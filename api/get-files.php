<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';

$document_id = $_GET['document_id'] ?? null;

if (!$document_id) {
    http_response_code(400);
    echo json_encode(['error' => ps_t('Document ID is required')]);
    exit;
}

// Fetch files for the document, including id and file_path
$stmt = $conn->prepare("SELECT file_id, file_name, file_path FROM document_files WHERE document_id = ?");
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();

$files = [];
while ($row = $result->fetch_assoc()) {
    $files[] = [
        'id' => $row['file_id'],
        'file_name' => $row['file_name'],
        'file_path' => $row['file_path']
    ];
}

echo json_encode(['files' => $files]);
?>
