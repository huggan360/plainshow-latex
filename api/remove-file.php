<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$document_id = $input['document_id'] ?? null;
$file_id = $input['file_id'] ?? null;

if (!$document_id || !$file_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => ps_t('Invalid parameters.')]);
    exit;
}

// Fetch file details from the database
$stmt = $conn->prepare("SELECT file_path FROM document_files WHERE file_id = ? AND document_id = ?");
$stmt->bind_param("ii", $file_id, $document_id);
$stmt->execute();
$stmt->bind_result($file_path);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => ps_t('File not found.')]);
    exit;
}
$stmt->close();

// Delete the file from the filesystem
if (file_exists($file_path)) {
    if (!unlink($file_path)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => ps_t('Failed to delete the file from the server.')]);
        exit;
    }
}

// Remove the file record from the database
$stmt = $conn->prepare("DELETE FROM document_files WHERE file_id = ? AND document_id = ?");
$stmt->bind_param("ii", $file_id, $document_id);
if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => ps_t('Failed to remove file from the database.')]);
}
$stmt->close();
?>
