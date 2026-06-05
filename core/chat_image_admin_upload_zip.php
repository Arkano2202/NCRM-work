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

if (!isset($_FILES['zip_file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => t('chat_images.zip_error')]);
    exit;
}

try {
    $result = chatStoreAdminTempImagesFromZip($_FILES['zip_file']);
    $storedCount = (int) ($result['stored_count'] ?? 0);
    $skippedCount = (int) ($result['skipped_count'] ?? 0);

    $message = $storedCount === 1
        ? t('chat_images.zip_success_single')
        : str_replace('{count}', (string) $storedCount, t('chat_images.zip_success_many'));

    if ($skippedCount > 0) {
        $message .= ' ' . str_replace('{count}', (string) $skippedCount, t('chat_images.zip_skipped'));
    }

    echo json_encode([
        'ok' => true,
        'stored_count' => $storedCount,
        'skipped_count' => $skippedCount,
        'message' => $message,
    ]);
    exit;
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $message = match ($code) {
        'invalid_upload' => t('chat_images.zip_error_upload'),
        'zip_not_supported' => t('chat_images.zip_error_not_supported'),
        'invalid_zip' => t('chat_images.zip_error_invalid'),
        'zip_without_images' => t('chat_images.zip_error_empty'),
        default => t('chat_images.zip_error') . ' [' . $code . ']',
    };

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $message,
    ]);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => t('chat_images.zip_error') . ' [' . $exception->getMessage() . ']',
    ]);
    exit;
}
