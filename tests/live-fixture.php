<?php
// CLI-only fixture for tests/live-collaboration.cjs: a disposable project with files.
//   php tests/live-fixture.php create | tickets | delete   (JSON on stdin)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/api/_workspace.php';
$input = json_decode(stream_get_contents(STDIN), true) ?? [];
$action = $argv[1] ?? '';
$prefix = 'PlainShow reliability fixture ';

if ($action === 'create') {
    $owner = (int) $conn->query('SELECT MIN(user_id) FROM documents')->fetch_row()[0];
    if (!$owner) throw new RuntimeException('No fixture owner available.');
    $title = $prefix . bin2hex(random_bytes(12));
    $stmt = $conn->prepare("INSERT INTO documents (user_id, title, root_file) VALUES (?, ?, 'main.tex')");
    $stmt->bind_param('is', $owner, $title);
    $stmt->execute();
    $id = $conn->insert_id;
    $dir = workspaceDocumentRoot($id);
    mkdir("$dir/chapters", 0770, true);
    file_put_contents("$dir/main.tex", "start\n");
    file_put_contents("$dir/chapters/a.tex", "chapter\n");
    echo json_encode(['documentId' => $id, 'userId' => $owner, 'title' => $title, 'dir' => $dir]);
    exit;
}

$id = (int) ($input['documentId'] ?? 0);
$stmt = $conn->prepare('SELECT title FROM documents WHERE document_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$title = $stmt->get_result()->fetch_row()[0] ?? '';
if (!$id || !str_starts_with($title, $prefix) || $title !== ($input['title'] ?? null)) throw new RuntimeException('Fixture identity does not match.');

if ($action === 'tickets') {
    $config = require __DIR__ . '/../config/plainshow.php';
    $tickets = [];
    foreach ((array) ($input['users'] ?? []) as $user) {
        $payload = rtrim(strtr(base64_encode(json_encode(['u' => (int) $user, 'd' => $id, 'n' => 'Load ' . $user, 'e' => time() + 900])), '+/', '-_'), '=');
        $tickets[(string) $user] = $payload . '.' . hash_hmac('sha256', $payload, $config['ws_ticket_secret']);
    }
    echo json_encode($tickets);
} elseif ($action === 'delete') {
    exec('rm -rf ' . escapeshellarg(workspaceDocumentRoot($id)));
    $stmt = $conn->prepare('DELETE FROM documents WHERE document_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['deleted' => $id]);
} else {
    throw new RuntimeException('Unknown fixture action.');
}
