<?php
/*
 * Live editing together. A project is a folder of files; every text file
 * someone opens gets one shared state here, keyed by project and path:
 * its text, a revision number and a log of operational transforms.
 *
 * From the editor:
 *   open_file        {path, known_rev?, epoch?, force_snapshot?}  -> file_state or file_resume
 *   close_file       {path}
 *   active_file      {path|null}         which tab you are looking at, for presence
 *   ot_update        {path, base_rev, client_id, op_id, epoch, ops}  -> ot_applied to everyone
 *   cursor_position  {path, anchor, head} / cursor_clear {path}
 *   workspace_event  {action, path, old_path?, entry_type?}  after a file API call
 *
 * Edits that arrive in the same loop tick share one write. A write goes to a
 * temporary file that is renamed over the real one, so a file on disk is
 * always either the old or the new text, never half of each.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TextOperation.php';
require_once dirname(__DIR__) . '/api/_workspace.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use React\Socket\Server as ReactServer;
use React\EventLoop\Factory as LoopFactory;

class CollabEditor implements MessageComponentInterface {
    const OPLOG_LIMIT = 10000;
    const MAX_FILE_BYTES = 4 * 1024 * 1024;
    const PROTOCOL = 4;

    protected $documentClients = []; // docId => SplObjectStorage
    protected $fileStates = [];      // docId => [path => state]
    protected $pendingCommits = [];  // "docId\0path" => payloads and senders awaiting one write
    protected $commitScheduled = false;
    protected $touched = [];         // docId => last time documents.updated_at was bumped
    protected $loop = null;

    public function setLoop($loop) {
        $this->loop = $loop;
    }

    public function onOpen(ConnectionInterface $conn) {
        parse_str($conn->httpRequest->getUri()->getQuery(), $params);
        // Who is connecting comes only from a ticket the editor page signed
        // after checking access to this project, never from the URL.
        $ticket = $this->verifyTicket((string) ($params['ticket'] ?? ''), (string) ($params['document_id'] ?? ''));
        if ($ticket === null) {
            $conn->close();
            return;
        }
        $this->join($conn, (string) $ticket['d'], (string) $ticket['u'], (string) ($ticket['n'] ?? 'Unknown'));
    }

    /** A verified connection joins its project: it gets the people there, they hear of it. */
    protected function join(ConnectionInterface $conn, string $docId, string $userId, string $userName) {
        $conn->documentId = $docId;
        $conn->userId = $userId;
        $conn->userName = $userName;
        // An object, not an array: Ratchet's connection hands properties out by value.
        $conn->openFiles = new \ArrayObject();
        $conn->activePath = null;
        // One person can have the project open in several tabs; each is a client.
        $conn->clientKey = (string) ($conn->resourceId ?? spl_object_id($conn));

        $this->documentClients[$docId] ??= new \SplObjectStorage;
        $this->documentClients[$docId][$conn] = true;

        $users = [];
        foreach ($this->documentClients[$docId] as $client) {
            $users[] = ['client' => $client->clientKey, 'user_id' => $client->userId, 'user_name' => $client->userName, 'path' => $client->activePath];
        }
        $this->sendToOne($conn, ['type' => 'user_list', 'document_id' => $docId, 'you' => $conn->clientKey, 'users' => $users, 'protocol_version' => self::PROTOCOL]);
        $this->broadcastToDocument($docId, ['type' => 'user_join', 'client' => $conn->clientKey, 'user_id' => $conn->userId, 'user_name' => $conn->userName], $conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($from->documentId)) return;
        if ((string) ($data['document_id'] ?? '') !== $from->documentId || (string) ($data['user_id'] ?? '') !== $from->userId) return;

        $type = (string) ($data['type'] ?? '');
        if ($type === 'ping') {
            $this->sendToOne($from, ['type' => 'pong']);
            return;
        }
        $path = $this->cleanPath($data['path'] ?? null);

        try {
            switch ($type) {
                case 'open_file': if ($path !== null) $this->handleOpenFile($from, $path, $data); break;
                case 'close_file': if ($path !== null) $this->handleCloseFile($from, $path); break;
                case 'active_file': $this->handleActiveFile($from, $path); break;
                case 'ot_update': if ($path !== null) $this->handleOtUpdate($from, $path, $data); break;
                case 'cursor_position': if ($path !== null) $this->handleCursor($from, $path, $data); break;
                case 'cursor_clear': if ($path !== null) $this->broadcastToDocument($from->documentId, ['type' => 'cursor_clear', 'client' => $from->clientKey, 'path' => $path], $from); break;
                case 'workspace_event': $this->handleWorkspaceEvent($from, $path, $data); break;
            }
        } catch (\Throwable $error) {
            $this->sendToOne($from, ['type' => 'server_error', 'message' => 'Unable to synchronize. Retrying is safe.']);
            error_log('Collaboration request failed: ' . get_class($error) . ': ' . $error->getMessage());
        }
    }

    /** A project-relative path, or null when it is not one. */
    protected function cleanPath($path): ?string {
        if (!is_string($path)) return null;
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || strlen($path) > 1024 || str_contains($path, "\0")) return null;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return null;
        }

        return $path;
    }

    protected function handleOpenFile(ConnectionInterface $conn, string $path, array $data) {
        $docId = $conn->documentId;
        if ($this->pendingCommits) $this->flushCommits();
        try {
            $state = &$this->ensureFileState($docId, $path);
        } catch (\RuntimeException $error) {
            $this->sendToOne($conn, ['type' => 'file_error', 'path' => $path, 'message' => $error->getMessage()]);
            return;
        }
        $conn->openFiles[$path] = true;

        $knownRev = $data['known_rev'] ?? null;
        $canResume = empty($data['force_snapshot']) && ($data['epoch'] ?? null) === $state['epoch']
            && is_int($knownRev) && $knownRev >= 0 && $knownRev <= $state['rev']
            && ($knownRev === $state['rev'] || isset($state['events'][$knownRev]));
        if ($canResume) {
            $events = [];
            for ($revision = $knownRev; $revision < $state['rev']; $revision++) $events[] = $state['events'][$revision];
            $this->sendToOne($conn, ['type' => 'file_resume', 'path' => $path, 'rev' => $state['rev'], 'epoch' => $state['epoch'], 'events' => $events]);
        } else {
            $this->sendToOne($conn, ['type' => 'file_state', 'protocol_version' => self::PROTOCOL, 'path' => $path,
                'epoch' => $state['epoch'], 'rev' => $state['rev'], 'content' => $state['content']]);
        }
        unset($state);
        if (!empty($data['active'])) $this->handleActiveFile($conn, $path);
    }

    protected function handleCloseFile(ConnectionInterface $conn, string $path) {
        unset($conn->openFiles[$path]);
        if ($conn->activePath === $path) $this->handleActiveFile($conn, null);
        $this->releaseIfUnused($conn->documentId, $path);
    }

    protected function handleActiveFile(ConnectionInterface $conn, ?string $path) {
        if ($conn->activePath === $path) return;
        if ($conn->activePath !== null) {
            $this->broadcastToDocument($conn->documentId, ['type' => 'cursor_clear', 'client' => $conn->clientKey, 'path' => $conn->activePath], $conn);
        }
        $conn->activePath = $path;
        $this->broadcastToDocument($conn->documentId, ['type' => 'presence', 'client' => $conn->clientKey, 'user_id' => $conn->userId, 'user_name' => $conn->userName, 'path' => $path], $conn);
    }

    protected function handleCursor(ConnectionInterface $conn, string $path, array $data) {
        $this->broadcastToDocument($conn->documentId, [
            'type' => 'cursor_position', 'client' => $conn->clientKey, 'user_id' => $conn->userId, 'user_name' => $conn->userName, 'path' => $path,
            'anchor' => max(0, (int) ($data['anchor'] ?? 0)), 'head' => max(0, (int) ($data['head'] ?? 0)),
        ], $conn);
    }

    protected function handleOtUpdate(ConnectionInterface $conn, string $path, array $data) {
        $docId = $conn->documentId;
        $ops = $data['ops'] ?? null;
        $baseRev = isset($data['base_rev']) ? (int) $data['base_rev'] : 0;
        $clientId = is_string($data['client_id'] ?? null) ? $data['client_id'] : $conn->userId;
        if (!is_array($ops)) return;
        if (!isset($this->fileStates[$docId][$path])) {
            // Only files someone has opened are edited, so this one was closed, moved or removed.
            $this->sendToOne($conn, ['type' => 'ot_error', 'path' => $path, 'message' => 'Reopen the file to keep editing.']);
            return;
        }
        $state = &$this->fileStates[$docId][$path];

        try {
            $operationId = $data['op_id'] ?? null;
            if ($operationId !== null && (!is_string($operationId) || strlen($operationId) > 200)) throw new \RuntimeException('Invalid operation ID.');
            if (isset($data['epoch']) && $data['epoch'] !== $state['epoch']) throw new \RuntimeException('Server epoch changed.');
            $receiptKey = $clientId . ':' . ($operationId ?? '');
            if ($operationId && isset($state['receipts'][$receiptKey])) {
                // A retry of an edit still awaiting its write is acknowledged by that write.
                if ($state['receipts'][$receiptKey]['rev'] <= $state['persistedRev']) $this->sendToOne($conn, $state['receipts'][$receiptKey]);
                return;
            }
            if ($baseRev < 0 || $baseRev > $state['rev']) throw new \RuntimeException('Invalid revision.');
            $incoming = \App\OT\TextOperation::fromJSON($ops);
            // Rebase against everything applied since the client's revision.
            for ($i = $baseRev; $i < $state['rev']; $i++) {
                if (!isset($state['log'][$i])) throw new \RuntimeException('Revision history expired.');
                $incoming = \App\OT\TextOperation::transform(\App\OT\TextOperation::fromJSON($state['log'][$i]), $incoming)[1];
            }

            $content = $incoming->apply($state['content']);
            if (strlen($content) > self::MAX_FILE_BYTES) throw new \RuntimeException('File too large.');
            $state['content'] = $content;
            $transformed = $incoming->toJSON();
            $state['log'][$state['rev']] = $transformed;
            $state['rev']++;

            $payload = ['type' => 'ot_applied', 'path' => $path, 'rev' => $state['rev'], 'ops' => $transformed, 'client_id' => $clientId, 'op_id' => $operationId];
            $state['events'][$state['rev'] - 1] = $payload;
            if ($operationId) $state['receipts'][$receiptKey] = $payload;
            if (count($state['log']) > self::OPLOG_LIMIT) {
                $oldest = array_key_first($state['log']);
                $expired = $state['events'][$oldest];
                unset($state['receipts'][$expired['client_id'] . ':' . ($expired['op_id'] ?? '')]);
                unset($state['log'][$oldest], $state['events'][$oldest]);
            }
            unset($state);
            // Acknowledge and broadcast only after the write succeeds.
            $this->queueCommit($conn, $docId, $path, $payload);
        } catch (\Throwable $error) {
            unset($state);
            $this->sendToOne($conn, ['type' => 'ot_error', 'path' => $path, 'message' => 'Unable to apply edit; resynchronization required.']);
        }
    }

    protected function queueCommit(ConnectionInterface $conn, string $docId, string $path, array $payload) {
        $key = $docId . "\0" . $path;
        $this->pendingCommits[$key]['docId'] = $docId;
        $this->pendingCommits[$key]['path'] = $path;
        $this->pendingCommits[$key]['payloads'][] = $payload;
        $this->pendingCommits[$key]['senders'][] = $conn;
        if ($this->loop === null) {
            $this->flushCommits();
        } elseif (!$this->commitScheduled) {
            $this->commitScheduled = true;
            $this->loop->futureTick(function () { $this->flushCommits(); });
        }
    }

    // One write per changed file, then release the acknowledgements it covers.
    protected function flushCommits() {
        $this->commitScheduled = false;
        $pending = $this->pendingCommits;
        $this->pendingCommits = [];
        foreach ($pending as $batch) {
            $docId = $batch['docId'];
            $path = $batch['path'];
            try {
                if (!isset($this->fileStates[$docId][$path])) throw new \RuntimeException('File was closed.');
                $state = &$this->fileStates[$docId][$path];
                $state['hash'] = $this->writeFile($docId, $path, $state['content']);
                $state['persistedRev'] = $state['rev'];
                unset($state);
                $this->touchDocument($docId, end($batch['senders'])->userId);
            } catch (\Throwable $error) {
                unset($state);
                // Nobody has seen these edits. Start the file again from disk;
                // the senders keep their text as a recovery copy.
                unset($this->fileStates[$docId][$path]);
                $notified = new \SplObjectStorage;
                foreach ($batch['senders'] as $sender) {
                    if (isset($notified[$sender])) continue;
                    $notified[$sender] = true;
                    $this->sendToOne($sender, ['type' => 'ot_error', 'path' => $path, 'message' => 'Unable to save edit; resynchronization required.']);
                }
                error_log("Collaboration write failed for project $docId: " . $error->getMessage());
                continue;
            }
            foreach ($batch['payloads'] as $payload) $this->broadcastToDocument($docId, $payload);
        }
    }

    protected function &ensureFileState(string $docId, string $path) {
        if (isset($this->fileStates[$docId][$path])) {
            $state = &$this->fileStates[$docId][$path];
            // Someone replaced the file outside the editor (an upload, a copy).
            // With nothing waiting to be written, the disk wins.
            if ($state['persistedRev'] === $state['rev'] && $this->diskHash($docId, $path) !== $state['hash']) {
                unset($state, $this->fileStates[$docId][$path]);
            } else {
                return $state;
            }
        }
        $content = $this->readFile($docId, $path);
        $this->fileStates[$docId][$path] = [
            'content' => $content, 'hash' => hash('sha256', $content),
            'rev' => 0, 'log' => [], 'events' => [], 'receipts' => [], 'persistedRev' => 0,
            'epoch' => bin2hex(random_bytes(16)),
        ];

        return $this->fileStates[$docId][$path];
    }

    /** Forget a file's state once nobody has it open and it is written. */
    protected function releaseIfUnused(string $docId, string $path) {
        if (!isset($this->fileStates[$docId][$path])) return;
        $state = $this->fileStates[$docId][$path];
        if ($state['persistedRev'] !== $state['rev']) return;
        foreach ($this->documentClients[$docId] ?? [] as $client) {
            if (isset($client->openFiles[$path])) return;
        }
        unset($this->fileStates[$docId][$path]);
    }

    protected function handleWorkspaceEvent(ConnectionInterface $conn, ?string $path, array $data) {
        $docId = $conn->documentId;
        $action = (string) ($data['action'] ?? 'updated');
        $oldPath = $this->cleanPath($data['old_path'] ?? null);
        if ($this->pendingCommits) $this->flushCommits();

        // The server checks the disk itself; the message only says where to look.
        if (in_array($action, ['renamed', 'moved'], true) && $oldPath !== null && $path !== null
            && !$this->exists($docId, $oldPath) && $this->exists($docId, $path)) {
            $this->moveStates($docId, $oldPath, $path);
        } elseif ($action === 'removed' && $path !== null && !$this->exists($docId, $path)) {
            foreach (array_keys($this->fileStates[$docId] ?? []) as $open) {
                if ($open === $path || str_starts_with($open, $path . '/')) unset($this->fileStates[$docId][$open]);
            }
        } elseif (in_array($action, ['updated', 'created'], true) && $path !== null && isset($this->fileStates[$docId][$path])) {
            $state = $this->fileStates[$docId][$path];
            if ($state['persistedRev'] === $state['rev'] && $this->diskHash($docId, $path) !== $state['hash']) {
                // Replaced from outside: everyone with it open loads the new text.
                unset($this->fileStates[$docId][$path]);
                $this->broadcastToDocument($docId, ['type' => 'file_reset', 'path' => $path]);
            }
        }

        $this->broadcastToDocument($docId, [
            'type' => 'workspace_event', 'user_id' => $conn->userId, 'user_name' => $conn->userName,
            'action' => $action, 'path' => $path, 'old_path' => $oldPath,
            'entry_type' => in_array($data['entry_type'] ?? null, ['file', 'folder'], true) ? $data['entry_type'] : null,
        ], $conn);
    }

    /** Keep a renamed file's (or folder's) live state, revisions and all, under its new path. */
    protected function moveStates(string $docId, string $oldPath, string $newPath) {
        foreach (array_keys($this->fileStates[$docId] ?? []) as $open) {
            if ($open !== $oldPath && !str_starts_with($open, $oldPath . '/')) continue;
            $moved = $newPath . substr($open, strlen($oldPath));
            $state = $this->fileStates[$docId][$open];
            // The history replayed to a reconnecting editor names the file as it is now.
            foreach ($state['events'] as $rev => $event) $state['events'][$rev]['path'] = $moved;
            foreach ($state['receipts'] as $key => $receipt) $state['receipts'][$key]['path'] = $moved;
            $this->fileStates[$docId][$moved] = $state;
            unset($this->fileStates[$docId][$open]);
            foreach ($this->documentClients[$docId] ?? [] as $client) {
                if (isset($client->openFiles[$open])) {
                    unset($client->openFiles[$open]);
                    $client->openFiles[$moved] = true;
                }
                if ($client->activePath === $open) $client->activePath = $moved;
            }
        }
    }

    protected function absolute(string $docId, string $path): string {
        $absolute = workspaceRelativeToAbsolute((int) $docId, $path);
        workspaceAssertSafeName(basename($absolute));

        return $absolute;
    }

    protected function exists(string $docId, string $path): bool {
        try {
            return file_exists($this->absolute($docId, $path));
        } catch (\RuntimeException) {
            return false;
        }
    }

    protected function diskHash(string $docId, string $path): ?string {
        try {
            $absolute = $this->absolute($docId, $path);
        } catch (\RuntimeException) {
            return null;
        }
        clearstatcache(true, $absolute);

        return is_file($absolute) ? hash_file('sha256', $absolute) : null;
    }

    protected function readFile(string $docId, string $path): string {
        $absolute = $this->absolute($docId, $path);
        clearstatcache(true, $absolute);
        if (!is_file($absolute)) throw new \RuntimeException('This file no longer exists.');
        if (filesize($absolute) > self::MAX_FILE_BYTES) throw new \RuntimeException('This file is too large to edit here.');
        $content = file_get_contents($absolute);
        if ($content === false) throw new \RuntimeException('This file could not be read.');
        if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) throw new \RuntimeException('This is not a text file.');

        return $content;
    }

    /** Write next to the file and rename over it. Returns the new SHA-256. */
    protected function writeFile(string $docId, string $path, string $content): string {
        $absolute = $this->absolute($docId, $path);
        if (!is_file($absolute)) throw new \RuntimeException('File was removed.');
        $temporary = dirname($absolute) . '/.' . basename($absolute) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $content) !== strlen($content)) {
            @unlink($temporary);
            throw new \RuntimeException('Write failed.');
        }
        if (!rename($temporary, $absolute)) {
            @unlink($temporary);
            throw new \RuntimeException('Write failed.');
        }
        clearstatcache(true, $absolute);
        $hash = hash_file('sha256', $absolute);
        if ($hash !== hash('sha256', $content)) throw new \RuntimeException('Write verification failed.');

        return $hash;
    }

    /** "Last edited, by whom" for the project list, at most once a minute per person. */
    protected function touchDocument(string $docId, string $userId) {
        [$at, $by] = $this->touched[$docId] ?? [0, null];
        if ($by === $userId && $at > time() - 60) return;
        $this->touched[$docId] = [time(), $userId];
        try {
            $statement = $this->database()->prepare('UPDATE documents SET updated_at = NOW(), last_editor_id = ? WHERE document_id = ?');
            $id = (int) $docId;
            $editor = (int) $userId;
            $statement->bind_param('ii', $editor, $id);
            $statement->execute();
            $statement->close();
        } catch (\Throwable) {
            $this->database = null;
        }
    }

    protected $database = null;

    protected function database(): \mysqli {
        if ($this->database === null) {
            $config = require dirname(__DIR__) . '/config/database.php';
            $this->database = new \mysqli($config['host'], $config['user'], $config['password'], $config['database']);
            $this->database->set_charset('utf8mb4');
        }

        return $this->database;
    }

    protected $ticketSecret = null;

    /** @return array{u: int, d: int, n: string, e: int}|null */
    protected function verifyTicket(string $ticket, string $documentId): ?array {
        if ($this->ticketSecret === null) {
            $config = @include dirname(__DIR__) . '/config/plainshow.php';
            $this->ticketSecret = is_array($config) ? (string) ($config['ws_ticket_secret'] ?? '') : '';
        }
        [$payload, $signature] = array_pad(explode('.', $ticket, 2), 2, '');
        if ($this->ticketSecret === '' || $payload === '' || !hash_equals(hash_hmac('sha256', $payload, $this->ticketSecret), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || !isset($data['u'], $data['d'], $data['e']) || (int) $data['e'] < time()) {
            return null;
        }

        return (string) $data['d'] === $documentId ? $data : null;
    }

    public function onClose(ConnectionInterface $conn) {
        $docId = $conn->documentId ?? null;
        if ($docId === null || !isset($this->documentClients[$docId][$conn])) return;
        unset($this->documentClients[$docId][$conn]);
        if ($this->pendingCommits) $this->flushCommits();
        if ($conn->activePath !== null) {
            $this->broadcastToDocument($docId, ['type' => 'cursor_clear', 'client' => $conn->clientKey, 'path' => $conn->activePath]);
        }
        $this->broadcastToDocument($docId, ['type' => 'user_leave', 'client' => $conn->clientKey, 'user_id' => $conn->userId, 'user_name' => $conn->userName]);
        foreach (array_keys($conn->openFiles->getArrayCopy()) as $path) $this->releaseIfUnused($docId, $path);
        if ($this->documentClients[$docId]->count() === 0) {
            unset($this->documentClients[$docId], $this->fileStates[$docId], $this->touched[$docId]);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    protected function broadcastToDocument($documentId, array $message, ?ConnectionInterface $exclude = null) {
        $encoded = json_encode($message);
        foreach ($this->documentClients[$documentId] ?? [] as $client) {
            if ($client !== $exclude) $client->send($encoded);
        }
    }

    protected function sendToOne(ConnectionInterface $conn, array $message) {
        $conn->send(json_encode($message));
    }
}

// Tests load the handler without opening a socket.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$loop = LoopFactory::create();
$port = (int) (getenv('PLAINSHOW_WS_PORT') ?: 8080);
// Only Apache talks to this server, so it listens on loopback.
$socket = new ReactServer((getenv('PLAINSHOW_WS_HOST') ?: '127.0.0.1') . ':' . $port, $loop);
$editor = new CollabEditor();
$editor->setLoop($loop);
$server = new IoServer(new HttpServer(new WsServer($editor)), $socket, $loop);
echo "Collaboration server on port $port\n";
$loop->run();
