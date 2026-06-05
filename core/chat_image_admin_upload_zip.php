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
    $skippedTooLarge = (int) ($result['skipped_too_large'] ?? 0);
    $skippedInvalidType = (int) ($result['skipped_invalid_type'] ?? 0);
    $skippedInvalidContent = (int) ($result['skipped_invalid_content'] ?? 0);
    $skippedReadError = (int) ($result['skipped_read_error'] ?? 0);
    $skippedDuplicateName = (int) ($result['skipped_duplicate_name'] ?? 0);
    $failureRows = is_array($result['failure_rows'] ?? null) ? $result['failure_rows'] : [];
    $report = chatCreateAdminUploadFailuresReport($failureRows);
    $reportUrl = is_array($report) ? ($report['url'] ?? null) : null;

    $message = $storedCount === 1
        ? t('chat_images.zip_success_single')
        : str_replace('{count}', (string) $storedCount, t('chat_images.zip_success_many'));

    if ($skippedCount > 0) {
        $message .= ' ' . str_replace('{count}', (string) $skippedCount, t('chat_images.zip_skipped'));
        $parts = [];
        if ($skippedTooLarge > 0) {
            $parts[] = str_replace('{count}', (string) $skippedTooLarge, t('chat_images.zip_skipped_too_large'));
        }
        if ($skippedInvalidType > 0) {
            $parts[] = str_replace('{count}', (string) $skippedInvalidType, t('chat_images.zip_skipped_invalid_type'));
        }
        if ($skippedInvalidContent > 0) {
            $parts[] = str_replace('{count}', (string) $skippedInvalidContent, t('chat_images.zip_skipped_invalid_content'));
        }
        if ($skippedReadError > 0) {
            $parts[] = str_replace('{count}', (string) $skippedReadError, t('chat_images.zip_skipped_read_error'));
        }
        if ($skippedDuplicateName > 0) {
            $parts[] = str_replace('{count}', (string) $skippedDuplicateName, 'Duplicadas: {count}.');
        }
        if (!empty($parts)) {
            $message .= ' ' . implode(' ', $parts);
        }
    }

    $payload = [
        'ok' => true,
        'stored_count' => $storedCount,
        'skipped_count' => $skippedCount,
        'skipped_too_large' => $skippedTooLarge,
        'skipped_invalid_type' => $skippedInvalidType,
        'skipped_invalid_content' => $skippedInvalidContent,
        'skipped_read_error' => $skippedReadError,
        'skipped_duplicate_name' => $skippedDuplicateName,
        'message' => $message,
        'report_url' => $reportUrl,
    ];

    if ($storedCount === 0) {
        http_response_code(400);
        $payload['ok'] = false;
        $payload['message'] = !empty($failureRows)
            ? 'No se cargo ninguna imagen. Se descargara un reporte con el detalle.'
            : t('chat_images.zip_error_empty');
    }

    echo json_encode($payload);
    exit;
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $message = match ($code) {
        'invalid_upload' => t('chat_images.zip_error_upload'),
        'zip_not_supported' => t('chat_images.zip_error_not_supported'),
        'invalid_zip' => t('chat_images.zip_error_invalid'),
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
