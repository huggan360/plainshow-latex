<?php
/*
 * LaTeX API, version 1: plainshow.se/api/latex/v1/...
 * Read access to your projects for scripts using a personal PlainShow token.
 * Calls arrive through core's gateway, signed for the person (no session);
 * /health and /openapi.json are public.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/plainshow.php';
require_once __DIR__ . '/_workspace.php';

use PlainShow\Kit\Identity;
use PlainShow\Kit\KitException;
use PlainShow\Kit\OpenApi;
use PlainShow\Kit\Signed;

function v1_send(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function v1_project(mysqli $conn, int $id, Identity $identity): array
{
    $stmt = $conn->prepare('SELECT d.document_id, d.title, d.user_id, d.created_at, d.updated_at, u.firstname, u.lastname FROM documents d JOIN `user` u ON u.user_id = d.user_id WHERE d.document_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userId = $identity->id();
    if (!$row) {
        v1_send(404, ['ok' => false, 'message' => ps_t('No such project.')]);
    }
    if ((int) $row['user_id'] !== $userId && !$identity->can('latex.project.moderate')) {
        $stmt = $conn->prepare('SELECT 1 FROM shared_documents WHERE document_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row() && !in_array($id, latexGroupProjectIds($userId), true)) {
            v1_send(404, ['ok' => false, 'message' => ps_t('No such project.')]);
        }
    }
    return $row;
}

$routes = [
    ['method' => 'GET', 'path' => '/health', 'capability' => null],
    ['method' => 'GET', 'path' => '/projects', 'capability' => 'latex.project.view'],
    ['method' => 'GET', 'path' => '/projects/{id}', 'capability' => 'latex.project.view'],
    ['method' => 'GET', 'path' => '/projects/{id}/file', 'capability' => 'latex.project.view'],
    ['method' => 'GET', 'path' => '/projects/{id}/pdf', 'capability' => 'latex.project.view'],
];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = '/' . trim((string) preg_replace('#^/api/v1#', '', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../plainshow.json'), true) ?: [];

if ($method === 'GET' && $path === '/health') {
    v1_send(200, ['ok' => true, 'service' => 'latex', 'version' => $manifest['version'] ?? null]);
}
if ($method === 'GET' && $path === '/openapi.json') {
    header('Cache-Control: public, max-age=300');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(OpenApi::build($routes, [
        'GET /health' => ['summary' => 'Whether LaTeX answers', 'returns' => 'The service name and version'],
        'GET /projects' => ['summary' => 'Your projects', 'description' => 'Projects you own or that are shared with you, most recently changed first.'],
        'GET /projects/{id}' => ['summary' => 'A project', 'description' => 'Title, owner, the people it is shared with, and its files.', 'params' => ['id' => 'The project']],
        'GET /projects/{id}/file' => ['summary' => 'A file in a project', 'description' => 'The file as it is stored, such as main.tex or a picture.', 'params' => ['id' => 'The project'], 'query' => ['path' => 'The file, such as main.tex or figures/plot.png']],
        'GET /projects/{id}/pdf' => ['summary' => 'The compiled PDF', 'description' => 'The PDF from the last compile in the editor. 404 if it was never compiled.', 'params' => ['id' => 'The project']],
    ], ['title' => 'PlainShow LaTeX', 'version' => (string) ($manifest['version'] ?? '1'), 'description' => 'Read your LaTeX projects, their files and their PDFs.'], ps_url('core', '/api/latex/v1')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Signed::present($_SERVER)) {
    v1_send(401, ['ok' => false, 'message' => ps_t('Use {url} with a PlainShow API token.', ['url' => ps_url('core', '/api/latex/v1')])]);
}
$secret = is_file(__DIR__ . '/../config/widgets.php') ? (string) (require __DIR__ . '/../config/widgets.php')['secret'] : '';
try {
    $identity = Signed::identity(Signed::verify($_SERVER, (string) file_get_contents('php://input'), $secret));
} catch (KitException $error) {
    v1_send(401, ['ok' => false, 'message' => $error->getMessage()]);
}
if (!$identity->hasService('latex') || !$identity->can('latex.project.view')) {
    v1_send(403, ['ok' => false, 'message' => ps_t('You cannot open LaTeX projects.')]);
}
if (latexPlatformStatus($identity->id()) !== 200) {
    v1_send(403, ['ok' => false, 'message' => ps_t('LaTeX access is unavailable.')]);
}
if ($method !== 'GET') {
    v1_send(405, ['ok' => false, 'message' => ps_t('The LaTeX API only reads.')]);
}

$userId = $identity->id();
if ($path === '/projects') {
    $groupSql = latexGroupProjectSql($userId);
    $stmt = $conn->prepare('
        SELECT d.document_id, d.title, d.user_id, u.firstname, u.lastname,
               d.updated_at AS changed
        FROM documents d JOIN `user` u ON u.user_id = d.user_id
        WHERE d.user_id = ? OR EXISTS (SELECT 1 FROM shared_documents s WHERE s.document_id = d.document_id AND s.user_id = ?)
        ' . $groupSql . ' ORDER BY changed DESC');
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    v1_send(200, ['ok' => true, 'projects' => array_map(fn (array $row) => [
        'id' => (int) $row['document_id'],
        'title' => $row['title'],
        'owner' => ['id' => (int) $row['user_id'], 'name' => trim($row['firstname'] . ' ' . $row['lastname'])],
        'yours' => (int) $row['user_id'] === $userId,
        'changedAt' => date(DATE_ATOM, strtotime((string) $row['changed'])),
    ], $stmt->get_result()->fetch_all(MYSQLI_ASSOC))]);
}

if (!preg_match('#^/projects/([0-9]+)(/file|/pdf)?$#', $path, $match)) {
    v1_send(404, ['ok' => false, 'message' => ps_t('Not found.')]);
}
$project = v1_project($conn, (int) $match[1], $identity);
$id = (int) $project['document_id'];
$part = $match[2] ?? '';

if ($part === '') {
    $stmt = $conn->prepare('SELECT u.user_id, u.firstname, u.lastname FROM shared_documents s JOIN `user` u ON u.user_id = s.user_id WHERE s.document_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tree = is_dir(workspaceDocumentRoot($id)) ? workspaceBuildTree($id) : [];
    v1_send(200, ['ok' => true, 'project' => [
        'id' => $id,
        'title' => $project['title'],
        'owner' => ['id' => (int) $project['user_id'], 'name' => trim($project['firstname'] . ' ' . $project['lastname'])],
        'sharedWith' => array_map(fn (array $row) => ['id' => (int) $row['user_id'], 'name' => trim($row['firstname'] . ' ' . $row['lastname'])], $stmt->get_result()->fetch_all(MYSQLI_ASSOC)),
        'createdAt' => $project['created_at'] ? date(DATE_ATOM, strtotime((string) $project['created_at'])) : null,
        'files' => $tree,
    ]]);
}

$file = $part === '/pdf' ? workspaceDocumentRoot($id) . "/document_$id.pdf" : null;
if ($part === '/file') {
    try {
        $file = workspaceRelativeToAbsolute($id, (string) ($_GET['path'] ?? ''));
    } catch (RuntimeException $error) {
        v1_send(400, ['ok' => false, 'message' => $error->getMessage()]);
    }
    if (workspaceIsIgnored($id, workspaceAbsoluteToRelative($id, $file))) {
        v1_send(404, ['ok' => false, 'message' => ps_t('No such file.')]);
    }
}
if (!$file || !is_file($file) || is_link($file)) {
    v1_send(404, ['ok' => false, 'message' => $part === '/pdf' ? 'The project has not been compiled yet.' : 'No such file.']);
}
$type = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
header('Content-Type: ' . ($type === 'text/x-tex' || str_starts_with($type, 'text/') ? 'text/plain; charset=utf-8' : $type));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($file)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($file));
readfile($file);
