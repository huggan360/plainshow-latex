<?php
// Copies the PlainShow UI kit to ./ui so pages can load it: php lib/publish-ui.php
$source = __DIR__ . '/../vendor/plainshow/kit/ui';
$target = __DIR__ . '/../ui';
$copy = function (string $from, string $to) use (&$copy): void {
    is_dir($to) || mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir("$from/$entry") ? $copy("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
        }
    }
};
foreach (['css', 'fonts', 'js', 'brand'] as $directory) {
    $copy("$source/$directory", "$target/$directory");
}
echo "PlainShow UI published.\n";
