<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat_images_admin");

$fileName = trim((string) ($_GET['file'] ?? ''));
if ($fileName === '') {
    http_response_code(400);
    exit('Solicitud invalida');
}

$file = chatFindAdminUploadFile($fileName);
$fullPath = chatResolveAdminUploadPath($fileName);
if (!$file || $fullPath === null) {
    http_response_code(404);
    exit('Archivo no disponible');
}

$mimeType = trim((string) ($file['mime_type'] ?? 'application/octet-stream'));
$download = isset($_GET['download']) && $_GET['download'] === '1';
$displayName = basename((string) ($file['file_name'] ?? 'archivo'));

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($displayName) . '"');
readfile($fullPath);
exit;
