<?php
// Sections were removed in 2.0.0. Reload the editor.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['status' => 'error', 'message' => 'This part of LaTeX was removed. Reload the page.']);
