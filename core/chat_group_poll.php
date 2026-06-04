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

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$groupTlId = (int) ($_GET['group'] ?? 0);
$markAll = isset($_GET['mark_all']) && $_GET['mark_all'] === '1';

if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesion no valida'], JSON_UNESCAPED_UNICODE);
    exit;
}

chatMaybePurge($conn);
$currentUser = chatGetCurrentUser($conn, $currentUserId);
if (!$currentUser) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Usuario no encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($markAll) {
    chatMarkVisibleGroupsSeen($conn, $currentUser);
}

$groupRooms = chatGetVisibleGroupRooms($conn, $currentUser);
$selectedGroup = null;
foreach ($groupRooms as $room) {
    if ((int) ($room['id'] ?? 0) === $groupTlId) {
        $selectedGroup = $room;
        break;
    }
}

$messages = [];
if ($selectedGroup) {
    $messages = chatGetGroupMessages($conn, $currentUser, $groupTlId);
    $groupRooms = chatGetVisibleGroupRooms($conn, $currentUser);
    foreach ($groupRooms as $room) {
        if ((int) ($room['id'] ?? 0) === $groupTlId) {
            $selectedGroup = $room;
            break;
        }
    }
}

echo json_encode([
    'ok' => true,
    'group_rooms' => $groupRooms,
    'selected_group' => $selectedGroup,
    'messages' => $messages,
], JSON_UNESCAPED_UNICODE);
