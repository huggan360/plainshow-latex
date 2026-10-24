<?php
// Every file in a project you can open (document_id is checked by _bootstrap).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

$documentId = (int) ($_GET['document_id'] ?? 0);
$files = [];
$walk = function (array $nodes) use (&$walk, &$files): void {
    foreach ($nodes as $node) {
        $node['type'] === 'folder' ? $walk($node['children']) : $files[] = $node['path'];
    }
};
try {
    $walk(workspaceBuildTree($documentId));
} catch (RuntimeException) {
    $files = [];
}
echo json_encode(['status' => 'success', 'files' => $files]);
