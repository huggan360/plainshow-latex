<?php
/*
 * LaTeX's widgets on the PlainShow dashboard: /api/v1/widgets/{name}.
 * Core signs each request for a person (PlainShow\Kit\Widgets); no session.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/plainshow.php';
require_once __DIR__ . '/../lib/view.php';

use PlainShow\Kit\KitException;
use PlainShow\Kit\Widgets;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$widget = (string) ($_GET['widget'] ?? '');
$secret = is_file(__DIR__ . '/../config/widgets.php') ? (string) (require __DIR__ . '/../config/widgets.php')['secret'] : '';
if ($widget !== 'recent-projects') {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'message' => ps_t('Unknown widget.')]));
}
try {
    $userId = Widgets::verify($_GET, $widget, $secret);
} catch (KitException $error) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'message' => $error->getMessage()]));
}
if (latexPlatformStatus($userId) !== 200) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'message' => ps_t('LaTeX access is unavailable.')]));
}

$groupSql = latexGroupProjectSql($userId);
$stmt = $conn->prepare('
    SELECT d.document_id, d.title, d.user_id, u.firstname, u.lastname,
           d.updated_at AS changed
    FROM documents d JOIN `user` u ON u.user_id = d.user_id
    WHERE d.user_id = ? OR EXISTS (SELECT 1 FROM shared_documents s WHERE s.document_id = d.document_id AND s.user_id = ?)
    ' . $groupSql . ' ORDER BY changed DESC LIMIT 5');
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$items = array_map(fn (array $row) => [
    'title' => $row['title'],
    'meta' => ((int) $row['user_id'] === $userId ? 'Yours' : trim($row['firstname'] . ' ' . $row['lastname'])) . ', ' . latex_ago($row['changed']),
    'url' => ps_url('latex', '/') . (latexCanAccess((int) $row['document_id'], $userId, true) ? 'edit' : 'view') . '?document_id=' . (int) $row['document_id'],
    'icon' => 'bx-file',
], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
echo json_encode(['ok' => true, 'type' => 'list', 'items' => $items, 'empty' => ps_t('No projects yet.'), 'more' => ['label' => ps_t('All projects'), 'url' => ps_url('latex', '/')]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
