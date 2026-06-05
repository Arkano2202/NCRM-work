<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat_images_admin");

$fileName = basename((string) ($_GET['file'] ?? ''));
if ($fileName === '' || $fileName === '.' || $fileName === '..') {
    http_response_code(404);
    echo "Archivo no encontrado";
    exit;
}

$fullPath = chatEnsureAdminReportDirectory() . DIRECTORY_SEPARATOR . $fileName;
if (!is_file($fullPath)) {
    http_response_code(404);
    echo "Archivo no encontrado";
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"" . str_replace('"', '', $fileName) . "\"");
header('Content-Length: ' . (string) filesize($fullPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($fullPath);
exit;
