<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$documentId = isset($input['document_id']) ? (int)$input['document_id'] : 0;
$parent     = trim((string)($input['parent'] ?? ''), '/');
$fileName   = trim((string)($input['file_name'] ?? ''));

if ($documentId <= 0 || $fileName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('document_id and file_name are required.')]);
    exit;
}

if (preg_match('/[\\\\\/]/', $fileName)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('File name cannot contain slashes.')]);
    exit;
}

try {
    workspaceAssertSafeName($fileName);
    $parentAbsolute = workspaceRelativeToAbsolute($documentId, $parent);
    if (!is_dir($parentAbsolute)) {
        throw new RuntimeException('Parent folder not found.');
    }

    $target = $parentAbsolute . '/' . $fileName;
    if (file_exists($target)) {
        throw new RuntimeException('A file with that name already exists.');
    }

    if (file_put_contents($target, '') === false) {
        throw new RuntimeException('Failed to create file.');
    }

    echo json_encode([
        'status' => 'success',
        'path'   => workspaceAbsoluteToRelative($documentId, $target)
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
