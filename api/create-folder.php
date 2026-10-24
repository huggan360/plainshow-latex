<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$documentId = isset($input['document_id']) ? (int)$input['document_id'] : 0;
$parent = trim((string)($input['parent'] ?? ''), '/');
$folderName = trim((string)($input['folder_name'] ?? ''));

if ($documentId <= 0 || $folderName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID and folder name are required.')]);
    exit;
}

if (preg_match('/[\\\\\/]/', $folderName)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Folder name cannot contain slashes.')]);
    exit;
}

try {
    workspaceAssertSafeName($folderName);
    $parentAbsolute = workspaceRelativeToAbsolute($documentId, $parent);
    if (!is_dir($parentAbsolute)) {
        throw new RuntimeException('Parent folder not found.');
    }

    $target = $parentAbsolute . '/' . $folderName;
    if (file_exists($target)) {
        throw new RuntimeException('A file or folder with that name already exists.');
    }

    if (!mkdir($target, 0775, true)) {
        throw new RuntimeException('Failed to create folder.');
    }

    echo json_encode([
        'status' => 'success',
        'path' => workspaceAbsoluteToRelative($documentId, $target)
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
