<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/chat.php";

requireLogin();
requirePermission("chat");

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$messageId = (int) ($_POST['message_id'] ?? 0);

if ($userId <= 0 || $messageId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Solicitud invalida'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'edit') {
        $message = (string) ($_POST['message'] ?? '');
        $result = chatEditOwnDirectMessage($conn, $userId, $messageId, $message);
        echo json_encode([
            'ok' => true,
            'action' => 'edit',
            'message_id' => $messageId,
            'message' => $result['mensaje'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        chatDeleteOwnDirectMessage($conn, $userId, $messageId);
        echo json_encode([
            'ok' => true,
            'action' => 'delete',
            'message_id' => $messageId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Accion no valida'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No fue posible procesar la accion'], JSON_UNESCAPED_UNICODE);
}
