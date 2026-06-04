<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat");

header("Content-Type: application/json; charset=UTF-8");

function chatNormalizeUploadedImages(array $fileBag): array
{
    if (empty($fileBag) || !isset($fileBag['name'])) {
        return [];
    }

    if (!is_array($fileBag['name'])) {
        return [ $fileBag ];
    }

    $images = [];
    $total = count($fileBag['name']);
    for ($i = 0; $i < $total; $i++) {
        $images[] = [
            'name' => $fileBag['name'][$i] ?? '',
            'type' => $fileBag['type'][$i] ?? '',
            'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
            'error' => $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $fileBag['size'][$i] ?? 0,
        ];
    }

    return $images;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$otherUserId = (int) ($_POST['with'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));
$replyType = trim((string) ($_POST['reply_type'] ?? ''));
$replyId = (int) ($_POST['reply_id'] ?? 0);
$images = chatNormalizeUploadedImages($_FILES['image'] ?? []);
$validImages = array_values(array_filter($images, static fn(array $image): bool => (int) ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
$hasImage = !empty($validImages);

if ($currentUserId <= 0 || $otherUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parametros invalidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($message === '' && !$hasImage) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Escribe un mensaje o adjunta un archivo antes de enviar'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'El mensaje no puede superar 2000 caracteres'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($validImages) > 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Solo puedes enviar hasta 5 archivos por mensaje'], JSON_UNESCAPED_UNICODE);
    exit;
}

chatMaybePurge($conn);

try {
    if ($message !== '') {
        $replyTarget = null;
        if ($replyId > 0 && in_array($replyType, ['texto', 'imagen'], true)) {
            $replyTarget = ['type' => $replyType, 'id' => $replyId];
        }
        chatSendMessage($conn, $currentUserId, $otherUserId, $message, $replyTarget);
    }

    if ($hasImage) {
        foreach ($validImages as $imageFile) {
            chatSaveImage($conn, $currentUserId, $otherUserId, $imageFile);
        }
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $reason = (string) $e->getMessage();
    $messageOut = 'No fue posible enviar el mensaje.';
    if ($reason === 'image_too_large') {
        $messageOut = 'El archivo no puede exceder 2 MB.';
    } elseif ($reason === 'image_type_not_allowed') {
        $messageOut = 'Solo se permiten imagenes o archivos PDF.';
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $messageOut,
        'reason' => $reason,
    ], JSON_UNESCAPED_UNICODE);
}
