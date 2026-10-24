<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$documentId = isset($input['document_id']) ? (int)$input['document_id'] : 0;
$sourcePath  = trim((string)($input['source'] ?? ''), '/');
$destFolder  = trim((string)($input['destination'] ?? ''), '/');

if ($documentId <= 0 || $sourcePath === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('document_id and source are required.')]);
    exit;
}

try {
    $sourceAbs = workspaceRelativeToAbsolute($documentId, $sourcePath);
    if (!file_exists($sourceAbs)) {
        throw new RuntimeException('Source not found.');
    }

    $destAbs = workspaceRelativeToAbsolute($documentId, $destFolder);
    if (!is_dir($destAbs)) {
        throw new RuntimeException('Destination folder not found.');
    }

    $basename  = basename($sourceAbs);
    $targetAbs = rtrim($destAbs, '/') . '/' . $basename;

    if (realpath($sourceAbs) === realpath($targetAbs)) {
        echo json_encode(['status' => 'success', 'message' => ps_t('Already in destination.')]);
        exit;
    }

    if (file_exists($targetAbs)) {
        throw new RuntimeException('A file or folder with that name already exists in the destination.');
    }

    // Prevent moving a folder into itself
    if (is_dir($sourceAbs)) {
        $realSource = realpath($sourceAbs);
        $realDest   = realpath($destAbs);
        if (strpos($realDest . '/', $realSource . '/') === 0) {
            throw new RuntimeException('Cannot move a folder into itself.');
        }
    }

    if (!rename($sourceAbs, $targetAbs)) {
        throw new RuntimeException('Failed to move entry.');
    }

    $newPath = workspaceAbsoluteToRelative($documentId, $targetAbs);
    workspaceFollowRootFile($conn, $documentId, $sourcePath, $newPath);

    echo json_encode([
        'status'      => 'success',
        'old_path'    => $sourcePath,
        'new_path'    => $newPath,
        'destination' => $destFolder
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
