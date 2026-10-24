<?php
// Copies files from another project you can open into this one.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$documentId = (int) ($input['document_id'] ?? 0);
$sourceId = (int) ($input['source_id'] ?? 0);
$targetDir = trim((string) ($input['target_dir'] ?? ''), '/');
$files = array_slice(array_values(array_filter((array) ($input['files'] ?? []), 'is_string')), 0, 50);

if ($sourceId <= 0 || !latexCanAccess($sourceId, (int) $user_id)) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => ps_t('You cannot open that project.')]));
}
try {
    $target = workspaceRelativeToAbsolute($documentId, $targetDir);
    if (!is_dir($target)) {
        throw new RuntimeException('The target folder does not exist.');
    }
    // Check every file first, so a bad one leaves nothing half copied.
    $sources = [];
    foreach ($files as $path) {
        $from = workspaceRelativeToAbsolute($sourceId, $path);
        if (!is_file($from) || is_link($from)) {
            throw new RuntimeException('A file was not found: ' . $path);
        }
        workspaceAssertSafeName(basename($from));
        $sources[] = $from;
    }
    $copied = [];
    foreach ($sources as $from) {
        $name = basename($from);
        $to = $target . '/' . $name;
        if (file_exists($to)) {
            $info = pathinfo($name);
            $name = $info['filename'] . '_copy' . (isset($info['extension']) ? '.' . $info['extension'] : '');
            $to = $target . '/' . $name;
        }
        if (!copy($from, $to)) {
            throw new RuntimeException('Could not copy ' . $name);
        }
        $relative = workspaceAbsoluteToRelative($documentId, $to);
        $stmt = $conn->prepare('INSERT INTO document_files (document_id, file_name, file_path) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $documentId, $relative, $to);
        $stmt->execute();
        $copied[] = $relative;
    }
    echo json_encode(['status' => 'success', 'paths' => $copied]);
} catch (RuntimeException $error) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
}
