<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => ps_t('Unauthorized')]);
    exit;
}
if (!latexCan('latex.project.create') && !latexCan('latex.project.moderate')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => ps_t('You do not have permission to share projects.')]);
    exit;
}

$users = array_values(array_map(static fn (array $person): array => [
    'user_id' => (int) $person['id'],
    'firstname' => (string) ($person['firstName'] ?? ''),
    'lastname' => (string) ($person['lastName'] ?? ''),
    'username' => (string) ($person['username'] ?? ''),
    'image_source' => $person['avatar'] ?? '',
], array_filter(latexPlatformPeople((int) $user_id), static fn (array $person): bool => (int) $person['id'] !== (int) $user_id)));

echo json_encode(['status' => 'success', 'users' => $users, 'groups' => array_map(
    static fn ($group) => ['id' => (int) $group['id'], 'name' => (string) $group['name']],
    latexPlatformGroups((int) $user_id),
)]);
?>
