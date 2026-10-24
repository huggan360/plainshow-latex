<?php

function workspaceDocumentRoot(int $documentId): string
{
    return realpath(__DIR__ . '/../temp') . "/document_{$documentId}";
}

function ensureWorkspaceDocumentRoot(int $documentId): string
{
    $root = workspaceDocumentRoot($documentId);
    if (!is_dir($root)) {
        throw new RuntimeException('Document workspace does not exist.');
    }

    return $root;
}

function workspaceRelativeToAbsolute(int $documentId, string $relativePath): string
{
    $root = ensureWorkspaceDocumentRoot($documentId);
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    if ($relativePath === '') {
        return $root;
    }

    if (strpos($relativePath, "\0") !== false) throw new RuntimeException('Invalid path.');
    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
        if ($segment === '..' || $segment === '.' || $segment === '') throw new RuntimeException('Invalid path.');
    }

    if (!$segments) {
        return $root;
    }

    $target = $root;
    foreach ($segments as $segment) {
        $target .= '/' . $segment;
        if (is_link($target)) throw new RuntimeException('Symbolic links are not supported.');
    }
    return $target;
}

function workspaceAbsoluteToRelative(int $documentId, string $absolutePath): string
{
    $root = ensureWorkspaceDocumentRoot($documentId);
    $rootPrefix = rtrim($root, '/') . '/';

    if ($absolutePath === $root) {
        return '';
    }

    if (strpos($absolutePath, $rootPrefix) !== 0) {
        throw new RuntimeException('Path is outside workspace.');
    }

    return substr($absolutePath, strlen($rootPrefix));
}

function workspaceIsIgnored(int $documentId, string $relativePath): bool
{
    $basename = basename($relativePath);
    $generatedPrefix = "document_{$documentId}";
    $ignoredExtensions = ['aux', 'fdb_latexmk', 'fls', 'log', 'out', 'synctex.gz', 'toc'];

    if ($basename === '.' || $basename === '..') {
        return true;
    }

    if (preg_match('/^\./', $basename)) {
        return true;
    }

    // Compiler output: document_<id>.pdf for the main file, document_<id>--<name>.pdf for other documents.
    if (strpos($basename, $generatedPrefix . '.') === 0 || strpos($basename, $generatedPrefix . '--') === 0) {
        return true;
    }

    foreach ($ignoredExtensions as $extension) {
        if (preg_match('/\.' . preg_quote($extension, '/') . '$/i', $basename)) {
            return true;
        }
    }

    return false;
}

function workspaceBuildTree(int $documentId, string $absoluteDirectory = null): array
{
    $directory = $absoluteDirectory ?: ensureWorkspaceDocumentRoot($documentId);
    $entries = scandir($directory);
    if ($entries === false) {
        return [];
    }

    $items = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $absolutePath = $directory . '/' . $entry;
        if (is_link($absolutePath)) continue;
        $relativePath = workspaceAbsoluteToRelative($documentId, $absolutePath);
        if (workspaceIsIgnored($documentId, $relativePath)) {
            continue;
        }

        if (is_dir($absolutePath)) {
            $items[] = [
                'type' => 'folder',
                'name' => $entry,
                'path' => $relativePath,
                'children' => workspaceBuildTree($documentId, $absolutePath)
            ];
            continue;
        }

        $items[] = [
            'type' => 'file',
            'name' => $entry,
            'path' => $relativePath,
            'size' => filesize($absolutePath) ?: 0
        ];
    }

    usort($items, static function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }

        return strnatcasecmp($a['name'], $b['name']);
    });

    return $items;
}

/** Names that may exist in a project: no hidden files and nothing a web server could run. */
function workspaceAssertSafeName(string $name): void
{
    if ($name === '' || $name[0] === '.' || preg_match('/[\\\\\/\x00]/', $name)
        || preg_match('/\.(php\d?|phtml|phar|pht|phps|cgi|pl|sh|htaccess|htpasswd)$/i', $name)) {
        throw new RuntimeException('That file name or type is not allowed in a project.');
    }
}

/** Keep the project's main file pointing at it after a rename or move of it or a folder above it. */
function workspaceFollowRootFile(mysqli $conn, int $documentId, string $oldPath, string $newPath): void
{
    $prefix = $oldPath . '/%';
    $from = strlen($oldPath) + 1;
    $stmt = $conn->prepare('UPDATE documents SET root_file = CONCAT(?, SUBSTRING(root_file, ?)) WHERE document_id = ? AND (root_file = ? OR root_file LIKE ?)');
    $stmt->bind_param('siiss', $newPath, $from, $documentId, $oldPath, $prefix);
    $stmt->execute();
    $stmt->close();
}
