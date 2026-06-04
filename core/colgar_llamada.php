<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/env.php";
require_once __DIR__ . "/db.php";

requireLogin();
requirePermission("leads");

header('Content-Type: application/json; charset=UTF-8');

$host = env('ASTERISK_HOST');
$port = (int) env('ASTERISK_PORT', 5038);
$username = env('ASTERISK_USERNAME');
$secret = env('ASTERISK_SECRET');
$extension = preg_replace('/\D+/', '', (string) ($_SESSION['ext'] ?? ''));

if ($extension === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Extension no disponible']);
    exit;
}

$socket = fsockopen($host, $port, $errno, $errstr, 10);
if (!$socket) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No conecta AMI']);
    exit;
}

fputs($socket, "Action: Login\r\n");
fputs($socket, "Username: $username\r\n");
fputs($socket, "Secret: $secret\r\n\r\n");

while ($line = fgets($socket)) {
    if (strpos($line, "Response: Success") !== false) break;
    if (strpos($line, "Response: Error") !== false) {
        fclose($socket);
        echo json_encode(['success' => false, 'error' => 'Login AMI fallido']);
        exit;
    }
}

fputs($socket, "Action: CoreShowChannels\r\n\r\n");

$channels = [];
$current = [];

while ($line = fgets($socket)) {
    $line = trim($line);

    if ($line === '') {
        if (!empty($current)) {
            $channels[] = $current;
            $current = [];
        }
        continue;
    }

    if (strpos($line, 'Event: CoreShowChannelsComplete') !== false) break;

    $parts = explode(': ', $line, 2);
    if (count($parts) === 2) {
        $current[$parts[0]] = $parts[1];
    }
}

$matched = [];
foreach ($channels as $chan) {
    foreach (['CallerIDNum', 'ConnectedLineNum', 'Channel'] as $field) {
        if (isset($chan[$field], $chan['Channel']) && strpos($chan[$field], $extension) !== false) {
            $matched[] = $chan['Channel'];
            break;
        }
    }
}

foreach ($matched as $ch) {
    fputs($socket, "Action: Hangup\r\n");
    fputs($socket, "Channel: $ch\r\n\r\n");
}

fputs($socket, "Action: Logoff\r\n\r\n");
fclose($socket);

echo json_encode([
    'success' => true,
    'extension' => $extension,
    'canales_colgados' => count($matched)
], JSON_UNESCAPED_UNICODE);
