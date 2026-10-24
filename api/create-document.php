<?php
// A new project: a folder with a main.tex to start from.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_workspace.php';
header('Content-Type: application/json');

$data = json_decode((string) file_get_contents('php://input'), true) ?: [];
$title = trim((string) ($data['title'] ?? ''));
$shared = latexRequestedUserIds($user_id, $data['shared_users'] ?? []);
$groups = latexRequestedGroupIds($user_id, $data['shared_groups'] ?? []);
if (!latexCan('latex.project.create')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => ps_t('You do not have permission to create projects.')]);
    exit;
}
if ($title === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => ps_t('The project needs a name.')]);
    exit;
}
if ($groups === null || $shared === null) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => ps_t('Choose people and groups with LaTeX access.')]);
    exit;
}

$stmt = $conn->prepare("INSERT INTO documents (user_id, title, root_file, last_editor_id) VALUES (?, ?, 'main.tex', ?)");
$stmt->bind_param('isi', $user_id, $title, $user_id);
$stmt->execute();
$documentId = $stmt->insert_id;
$stmt->close();

$dir = workspaceDocumentRoot($documentId);
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => ps_t('Could not create the project folder.')]);
    exit;
}
$escaped = strtr($title, ['\\' => '\\textbackslash{}', '{' => '\\{', '}' => '\\}', '$' => '\\$', '&' => '\\&', '#' => '\\#', '%' => '\\%', '_' => '\\_', '^' => '\\^{}', '~' => '\\~{}']);
file_put_contents("$dir/main.tex", <<<TEX
\\documentclass[12pt]{article}

\\usepackage[utf8]{inputenc}
\\usepackage{amsmath}
\\usepackage{graphicx}
\\usepackage[a4paper, margin=1in]{geometry}
\\usepackage{hyperref}

\\title{{$escaped}}
\\author{}
\\date{\\today}

\\begin{document}

\\maketitle

\\section{Introduction}

Start writing here.

\\end{document}

TEX);

if ($shared) {
    $stmt = $conn->prepare('INSERT INTO shared_documents (document_id, user_id) VALUES (?, ?)');
    foreach ($shared as $person) {
        $stmt->bind_param('ii', $documentId, $person);
        $stmt->execute();
    }
    $stmt->close();
}

if ($groups) {
    $stmt = $conn->prepare('INSERT INTO latex_group_shares (document_id, group_id) VALUES (?, ?)');
    foreach ($groups as $groupId) {
        $stmt->bind_param('ii', $documentId, $groupId);
        $stmt->execute();
    }
    $stmt->close();
}

echo json_encode(['status' => 'success', 'documentId' => $documentId]);
