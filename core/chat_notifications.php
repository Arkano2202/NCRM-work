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
$summary = chatGetUnreadSummary($conn, $currentUserId);

echo json_encode([
    'ok' => true,
    'summary' => $summary,
], JSON_UNESCAPED_UNICODE);
