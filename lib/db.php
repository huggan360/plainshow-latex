<?php
// LaTeX's database connection. Its tables live in the blog's woc database;
// credentials are in config/database.php, outside git. Sets the global $conn.
if (!function_exists('latexDb')) {
    function latexDb(): mysqli
    {
        static $conn = null;
        if ($conn === null) {
            $config = require __DIR__ . '/../config/database.php';
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new mysqli($config['host'], $config['user'], $config['password'], $config['database']);
            $conn->set_charset('utf8mb4');
        }
        return $conn;
    }
}
$conn = latexDb();
