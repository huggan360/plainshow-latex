<?php
/*
 * Compile a project: pdflatex on its main file (documents.root_file), or on
 * another .tex file of the project that is a whole document (it has
 * \documentclass), run in the project folder so \input and \includegraphics
 * find their files. The main file's PDF is document_<id>.pdf, the address the
 * editor, /api/v1 and the dashboard widget read; another document's PDF is
 * document_<id>--<its path>.pdf, beside it and hidden from the file tree.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

function compile_fail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message] + $extra);
    exit;
}

$documentId = (int) ($_POST['document_id'] ?? 0);
if ($documentId <= 0) {
    compile_fail(400, 'Project ID is required.');
}

/** chapters/Report 2.tex -> chapters_Report_2; the browser makes the same name (js/editor/compile.js). */
function latex_job_slug(string $path): string
{
    return substr(trim(preg_replace('/[^A-Za-z0-9]+/', '_', preg_replace('/\.tex$/i', '', $path)), '_'), 0, 120);
}

$latex = getenv('LATEX_BIN') ?: '/mnt/texlive/2026/bin/aarch64-linux/pdflatex';
if (!is_file($latex) || !is_executable($latex)) {
    compile_fail(500, 'pdflatex is not installed where it should be.');
}

$statement = $conn->prepare('SELECT root_file FROM documents WHERE document_id = ?');
$statement->bind_param('i', $documentId);
$statement->execute();
$rootFile = (string) ($statement->get_result()->fetch_column() ?: 'main.tex');
$statement->close();

$file = trim((string) ($_POST['file'] ?? ''));
if ($file === '' || !preg_match('/\.tex$/i', $file)) {
    $file = $rootFile;
}
try {
    $directory = ensureWorkspaceDocumentRoot($documentId);
    $main = workspaceRelativeToAbsolute($documentId, $file);
} catch (RuntimeException $error) {
    compile_fail(400, $error->getMessage());
}
if ($file !== $rootFile && (!is_file($main) || !preg_match('/^[^%\n]*\\\\documentclass/m', (string) file_get_contents($main)))) {
    // Not a document of its own (a chapter, say): compile the main file instead.
    $file = $rootFile;
    $main = workspaceRelativeToAbsolute($documentId, $file);
}
if (!is_file($main)) {
    compile_fail(400, "The main file $rootFile is missing. Pick another in the file tree: right-click a .tex file and choose Use as main file.", ['root_file' => $rootFile]);
}
$job = 'document_' . $documentId . ($file === $rootFile ? '' : '--' . latex_job_slug($file));

// One compile per project at a time; a second click waits for the first.
$lock = fopen("$directory/.compile.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    compile_fail(500, 'Could not start the compiler.');
}

$command = [
    $latex, '-interaction=nonstopmode', '-file-line-error',
    // Never -shell-escape: it lets a document run commands on the server.
    '-no-shell-escape',
    '-jobname=' . $job,
    '-output-directory=' . $directory,
    $file,
];
$environment = [
    'PATH' => dirname($latex) . ':/usr/local/bin:/usr/bin:/bin',
    'HOME' => sys_get_temp_dir(),
    'openout_any' => 'p',
    // One message per line, so "file:line: error" stays whole.
    'max_print_line' => '10000',
    'error_line' => '254',
    'half_error_line' => '238',
];

$pdf = "$directory/$job.pdf";
$started = time();
// A second pass when LaTeX asks for one, for the contents and cross-references.
for ($pass = 1; $pass <= 3; $pass++) {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
    if (!is_resource($process)) {
        compile_fail(500, 'Could not start the compiler.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || !preg_match('/Rerun to get|Label\(s\) may have changed|Rerun LaTeX/', $output)) {
        break;
    }
}
flock($lock, LOCK_UN);
fclose($lock);

$lines = preg_split('/\R/', $output);
$pdfPath = "temp/document_$documentId/$job.pdf";
if ($code === 0) {
    echo json_encode(['status' => 'success', 'pdf_path' => $pdfPath, 'root_file' => $rootFile, 'file' => $file]);
    exit;
}
// Like Overleaf: with errors LaTeX often still makes a PDF. Show it, with the errors.
clearstatcache(true, $pdf);
$madePdf = is_file($pdf) && filemtime($pdf) >= $started;

$errors = [];
$warnings = [];
foreach ($lines as $line) {
    $line = trim(str_replace($directory . '/', '', $line));
    if (preg_match('/^[^\s:]+:\d+: /', $line) || str_starts_with($line, '! ')) {
        $errors[] = $line;
    } elseif (preg_match('/Warning:/', $line)) {
        $warnings[] = $line;
    }
}
// "Fatal error occurred" only repeats that there was an error.
$errors = array_values(array_filter($errors, fn ($line) => !str_contains($line, '==> Fatal error occurred'))) ?: $errors;
if (!$errors) {
    // Nothing in file:line form: fall back to anything that says error.
    $errors = array_values(array_filter(array_map('trim', $lines), fn ($line) => stripos($line, 'error') !== false));
}
echo json_encode([
    'status' => $madePdf ? 'partial' : 'error',
    'pdf_path' => $madePdf ? $pdfPath : null,
    'message' => $madePdf ? 'The PDF was made, with errors.' : 'The project did not compile.',
    'root_file' => $rootFile,
    'file' => $file,
    'output' => array_map(fn ($line) => str_replace($directory . '/', '', $line), $lines),
    'errors' => array_values(array_unique($errors)),
    'warnings' => array_values(array_unique($warnings)),
]);
