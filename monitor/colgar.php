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

if (!isset($_POST['extension'])) {
    http_response_code(400);
    exit("Extension no especificada.");
}

$extension = normalizeExtension((string) $_POST['extension']);

if ($extension === '') {
    http_response_code(400);
    exit('Extension invalida.');
}

try {
    $socket = amiOpenConnection();
    $channels = amiFetchCoreChannels($socket);
    $matchedChannels = findExtensionChannels($channels, $extension);

    if (count($matchedChannels) === 0) {
        amiLogoff($socket);
        echo "No hay llamada activa para la extension $extension.";
        exit;
    }

    foreach ($matchedChannels as $channel) {
        if (empty($channel['Channel'])) {
            continue;
        }

        amiSendAction($socket, [
            'Action' => 'Hangup',
            'Channel' => $channel['Channel'],
        ]);
        amiReadBlock($socket);
    }

    amiLogoff($socket);
    echo "Se colgaron " . count($matchedChannels) . " canales de la extension $extension.";
} catch (Throwable $e) {
    http_response_code(500);
    exit($e->getMessage());
}
?>
