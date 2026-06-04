<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/i18n.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat_images_admin");

$images = chatListAdminImages($conn);
if (empty($images)) {
    http_response_code(404);
    exit(t('chat_images.empty'));
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit(t('chat_images.download_all_error'));
}

$zipPath = tempnam(sys_get_temp_dir(), 'chat_images_zip_');
if ($zipPath === false) {
    http_response_code(500);
    exit(t('chat_images.download_all_error'));
}

$zipFile = $zipPath . '.zip';
@rename($zipPath, $zipFile);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($zipFile);
    http_response_code(500);
    exit(t('chat_images.download_all_error'));
}

$usedNames = [];
$added = 0;

foreach ($images as $image) {
    $fileName = trim((string) ($image['file_name'] ?? ''));
    $fullPath = $fileName !== '' ? chatResolveAdminImagePath($fileName) : null;
    if ($fullPath === null || !is_file($fullPath)) {
        continue;
    }

    $baseName = basename(str_replace('\\', '/', $fileName));
    if ($baseName === '') {
        $baseName = 'imagen_' . ($added + 1);
    }

    $uniqueName = $baseName;
    $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
    $extension = pathinfo($baseName, PATHINFO_EXTENSION);
    $suffix = 2;
    while (isset($usedNames[mb_strtolower($uniqueName)])) {
        $uniqueName = $nameWithoutExt . '_' . $suffix . ($extension !== '' ? '.' . $extension : '');
        $suffix++;
    }

    $usedNames[mb_strtolower($uniqueName)] = true;
    if ($zip->addFile($fullPath, $uniqueName)) {
        $added++;
    }
}

$zip->close();

if ($added === 0 || !is_file($zipFile)) {
    @unlink($zipFile);
    http_response_code(404);
    exit(t('chat_images.empty'));
}

$downloadName = 'imagenes_chat_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipFile));
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($zipFile);
@unlink($zipFile);
exit;
