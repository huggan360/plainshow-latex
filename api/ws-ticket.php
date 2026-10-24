<?php
// A fresh two-minute ticket for the collaboration server, so the editor can reconnect.
require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$documentId = (int) ($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('Project ID is required.')]);
    exit;
}
if (!latexCanAccess($documentId, $user_id, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => ps_t('Edit access is required.')]);
    exit;
}
$identity = latexAuth()->user();
echo json_encode(['status' => 'success', 'ticket' => latexWsTicket($documentId, $user_id, $identity ? $identity->name() : '')]);
