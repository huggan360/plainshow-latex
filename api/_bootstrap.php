<?php
/*
 * Every API endpoint starts here: the caller must be signed in, and any
 * document named in the request must be one they may open.
 */

require_once __DIR__ . '/../lib/plainshow.php';

$user_id = latexApiUser();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$readEndpoints = ['get-users.php', 'get-document-details.php', 'get-workspace-tree.php', 'file.php', 'list-projects.php', 'list-project-files.php', 'ws-ticket.php', 'fetch-updates.php', 'fetch-document.php', 'get-files.php', 'get-parts.php', 'get-all-part-contents.php', 'load-part.php', 'load-workspace-file.php'];
if ($method === 'GET' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $readEndpoints, true)) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => ps_t('Use POST.')]);
    exit;
}

// Every change needs the page's CSRF token, so other sites cannot act for you.
if ($method !== 'GET' && !latexCsrfValid()) {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => ps_t('The page has been open too long. Reload and try again.')]);
    exit;
}

(function () use ($user_id): void {
    $documentId = $_POST['document_id'] ?? $_GET['document_id'] ?? null;
    if ($documentId === null && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $documentId = is_array($body) ? ($body['document_id'] ?? null) : null;
    }
    if ($documentId === null || $documentId === '') {
        return;
    }
    $write = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if (!ctype_digit((string) $documentId) || !latexCanAccess((int) $documentId, $user_id, $write)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => ps_t('You do not have access to this document.')]);
        exit;
    }
})();
