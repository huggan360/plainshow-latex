<?php
/*
 * database/migrations/*.sql, each once and in name order: php bin/migrate.php
 * The first one is the schema as it was in 2026 and changes nothing where the
 * tables exist. Updates runs this after moving to a new release.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/db.php';

$db = latexDb();
// Checked first: creating it, even "if not exists", needs a right a shared database may not give.
if ($db->query("SHOW TABLES LIKE 'latex_migrations'")->num_rows === 0) {
    $db->query('CREATE TABLE `latex_migrations` (`name` varchar(190) NOT NULL PRIMARY KEY, `ran_at` datetime NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
$done = array_column($db->query('SELECT `name` FROM `latex_migrations`')->fetch_all(MYSQLI_ASSOC), 'name');
$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    $db->multi_query((string) file_get_contents($file));
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
    $stmt = $db->prepare('INSERT INTO `latex_migrations` (`name`, `ran_at`) VALUES (?, NOW())');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    echo "Migrated $name\n";
}
