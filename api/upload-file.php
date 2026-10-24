<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
ini_set('upload_max_filesize', '3000M');

$document_id = $_POST['document_id'] ?? null;
$target_dir = $_POST['target_dir'] ?? '';

if (!$document_id) {
    http_response_code(400);
    echo json_encode(['error' => ps_t('Document ID is required')]);
    exit;
}

try {
    $document_dir = workspaceRelativeToAbsolute((int)$document_id, $target_dir);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if (!is_dir($document_dir)) {
    http_response_code(400);
    echo json_encode(['error' => ps_t('Target folder does not exist')]);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => ps_t('No file uploaded')]);
    exit;
}

$file_name = basename($_FILES['file']['name']); // Sanitize the file name
try {
    workspaceAssertSafeName($file_name);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
$file_tmp_path = $_FILES['file']['tmp_name'];
$file_dest_path = "$document_dir/$file_name";

$workspace_relative_dir = trim((string)$target_dir, '/');

if (file_exists($file_dest_path)) {
    // File with the same name exists, generate a new unique name
    $file_info = pathinfo($file_name);
    $base_name = $file_info['filename'];
    $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
    $timestamp = time();
    $new_file_name = $base_name . '_' . $timestamp . $extension;
    $file_dest_path = "$document_dir/$new_file_name";
} else {
    $new_file_name = $file_name;
}

$db_file_name = $workspace_relative_dir === '' ? $new_file_name : ($workspace_relative_dir . '/' . $new_file_name);

// Move the file to the document's directory
if (move_uploaded_file($file_tmp_path, $file_dest_path)) {
    // Insert file info into the database
    $stmt = $conn->prepare("INSERT INTO document_files (document_id, file_name, file_path) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $document_id, $db_file_name, $file_dest_path);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'file_name' => $db_file_name, 'file_path' => $file_dest_path]);
    } else {
        // If database insertion fails, remove the uploaded file
        unlink($file_dest_path);
        http_response_code(500);
        echo json_encode(['error' => ps_t('Failed to insert file info into the database')]);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => ps_t('Failed to upload file')]);
}
?>
