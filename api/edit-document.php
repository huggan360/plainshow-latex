<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$document_id = $input['document_id'] ?? null;
$title = $input['title'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
$shared_users = latexRequestedUserIds((int) $user_id, $input['shared_users'] ?? []);
$shared_groups = array_key_exists('shared_groups', $input) ? latexRequestedGroupIds((int) $user_id, $input['shared_groups']) : null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Unauthorized')]);
    exit;
}

if (!$document_id || !$title) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID and title are required')]);
    exit;
}
if (!latexCan('latex.project.create') && !latexCan('latex.project.moderate')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => ps_t('You do not have permission to share projects.')]);
    exit;
}
if ($shared_users === null || (array_key_exists('shared_groups', $input) && $shared_groups === null)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => ps_t('Choose people and groups with LaTeX access.')]);
    exit;
}

$access_stmt = $conn->prepare('SELECT document_id FROM documents WHERE document_id = ? AND (user_id = ? OR ? = 1)');
if (!$access_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$moderate = latexCan('latex.project.moderate') ? 1 : 0;
$access_stmt->bind_param('iii', $document_id, $user_id, $moderate);
$access_stmt->execute();
$access_result = $access_stmt->get_result();
$has_access = $access_result->num_rows > 0;

if (!$has_access) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Access denied')]);
    exit;
}

// Update the document title
$update_stmt = $conn->prepare("UPDATE documents SET title = ? WHERE document_id = ?");
if (!$update_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$update_stmt->bind_param("si", $title, $document_id);
if (!$update_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Failed to update document title')]);
    exit;
}

// Update shared users
// First, delete existing shared users
$delete_stmt = $conn->prepare("DELETE FROM shared_documents WHERE document_id = ?");
if (!$delete_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$delete_stmt->bind_param("i", $document_id);
if (!$delete_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Failed to update shared users')]);
    exit;
}

// Then, insert new shared users
if (!empty($shared_users)) {
    $insert_stmt = $conn->prepare("INSERT INTO shared_documents (document_id, user_id) VALUES (?, ?)");
    if (!$insert_stmt) {
        echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
        exit;
    }

    foreach ($shared_users as $shared_user_id) {
        $insert_stmt->bind_param("ii", $document_id, $shared_user_id);
        if (!$insert_stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => ps_t('Failed to add shared user')]);
            exit;
        }
    }
    $insert_stmt->close();
}

if ($shared_groups !== null) {
    $delete_groups = $conn->prepare('DELETE FROM latex_group_shares WHERE document_id = ?');
    $delete_groups->bind_param('i', $document_id);
    $delete_groups->execute();
    if ($shared_groups) {
        $insert_group = $conn->prepare('INSERT INTO latex_group_shares (document_id, group_id) VALUES (?, ?)');
        foreach ($shared_groups as $group_id) {
            $insert_group->bind_param('ii', $document_id, $group_id);
            $insert_group->execute();
        }
    }
}

echo json_encode(['status' => 'success', 'message' => ps_t('Document updated successfully')]);
?>
