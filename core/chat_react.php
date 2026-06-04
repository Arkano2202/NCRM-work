<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/i18n.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat");

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesion no valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = chatGetCurrentUser($conn, $currentUserId);
if (!$currentUser) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Usuario no encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$messageType = trim((string) ($_POST['message_type'] ?? ''));
$messageId = (int) ($_POST['message_id'] ?? 0);
$emoji = trim((string) ($_POST['emoji'] ?? ''));

try {
    $reactions = chatToggleReaction($conn, $currentUser, $messageType, $messageId, $emoji);
    echo json_encode([
        'ok' => true,
        'reactions' => $reactions,
        'message_type' => $messageType,
        'message_id' => $messageId,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
exit;
