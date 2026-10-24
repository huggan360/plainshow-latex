<?php
require_once __DIR__ . '/_bootstrap.php';

require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

// Ensure the user is logged in
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Unauthorized')]);
    exit;
}

// Get the document ID from the GET parameters
$document_id = $_GET['document_id'] ?? null;

if (!$document_id) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Document ID is required')]);
    exit;
}

// Fetch document details from the documents table
$document_stmt = $conn->prepare('SELECT document_id, title FROM documents WHERE document_id = ? AND (user_id = ? OR ? = 1)');
if (!$document_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$moderate = latexCan('latex.project.moderate') ? 1 : 0;
$document_stmt->bind_param('iii', $document_id, $user_id, $moderate);
$document_stmt->execute();
$document_result = $document_stmt->get_result();
$document = $document_result->fetch_assoc();

if (!$document) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Document not found')]);
    exit;
}

$users = array_values(array_map(static fn (array $person): array => [
    'user_id' => (int) $person['id'],
    'firstname' => (string) ($person['firstName'] ?? ''),
    'lastname' => (string) ($person['lastName'] ?? ''),
    'username' => (string) ($person['username'] ?? ''),
    'image_source' => $person['avatar'] ?? '',
], array_filter(latexPlatformPeople((int) $user_id), static fn (array $person): bool => (int) $person['id'] !== (int) $user_id)));

// Fetch shared users for this document
$shared_stmt = $conn->prepare("SELECT user_id FROM shared_documents WHERE document_id = ?");
if (!$shared_stmt) {
    echo json_encode(['status' => 'error', 'message' => ps_t('Prepare failed: ') . $conn->error]);
    exit;
}
$shared_stmt->bind_param("i", $document_id);
$shared_stmt->execute();
$shared_users_result = $shared_stmt->get_result();
$shared_users = [];
while ($shared_user = $shared_users_result->fetch_assoc()) {
    $shared_users[] = $shared_user['user_id'];
}
$group_stmt = $conn->prepare('SELECT group_id FROM latex_group_shares WHERE document_id = ?');
$group_stmt->bind_param('i', $document_id);
$group_stmt->execute();
$shared_groups = array_map('intval', array_column($group_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'group_id'));

// Respond with the document details and user lists
echo json_encode([
    'status' => 'success',
    'document' => $document,
    'users' => $users,
    'groups' => array_map(static fn ($group) => ['id' => (int) $group['id'], 'name' => (string) $group['name']], latexPlatformGroups((int) $user_id)),
    'shared_users' => $shared_users,
    'shared_groups' => $shared_groups
]);
?>
