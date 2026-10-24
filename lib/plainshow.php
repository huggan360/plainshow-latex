<?php
/*
 * PlainShow sign-in and access rules for LaTeX.
 *
 *   latexRequireUser()            pages: sign in if needed
 *   latexApiUser()                API: 401 JSON when signed out
 *   latexCanAccess($doc, $user)   owner, shared with, or LaTeX moderator
 *   latexWsTicket($doc)           short-lived proof for the collaboration server
 *
 * Names and sharing lists still come from the blog's `user` table, so it is
 * kept in step with the PlainShow account at every sign-in.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/* Texts in the visitor's language: lang/<code>.json, keyed by the English text (kit Lang). */
if (!function_exists('ps_t')) {
    function ps_t(string $text, array $params = []): string
    {
        return class_exists(\PlainShow\Kit\Lang::class) ? \PlainShow\Kit\Lang::t($text, $params) : strtr($text, array_combine(array_map(fn ($k) => '{' . $k . '}', array_keys($params)), array_map('strval', $params)) ?: []);
    }
    function ps_h(string $text, array $params = []): string
    {
        return htmlspecialchars(ps_t($text, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('ps_url')) {
    /** An address in a PlainShow app (kit Platform), or '' when that app is not installed here. */
    function ps_url(string $app, string $path = ''): string
    {
        $base = \PlainShow\Kit\Platform::url($app);

        return $base === null ? '' : $base . $path;
    }
}
if (class_exists(\PlainShow\Kit\Lang::class)) {
    \PlainShow\Kit\Lang::load(__DIR__ . '/../lang');
}
require_once __DIR__ . '/db.php';

use PlainShow\Kit\Identity;
use PlainShow\Kit\PhpSessionAuth;
use PlainShow\Kit\Widgets;

function latexConfig(): array
{
    static $config = null;

    return $config ??= require __DIR__ . '/../config/plainshow.php';
}

function latexSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.save_path', dirname(__DIR__) . '/sessions');
    ini_set('session.use_strict_mode', '1');
    session_name('latex_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function latexSyncUser(Identity $identity): void
{
    global $conn;
    $id = $identity->id();
    $username = mb_substr($identity->username(), 0, 15);
    $first = mb_substr($identity->firstName(), 0, 30);
    $last = mb_substr($identity->lastName(), 0, 30);
    $level = ['admin' => 5, 'moderator' => 4, 'member' => 1][$identity->role()] ?? 0;
    $picture = $identity->picture();

    $stmt = $conn->prepare('SELECT 1 FROM `user` WHERE `user_id` = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($exists) {
        $stmt = $conn->prepare('UPDATE `user` SET `username` = ?, `firstname` = ?, `lastname` = ?, `access_level` = ?, `image_source` = COALESCE(?, `image_source`) WHERE `user_id` = ?');
        $stmt->bind_param('sssisi', $username, $first, $last, $level, $picture, $id);
    } else {
        $source = $picture ?? '';
        $stmt = $conn->prepare("INSERT INTO `user` (`user_id`, `username`, `password`, `firstname`, `lastname`, `access_level`, `image_source`) VALUES (?, ?, '', ?, ?, ?, ?)");
        $stmt->bind_param('isssis', $id, $username, $first, $last, $level, $source);
    }
    $stmt->execute();
    $stmt->close();
}

function latexAuth(): PhpSessionAuth
{
    static $auth = null;
    latexSession();

    return $auth ??= new PhpSessionAuth(latexConfig(), function (Identity $identity): void {
        latexSyncUser($identity);
        $_SESSION['user_id'] = $identity->id();
    });
}

function latexRequireUser(): Identity
{
    $identity = latexAuth()->requireUser();
    $status = latexPlatformStatus($identity->id());
    if ($status !== 200) {
        http_response_code($status === 403 ? 403 : 503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . ps_h('LaTeX') . '</title><p>', $status === 403 ? ps_t('You no longer have access to LaTeX.') : ps_t('PlainShow is temporarily unavailable.'), '</p><a href="' . htmlspecialchars(ps_url('core', '/'), ENT_QUOTES) . '">' . ps_h('PlainShow') . '</a></html>';
        exit;
    }
    $_SESSION['user_id'] = $identity->id();

    return $identity;
}

/** For API endpoints: the signed-in user id, or a JSON 401 and stop. */
function latexApiUser(): int
{
    $identity = latexAuth()->user();
    $status = $identity === null ? 401 : latexPlatformStatus($identity->id());
    if ($status !== 200 || !$identity->hasService('latex')) {
        http_response_code($status === 401 ? 401 : ($status === 403 ? 403 : 503));
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $status === 401 ? ps_t('Sign in again to continue.') : ps_t('No access to LaTeX.')]);
        exit;
    }
    $_SESSION['user_id'] = $identity->id();

    return $identity->id();
}

function latexPlatformStatus(int $userId): int
{
    return latexPlatformResponse($userId)['status'];
}

function latexPlatformGroups(int $userId): array
{
    return latexPlatformResponse($userId)['groups'];
}

function latexPlatformPeople(int $userId): array
{
    static $synced = [];
    $people = latexPlatformResponse($userId)['people'];
    if (!isset($synced[$userId])) {
        latexSyncPlatformPeople($people);
        $synced[$userId] = true;
    }
    return $people;
}

function latexCan(string $capability): bool
{
    $identity = latexAuth()->user();
    if ($identity === null) {
        return false;
    }
    $granted = array_map('strval', latexPlatformResponse($identity->id())['viewer']['capabilities'] ?? []);
    return in_array('*', $granted, true) || in_array($capability, $granted, true);
}

function latexHasService(string $service): bool
{
    $identity = latexAuth()->user();
    return $service === 'core' || ($identity !== null && in_array($service, array_map('strval', latexPlatformResponse($identity->id())['viewer']['services'] ?? []), true));
}

function latexPlatformResponse(int $userId): array
{
    static $responses = [];
    if (isset($responses[$userId])) {
        return $responses[$userId];
    }
    $file = __DIR__ . '/../config/widgets.php';
    $secret = is_file($file) ? (string) ((require $file)['secret'] ?? '') : '';
    if ($secret === '') {
        return $responses[$userId] = ['status' => 0, 'groups' => [], 'people' => [], 'viewer' => []];
    }
    // Core runs on this server: the request goes straight to it.
    $core = \PlainShow\Kit\Platform::require('core');
    $host = (string) parse_url($core, PHP_URL_HOST);
    $url = $core.'/api/core/v1/groups/for-user?'.http_build_query(
        ['extension' => 'latex'] + Widgets::sign($userId, 'groups', $secret),
    );
    $connection = curl_init($url);
    curl_setopt_array($connection, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RESOLVE => ["$host:443:127.0.0.1"],
    ]);
    $body = curl_exec($connection);
    $status = curl_getinfo($connection, CURLINFO_HTTP_CODE);
    curl_close($connection);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($status === 200 && (!is_array($decoded) || !is_array($decoded['groups'] ?? null) || !is_array($decoded['people'] ?? null) || !is_array($decoded['viewer'] ?? null))) {
        $status = 0;
    }

    return $responses[$userId] = [
        'status' => $status,
        'groups' => $status === 200 ? $decoded['groups'] : [],
        'people' => $status === 200 ? $decoded['people'] : [],
        'viewer' => $status === 200 ? $decoded['viewer'] : [],
    ];
}

function latexSyncPlatformPeople(array $people): void
{
    global $conn;
    $statement = $conn->prepare("INSERT INTO `user` (`user_id`, `username`, `password`, `firstname`, `lastname`, `access_level`, `image_source`)
        VALUES (?, ?, '', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `firstname` = VALUES(`firstname`), `lastname` = VALUES(`lastname`), `access_level` = VALUES(`access_level`), `image_source` = VALUES(`image_source`)");
    foreach ($people as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $username = mb_substr((string) ($person['username'] ?? ''), 0, 15);
        $first = mb_substr((string) ($person['firstName'] ?? ''), 0, 30);
        $last = mb_substr((string) ($person['lastName'] ?? ''), 0, 30);
        $role = (string) ($person['role']['key'] ?? 'user');
        $level = ['admin' => 5, 'moderator' => 4, 'user' => 1][$role] ?? 1;
        $picture = mb_substr((string) ($person['avatar'] ?? ''), 0, 255);
        $statement->bind_param('isssis', $id, $username, $first, $last, $level, $picture);
        $statement->execute();
    }
    $statement->close();
}

function latexRequestedUserIds(int $userId, mixed $requested): ?array
{
    if (!is_array($requested) || count($requested) > 100) {
        return null;
    }
    $available = array_map('intval', array_column(latexPlatformPeople($userId), 'id'));
    $ids = [];
    foreach ($requested as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value) || !in_array((int) $value, $available, true)) {
            return null;
        }
        if ((int) $value !== $userId) {
            $ids[] = (int) $value;
        }
    }
    return array_values(array_unique($ids));
}

function latexGroupProjectIds(int $userId, bool $write = false): array
{
    static $cache = [];
    $key = $userId . ':' . (int) $write;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    global $conn;
    $groups = array_filter(latexPlatformGroups($userId), static function ($group) use ($write): bool {
        if (!is_array($group) || !filter_var($group['id'] ?? null, FILTER_VALIDATE_INT)) {
            return false;
        }
        $permission = $group['permissions']['latex'] ?? 'none';
        return $permission === 'edit' || (!$write && $permission === 'view');
    });
    if ($groups === []) {
        return $cache[$key] = [];
    }
    $ids = implode(',', array_map(static fn ($group) => (int) $group['id'], $groups));
    $result = $conn->query("SELECT DISTINCT document_id FROM latex_group_shares WHERE group_id IN ($ids)");
    return $cache[$key] = array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'document_id'));
}

function latexGroupProjectSql(int $userId): string
{
    if (!latexCan('latex.project.view') && !latexCan('latex.project.moderate')) {
        return '';
    }
    $ids = latexGroupProjectIds($userId);
    return $ids === [] ? '' : ' OR d.document_id IN (' . implode(',', $ids) . ')';
}

function latexRequestedGroupIds(int $userId, mixed $requested): ?array
{
    if (!is_array($requested) || count($requested) > 50) {
        return null;
    }
    $available = array_map(static fn ($group) => (int) $group['id'], latexPlatformGroups($userId));
    $ids = [];
    foreach ($requested as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value) || !in_array((int) $value, $available, true)) {
            return null;
        }
        $ids[] = (int) $value;
    }
    return array_values(array_unique($ids));
}

function latexCanAccess(int $documentId, int $userId, bool $write = false): bool
{
    global $conn;
    if (latexCan('latex.project.moderate')) {
        return true;
    }
    $stmt = $conn->prepare('SELECT 1 FROM `documents` WHERE document_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $documentId, $userId);
    $stmt->execute();
    $owned = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($owned) {
        return true;
    }
    if (!latexCan('latex.project.view')) {
        return false;
    }
    $stmt = $conn->prepare('SELECT 1 FROM `shared_documents` WHERE document_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $documentId, $userId);
    $stmt->execute();
    $allowed = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $allowed || in_array($documentId, latexGroupProjectIds($userId, $write), true);
}

/** A two-minute ticket proving who may join a document on the collaboration server. */
function latexWsTicket(int $documentId, int $userId, string $name): string
{
    $payload = rtrim(strtr(base64_encode(json_encode(['u' => $userId, 'd' => $documentId, 'n' => $name, 'e' => time() + 120])), '+/', '-_'), '=');

    return $payload . '.' . hash_hmac('sha256', $payload, latexConfig()['ws_ticket_secret']);
}

/** The session's CSRF token. Pages put it in a meta tag; js/csrf.js sends it with every request. */
function latexCsrfToken(): string
{
    latexSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function latexCsrfValid(): bool
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '');
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}
