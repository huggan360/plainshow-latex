<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$documentId = isset($input['document_id']) ? (int)$input['document_id'] : 0;
$path = trim((string)($input['path'] ?? ''), '/');
$newName = trim((string)($input['new_name'] ?? ''));

if ($documentId <= 0 || $path === '' || $newName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID, path and new name are required.')]);
    exit;
}

if (preg_match('/[\\\\\/]/', $newName)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Name cannot contain slashes.')]);
    exit;
}

try {
    workspaceAssertSafeName($newName);
    $absolutePath = workspaceRelativeToAbsolute($documentId, $path);
    if (!file_exists($absolutePath)) {
        throw new RuntimeException('Entry not found.');
    }

    $targetPath = dirname($absolutePath) . '/' . $newName;
    if (file_exists($targetPath)) {
        throw new RuntimeException('A file or folder with that name already exists.');
    }

    if (!rename($absolutePath, $targetPath)) {
        throw new RuntimeException('Failed to rename entry.');
    }

    $newRelativePath = workspaceAbsoluteToRelative($documentId, $targetPath);
    $oldRelativePath = trim($path, '/');
    workspaceFollowRootFile($conn, $documentId, $oldRelativePath, $newRelativePath);

    if (is_dir($targetPath)) {
        $oldRelativePrefix = $oldRelativePath . '/%';
        $newRelativePrefix = $newRelativePath . '/';
        $oldAbsolutePrefix = rtrim($absolutePath, '/') . '/';
        $newAbsolutePrefix = rtrim($targetPath, '/') . '/';

        $stmt = $conn->prepare("
            UPDATE document_files
            SET file_name = CONCAT(?, SUBSTRING(file_name, ?)),
                file_path = CONCAT(?, SUBSTRING(file_path, ?))
            WHERE document_id = ?
              AND (file_name = ? OR file_name LIKE ?)
        ");
        $oldRelativeLength = strlen($oldRelativePath) + 1;
        $oldAbsoluteLength = strlen($absolutePath) + 1;
        $stmt->bind_param(
            "sisiiss",
            $newRelativePrefix,
            $oldRelativeLength,
            $newAbsolutePrefix,
            $oldAbsoluteLength,
            $documentId,
            $oldRelativePath,
            $oldRelativePrefix
        );
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            UPDATE document_files
            SET file_name = ?, file_path = ?
            WHERE document_id = ? AND (file_name = ? OR file_path = ?)
        ");
        $stmt->bind_param("ssiss", $newRelativePath, $targetPath, $documentId, $oldRelativePath, $absolutePath);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode([
        'status' => 'success',
        'path' => $newRelativePath
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
