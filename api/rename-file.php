<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$document_id = $input['document_id'] ?? null;
$file_id = $input['file_id'] ?? null;
$new_name = $input['new_name'] ?? null;

if (!$document_id || !$file_id || !$new_name) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => ps_t('Invalid parameters.')]);
    exit;
}

// Sanitize the new file name
$new_name = basename($new_name); // Prevent directory traversal

// Check if a file with the new name already exists in the same document directory
$stmt = $conn->prepare("SELECT COUNT(*) FROM document_files WHERE document_id = ? AND file_name = ?");
$stmt->bind_param("is", $document_id, $new_name);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count > 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => ps_t('A file with the new name already exists.')]);
    exit;
}

// Fetch current file details
$stmt = $conn->prepare("SELECT file_path FROM document_files WHERE file_id = ? AND document_id = ?");
$stmt->bind_param("ii", $file_id, $document_id);
$stmt->execute();
$stmt->bind_result($current_path);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => ps_t('File not found.')]);
    exit;
}
$stmt->close();

// Determine new file path
$document_dir = dirname($current_path);
$new_path = $document_dir . '/' . $new_name;

// Rename the file in the filesystem
if (!rename($current_path, $new_path)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => ps_t('Failed to rename the file on the server.')]);
    exit;
}

// Update the database record
$stmt = $conn->prepare("UPDATE document_files SET file_name = ?, file_path = ? WHERE file_id = ? AND document_id = ?");
$stmt->bind_param("ssii", $new_name, $new_path, $file_id, $document_id);
if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    // If database update fails, revert the file rename
    rename($new_path, $current_path);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => ps_t('Failed to update the database.')]);
}
$stmt->close();
?>
