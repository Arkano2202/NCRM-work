<?php
require_once __DIR__ . '/../core/session_config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/common.php';

requireLogin();

if (!canView('monitor')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$extensionToSpy = normalizeExtension((string) ($_POST['extensionToSpy'] ?? ''));
$spyExtension = normalizeExtension((string) ($_POST['spyExtension'] ?? ''));

if (!$extensionToSpy || !$spyExtension) {
    http_response_code(400);
    exit("Faltan datos necesarios.");
}

try {
    $socket = amiOpenConnection();
    $channels = amiFetchCoreChannels($socket);
    $targetChannel = findBestExtensionChannel($channels, $extensionToSpy);

    if (!$targetChannel || empty($targetChannel['Channel'])) {
        amiLogoff($socket);
        exit("No hay llamada activa para la extension $extensionToSpy");
    }

    $ok = originateMonitorAction($socket, $spyExtension, $targetChannel['Channel'], 'qWb', 'Whisper');

    if (!$ok) {
        amiLogoff($socket);
        http_response_code(500);
        exit("Asterisk no pudo conectar la extension $spyExtension para whisper.");
    }

    amiLogoff($socket);
} catch (Throwable $e) {
    http_response_code(500);
    exit($e->getMessage());
}

echo "Se ha iniciado el whisper en la llamada de la extension $extensionToSpy";
?>
