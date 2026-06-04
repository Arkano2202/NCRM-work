<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/i18n.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat_images_admin");

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo no permitido']);
    exit;
}

$deleteAll = isset($_POST['delete_all']) && $_POST['delete_all'] === '1';
if ($deleteAll) {
    $deletedCount = chatDeleteAllAdminUploadFiles();
    echo json_encode([
        'ok' => true,
        'deleted_all' => true,
        'deleted_count' => $deletedCount,
        'message' => t('uploads_files.delete_all_success'),
    ]);
    exit;
}

$fileName = trim((string) ($_POST['file'] ?? ''));
if ($fileName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => t('uploads_files.delete_error')]);
    exit;
}

$deleted = chatDeleteAdminUploadFile($fileName);
echo json_encode([
    'ok' => $deleted,
    'message' => $deleted ? t('uploads_files.delete_success') : t('uploads_files.delete_error'),
]);
exit;
