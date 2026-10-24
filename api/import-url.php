<?php
/*
 * Fetches a file from a public https address into the project. Guards
 * against reaching the server's own network: only https on port 443, the
 * host must resolve to public addresses only, the connection is pinned to
 * the checked address, no redirects, 10 MB at most.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

const IMPORT_MAX_BYTES = 10 * 1024 * 1024;

function importFail(string $message, int $status = 400): never
{
    http_response_code($status);
    exit(json_encode(['status' => 'error', 'message' => $message]));
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$documentId = (int) ($input['document_id'] ?? 0);
$url = trim((string) ($input['url'] ?? ''));
$targetDir = trim((string) ($input['target_dir'] ?? ''), '/');
$parts = parse_url($url);
if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
    importFail('Use a public https address.');
}
$host = strtolower(rtrim($parts['host'], '.'));
$addresses = [];
foreach (array_merge(dns_get_record($host, DNS_A) ?: [], dns_get_record($host, DNS_AAAA) ?: []) as $record) {
    $addresses[] = $record['ip'] ?? $record['ipv6'];
}
if (filter_var($host, FILTER_VALIDATE_IP)) {
    $addresses = [$host];
}
if (!$addresses) {
    importFail('That address could not be found.');
}
foreach ($addresses as $address) {
    if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        importFail('That address is not public.');
    }
}

$name = trim((string) ($input['name'] ?? '')) ?: basename((string) ($parts['path'] ?? '')) ?: 'download';
$name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'download';
try {
    workspaceAssertSafeName($name);
    $target = workspaceRelativeToAbsolute($documentId, $targetDir);
    if (!is_dir($target)) {
        throw new RuntimeException('The target folder does not exist.');
    }
} catch (RuntimeException $error) {
    importFail($error->getMessage());
}

$temporary = tempnam(sys_get_temp_dir(), 'latex-import-');
$out = fopen($temporary, 'wb');
$received = 0;
$curl = curl_init($url);
$pin = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
curl_setopt_array($curl, [
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_RESOLVE => ["$host:443:$pin"],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'PlainShow LaTeX file import',
    CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($out, &$received): int {
        $received += strlen($chunk);
        if ($received > IMPORT_MAX_BYTES) {
            return 0;
        }
        return fwrite($out, $chunk);
    },
]);
$ok = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
fclose($out);
if (!$ok || $status !== 200) {
    @unlink($temporary);
    importFail($received > IMPORT_MAX_BYTES ? 'The file is larger than 10 MB.' : 'The file could not be fetched (status ' . $status . ').');
}

$to = $target . '/' . $name;
if (file_exists($to)) {
    $info = pathinfo($name);
    $name = $info['filename'] . '_' . time() . (isset($info['extension']) ? '.' . $info['extension'] : '');
    $to = $target . '/' . $name;
}
if (!rename($temporary, $to)) {
    @unlink($temporary);
    importFail('The file could not be saved.', 500);
}
@chmod($to, 0664);
$relative = workspaceAbsoluteToRelative($documentId, $to);
$stmt = $conn->prepare('INSERT INTO document_files (document_id, file_name, file_path) VALUES (?, ?, ?)');
$stmt->bind_param('iss', $documentId, $relative, $to);
$stmt->execute();
echo json_encode(['status' => 'success', 'path' => $relative]);
