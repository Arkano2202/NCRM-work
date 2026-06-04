<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat");

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$imageId = (int) ($_GET['id'] ?? 0);

if ($currentUserId <= 0 || $imageId <= 0) {
    http_response_code(400);
    exit('Solicitud invalida');
}

chatMaybePurge($conn);
$image = chatFindImageForUser($conn, $imageId, $currentUserId);
if (!$image) {
    http_response_code(404);
    exit('Imagen no disponible');
}

if ((int) ($image['conversacion_id'] ?? 0) > 0 && (int) ($image['receptor_id'] ?? 0) === $currentUserId) {
    chatMarkImageViewed($conn, $imageId, $currentUserId);
}

$relativePath = trim((string) ($image['ruta_relativa'] ?? ''));
$fullPath = '';
if ($relativePath !== '') {
    $fullPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
}
if ($fullPath === '' || !is_file($fullPath)) {
    $fullPath = chatImageAbsolutePathFromFileName((string) ($image['nombre_archivo'] ?? ''));
}

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

$mimeType = trim((string) ($image['mime_type'] ?? 'application/octet-stream'));
$fileName = basename((string) ($image['nombre_original'] ?? 'imagen'));
$download = isset($_GET['download']) && $_GET['download'] === '1';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($fileName) . '"');
readfile($fullPath);
exit;
