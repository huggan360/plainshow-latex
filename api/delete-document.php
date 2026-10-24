<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => ps_t('Use POST.')]);
    exit;
}
$document_id = $_POST['document_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Unauthorized')]);
    exit;
}

if (!$document_id) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID is required')]);
    exit;
}

// Verify ownership
$owner_check_stmt = $conn->prepare("SELECT document_id FROM documents WHERE document_id = ? AND (user_id = ? OR ? = 1)");
if (!$owner_check_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$moderate = latexCan('latex.project.moderate') ? 1 : 0;
$owner_check_stmt->bind_param("iii", $document_id, $user_id, $moderate);
$owner_check_stmt->execute();
$owner_check_result = $owner_check_stmt->get_result();
if ($owner_check_result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => ps_t('You do not have permission to delete this document')]);
    exit;
}

// Delete from shared_documents
$delete_shared_stmt = $conn->prepare("DELETE FROM shared_documents WHERE document_id = ?");
if (!$delete_shared_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$delete_shared_stmt->bind_param("i", $document_id);
if (!$delete_shared_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Failed to delete shared users')]);
    exit;
}
// Delete from documents
$delete_document_stmt = $conn->prepare("DELETE FROM documents WHERE document_id = ?");
if (!$delete_document_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$delete_document_stmt->bind_param("i", $document_id);
if (!$delete_document_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Failed to delete document')]);
    exit;
}



echo json_encode(['status' => 'success', 'message' => ps_t('Document deleted successfully')]);
?>
