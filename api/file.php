<?php
/*
 * Project files and compiled PDFs: /temp/document_{id}/{path} is rewritten
 * here, so only people who may open the project can read its files.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_workspace.php';

$documentId = (int) ($_GET['document_id'] ?? 0);
$path = (string) ($_GET['path'] ?? '');
try {
    $file = workspaceRelativeToAbsolute($documentId, $path);
} catch (RuntimeException) {
    $file = '';
}
if ($file === '' || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

$types = [
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'eps' => 'application/postscript', 'tex' => 'text/plain; charset=utf-8',
    'bib' => 'text/plain; charset=utf-8', 'cls' => 'text/plain; charset=utf-8', 'sty' => 'text/plain; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
// Nothing from a project may run with the viewer's session; PDFs need the browser's viewer.
if ($extension !== 'pdf') {
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
}
if (!isset($types[$extension])) {
    header('Content-Disposition: attachment; filename="' . addcslashes(basename($file), '"\\') . '"');
}
readfile($file);
