<?php
// Projects the signed-in person can open, for copying files between them.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
header('Content-Type: application/json');

$groupSql = latexGroupProjectSql($user_id);
$stmt = $conn->prepare('SELECT d.document_id AS id, d.title FROM documents d
    WHERE d.user_id = ? OR EXISTS (SELECT 1 FROM shared_documents s WHERE s.document_id = d.document_id AND s.user_id = ?)
    ' . $groupSql . ' ORDER BY d.title');
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$projects = array_map(fn ($row) => ['id' => (int) $row['id'], 'title' => $row['title']], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
echo json_encode(['status' => 'success', 'projects' => $projects]);
