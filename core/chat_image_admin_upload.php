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

if (!isset($_FILES['images'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => t('chat_images.upload_error')]);
    exit;
}

$input = $_FILES['images'];
$files = [];

if (is_array($input['name'] ?? null)) {
    $total = count($input['name']);
    for ($i = 0; $i < $total; $i++) {
        $files[] = [
            'name' => $input['name'][$i] ?? '',
            'type' => $input['type'][$i] ?? '',
            'tmp_name' => $input['tmp_name'][$i] ?? '',
            'error' => $input['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'][$i] ?? 0,
        ];
    }
} else {
    $files[] = $input;
}

$uploadedCount = 0;
$errors = [];

foreach ($files as $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    try {
        chatStoreAdminTempImage($file);
        $uploadedCount++;
    } catch (RuntimeException $exception) {
        $code = $exception->getMessage();
        if ($code === 'image_too_large') {
            $errors[] = t('chat_images.upload_error_size');
        } elseif ($code === 'image_type_not_allowed') {
            $errors[] = t('chat_images.upload_error_type');
        } else {
            $errors[] = t('chat_images.upload_error');
        }
    }
}

if ($uploadedCount === 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'uploaded_count' => 0,
        'message' => $errors[0] ?? t('chat_images.upload_error'),
        'errors' => $errors,
    ]);
    exit;
}

$message = $uploadedCount === 1
    ? t('chat_images.upload_success_single')
    : str_replace('{count}', (string) $uploadedCount, t('chat_images.upload_success_many'));

if (!empty($errors)) {
    $message .= ' ' . implode(' ', array_values(array_unique($errors)));
}

echo json_encode([
    'ok' => true,
    'uploaded_count' => $uploadedCount,
    'message' => $message,
    'errors' => $errors,
]);
exit;
