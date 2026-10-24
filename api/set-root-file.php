<?php
// The file tree's "Use as main file": the .tex file compiling starts from.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$documentId = (int) ($input['document_id'] ?? 0);
$path = trim((string) ($input['path'] ?? ''), '/');

try {
    if ($documentId <= 0 || !preg_match('/\.tex$/i', $path)) {
        throw new RuntimeException('Only a .tex file can be the main file.');
    }
    if (!is_file(workspaceRelativeToAbsolute($documentId, $path))) {
        throw new RuntimeException('File not found.');
    }
    $stmt = $conn->prepare('UPDATE documents SET root_file = ? WHERE document_id = ?');
    $stmt->bind_param('si', $path, $documentId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['status' => 'success', 'root_file' => $path]);
} catch (RuntimeException $error) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
}
