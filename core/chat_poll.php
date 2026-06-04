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
$otherUserId = (int) ($_GET['with'] ?? 0);
$markRead = !isset($_GET['mark_read']) || $_GET['mark_read'] !== '0';

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

$contacts = chatGetContactRows($conn, $currentUser);
$selectedContact = null;
foreach ($contacts as $contact) {
    if ((int) $contact['id'] === $otherUserId) {
        $selectedContact = $contact;
        break;
    }
}

$messages = [];
if ($selectedContact) {
    $messages = chatGetConversationMessages($conn, $currentUserId, $otherUserId, $markRead);
    $contacts = chatGetContactRows($conn, $currentUser);
    foreach ($contacts as $contact) {
        if ((int) $contact['id'] === $otherUserId) {
            $selectedContact = $contact;
            break;
        }
    }
}

echo json_encode([
    'ok' => true,
    'contacts' => $contacts,
    'selected_contact' => $selectedContact,
    'messages' => $messages,
], JSON_UNESCAPED_UNICODE);
