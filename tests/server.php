<?php
/*
 * The collaboration server without a socket: edits by several clients to
 * files in a scratch project folder, group commits, failed writes, renames,
 * removals, files changed from outside and presence. php tests/server.php
 */
require __DIR__ . '/../websocket/server.php';

/** Like Ratchet's connection: properties go through __get and come back by value. */
class TestConnection implements Ratchet\ConnectionInterface {
    private $data;
    public $messages = [];
    public function __construct() { $this->data = new stdClass(); }
    public function __get($name) { return $this->data->$name; }
    public function __set($name, $value) { $this->data->$name = $value; }
    public function __isset($name) { return isset($this->data->$name); }
    public function send($data) { $this->messages[] = json_decode($data, true); }
    public function close() {}
    public function last(?string $type = null) {
        foreach (array_reverse($this->messages) as $message) if ($type === null || $message['type'] === $type) return $message;
        return null;
    }
}

class TestEditor extends CollabEditor {
    public $root;
    public $failWrites = false;
    public $writes = 0;
    public function __construct(string $root) { $this->root = $root; }
    protected function absolute(string $docId, string $path): string {
        workspaceAssertSafeName(basename($path));
        return $this->root . '/' . $path;
    }
    protected function writeFile(string $docId, string $path, string $content): string {
        if ($this->failWrites) throw new RuntimeException('Simulated write failure');
        $this->writes++;
        return parent::writeFile($docId, $path, $content);
    }
    protected function touchDocument(string $docId, string $userId) {}
    public function connect(string $user, string $name = 'Test'): TestConnection {
        $connection = new TestConnection();
        $this->join($connection, '1', $user, $name);
        return $connection;
    }
    public function state(string $path) { return $this->fileStates['1'][$path] ?? null; }
}

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
    echo "  ok  $message\n";
}

$root = sys_get_temp_dir() . '/latex-server-test-' . bin2hex(random_bytes(4));
mkdir("$root/chapters", 0700, true);
file_put_contents("$root/main.tex", 'abc');
file_put_contents("$root/chapters/one.tex", 'one');
file_put_contents("$root/logo.png", "\x89PNG\0\0binary");

try {
    $editor = new TestEditor($root);
    $a = $editor->connect('7', 'Ada');
    $b = $editor->connect('8', 'Bo');
    $send = function (TestConnection $from, array $message) use ($editor) {
        $editor->onMessage($from, json_encode($message + ['document_id' => 1, 'user_id' => (int) $from->userId]));
    };
    $edit = function (TestConnection $from, string $path, array $ops, int $rev, ?string $id = null, ?string $client = null) use ($send) {
        $send($from, ['type' => 'ot_update', 'path' => $path, 'base_rev' => $rev, 'client_id' => $client ?? 'c' . $from->userId, 'op_id' => $id, 'ops' => $ops]);
    };

    echo "Opening files\n";
    $send($a, ['type' => 'open_file', 'path' => 'main.tex', 'active' => true]);
    $state = $a->last('file_state');
    check($state && $state['content'] === 'abc' && $state['rev'] === 0, 'open_file answers with the text and revision');
    check(isset($a->openFiles['main.tex']), 'the connection remembers its open file');
    check($b->last('presence')['path'] === 'main.tex' && $b->last('presence')['user_id'] === '7', 'others see where you are');
    $send($a, ['type' => 'open_file', 'path' => '../outside.tex']);
    $send($a, ['type' => 'open_file', 'path' => 'chapters/../../x.tex']);
    check($editor->state('../outside.tex') === null && !isset($a->openFiles['../outside.tex']), 'paths out of the project are ignored');
    $send($a, ['type' => 'open_file', 'path' => 'logo.png']);
    check($a->last('file_error')['path'] === 'logo.png', 'binary files are refused');
    $send($a, ['type' => 'open_file', 'path' => 'missing.tex']);
    check($a->last('file_error')['path'] === 'missing.tex', 'missing files are refused');
    $send($a, ['type' => 'open_file', 'path' => 'evil.php']);
    check($a->last('file_error')['path'] === 'evil.php', 'files the web server could run are refused');

    echo "Editing together\n";
    $send($b, ['type' => 'open_file', 'path' => 'main.tex']);
    $edit($a, 'main.tex', [1, 'X', 2], 0);
    $edit($b, 'main.tex', [1, 'Y', 2], 0);
    check(file_get_contents("$root/main.tex") === 'aXYbc', 'simultaneous inserts follow server order');
    check($editor->state('main.tex')['rev'] === 2, 'both edits acknowledged');
    check($b->last('ot_applied')['rev'] === 2, 'everyone gets every edit');
    check(glob("$root/.*.tmp") === [], 'writes leave no temporary files');
    $edit($a, 'main.tex', [5, '!'], 99);
    check($a->last()['type'] === 'ot_error' && $editor->state('main.tex')['rev'] === 2, 'a future revision is rejected');
    $editor->onMessage($a, json_encode(['type' => 'ot_update', 'document_id' => 2, 'user_id' => 7, 'path' => 'main.tex', 'base_rev' => 2, 'ops' => [5, '!']]));
    $editor->onMessage($a, json_encode(['type' => 'ot_update', 'document_id' => 1, 'user_id' => 8, 'path' => 'main.tex', 'base_rev' => 2, 'ops' => [5, '!']]));
    check($editor->state('main.tex')['rev'] === 2, 'messages for another project or person are ignored');
    $edit($a, 'main.tex', [5, '😀'], 2);
    $edit($b, 'main.tex', [5, -2], 3);
    check(file_get_contents("$root/main.tex") === 'aXYbc', 'UTF-16 insert and delete');
    $edit($a, 'chapters/one.tex', [3, '!'], 0);
    check($a->last()['type'] === 'ot_error' && file_get_contents("$root/chapters/one.tex") === 'one', 'edits to a file nobody opened are refused');

    echo "Group commit and failures\n";
    $loop = new class { public $ticks = []; public function futureTick(callable $c) { $this->ticks[] = $c; } public function run() { while ($t = array_shift($this->ticks)) $t(); } };
    $editor->setLoop($loop);
    $writes = $editor->writes;
    $seen = count($b->messages);
    $edit($a, 'main.tex', [5, '1'], 4, 'one', 'ca');
    $edit($b, 'main.tex', [5, '2'], 4, 'two', 'cb');
    $edit($a, 'main.tex', [5, '3'], 4, 'three', 'ca');
    $edit($a, 'main.tex', [5, '1'], 4, 'one', 'ca');
    check($editor->writes === $writes && count($b->messages) === $seen, 'nothing is written or acknowledged before the tick');
    $loop->run();
    check($editor->writes === $writes + 1, 'one write per file per tick');
    check(file_get_contents("$root/main.tex") === 'aXYbc123', 'batched edits saved in server order');
    check(array_column(array_slice($b->messages, $seen), 'rev') === [5, 6, 7], 'acknowledgements in revision order');
    $edit($a, 'main.tex', [5, '1'], 4, 'one', 'ca');
    check($a->last()['rev'] === 5, 'a retry of a saved edit is acknowledged again');
    $editor->failWrites = true;
    $edit($a, 'main.tex', [8, '!'], 7, 'lost', 'ca');
    $loop->run();
    check($editor->state('main.tex') === null && $a->last()['type'] === 'ot_error', 'a failed write drops the state and tells the sender');
    check(file_get_contents("$root/main.tex") === 'aXYbc123', 'a failed write leaves the file as it was');
    $editor->failWrites = false;
    $editor->setLoop(null);
    $send($a, ['type' => 'open_file', 'path' => 'main.tex']);
    check($a->last('file_state')['content'] === 'aXYbc123', 'reopening starts again from disk');

    echo "Files changing\n";
    $send($a, ['type' => 'open_file', 'path' => 'chapters/one.tex']);
    $send($b, ['type' => 'open_file', 'path' => 'chapters/one.tex']);
    $edit($a, 'chapters/one.tex', [3, ' two'], 0);
    $epoch = $editor->state('chapters/one.tex')['epoch'];
    rename("$root/chapters", "$root/parts");
    $send($a, ['type' => 'workspace_event', 'action' => 'renamed', 'old_path' => 'chapters', 'path' => 'parts', 'entry_type' => 'folder']);
    $moved = $editor->state('parts/one.tex');
    check($moved && $moved['epoch'] === $epoch && $moved['rev'] === 1 && $editor->state('chapters/one.tex') === null, 'a folder rename keeps live state, revisions and all');
    check(isset($b->openFiles['parts/one.tex']) && !isset($b->openFiles['chapters/one.tex']), "the other person's open file follows");
    check($b->last('workspace_event')['old_path'] === 'chapters', 'others hear of the rename');
    $edit($b, 'parts/one.tex', [7, '!'], 1);
    check(file_get_contents("$root/parts/one.tex") === 'one two!', 'editing continues under the new name');
    $send($b, ['type' => 'workspace_event', 'action' => 'renamed', 'old_path' => 'main.tex', 'path' => 'stolen.tex']);
    check($editor->state('main.tex') !== null && $editor->state('stolen.tex') === null, 'a rename the disk does not show is ignored');

    file_put_contents("$root/parts/one.tex", 'replaced outside');
    $send($a, ['type' => 'workspace_event', 'action' => 'updated', 'path' => 'parts/one.tex']);
    check($b->last('file_reset')['path'] === 'parts/one.tex' && $editor->state('parts/one.tex') === null, 'a file replaced from outside is reloaded by everyone');
    $send($b, ['type' => 'open_file', 'path' => 'parts/one.tex']);
    check($b->last('file_state')['content'] === 'replaced outside', 'and shows the new text');

    unlink("$root/parts/one.tex");
    rmdir("$root/parts");
    $send($a, ['type' => 'workspace_event', 'action' => 'removed', 'path' => 'parts']);
    check($editor->state('parts/one.tex') === null, 'removing a folder drops its files');

    echo "Leaving\n";
    $send($b, ['type' => 'close_file', 'path' => 'main.tex']);
    check($editor->state('main.tex') !== null, 'a file stays while someone else has it open');
    $send($a, ['type' => 'close_file', 'path' => 'main.tex']);
    check($editor->state('main.tex') === null, 'and is let go when the last person closes it');
    $c = $editor->connect('7', 'Ada');
    check(count($c->last('user_list')['users']) === 3, 'the same person in a second tab is a second client');
    $editor->onClose($c);
    check($a->last('user_leave')['client'] === $c->clientKey, 'leaving names the client, not only the person');
    echo "Collaboration server passed.\n";
} finally {
    exec('rm -rf ' . escapeshellarg($root));
}
