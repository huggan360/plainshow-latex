<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$documentId = isset($input['document_id']) ? (int)$input['document_id'] : 0;
$path = trim((string)($input['path'] ?? ''), '/');

if ($documentId <= 0 || $path === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID and path are required.')]);
    exit;
}

function deleteWorkspaceTree(string $absolutePath): void
{
    if (is_dir($absolutePath) && !is_link($absolutePath)) {
        $entries = scandir($absolutePath);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                deleteWorkspaceTree($absolutePath . '/' . $entry);
            }
        }
        if (!rmdir($absolutePath)) {
            throw new RuntimeException('Failed to remove folder.');
        }
        return;
    }

    if (!unlink($absolutePath)) {
        throw new RuntimeException('Failed to remove file.');
    }
}

try {
    $absolutePath = workspaceRelativeToAbsolute($documentId, $path);
    if (!file_exists($absolutePath)) {
        throw new RuntimeException('Entry not found.');
    }

    $relativePath = trim($path, '/');
    deleteWorkspaceTree($absolutePath);

    $relativePrefix = $relativePath . '/%';
    $absolutePrefix = rtrim($absolutePath, '/') . '/%';
    $stmt = $conn->prepare("
        DELETE FROM document_files
        WHERE document_id = ?
          AND (
            file_name = ? OR file_name LIKE ? OR
            file_path = ? OR file_path LIKE ?
          )
    ");
    $stmt->bind_param("issss", $documentId, $relativePath, $relativePrefix, $absolutePath, $absolutePrefix);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
