<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';

header('Content-Type: application/json');

$documentId = isset($_REQUEST['document_id']) ? (int)$_REQUEST['document_id'] : 0;
if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID is required.')]);
    exit;
}

try {
    ensureWorkspaceDocumentRoot($documentId);
    echo json_encode([
        'status' => 'success',
        'tree' => workspaceBuildTree($documentId)
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
