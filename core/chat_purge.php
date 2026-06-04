<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/chat.php";

chatPurgeOldData($conn);

header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    'ok' => true,
    'purged_at' => chatNowBogotaString(),
], JSON_UNESCAPED_UNICODE);
