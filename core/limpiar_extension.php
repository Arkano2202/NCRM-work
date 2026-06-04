<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/permissions.php";
require_once dirname(__DIR__) . "/monitor/common.php";

requireLogin();
requirePermission("leads");

header("Content-Type: application/json; charset=UTF-8");

function channelIdentityKeys(array $channel): array
{
    $keys = [];
    foreach (['Linkedid', 'BridgeId', 'Uniqueid'] as $field) {
        $value = trim((string) ($channel[$field] ?? ''));
        if ($value !== '') {
            $keys[$field] = $value;
        }
    }
    return $keys;
}

function findRelatedChannels(array $channels, array $seedChannels): array
{
    $related = [];
    $identityPool = [
        'Linkedid' => [],
        'BridgeId' => [],
        'Uniqueid' => [],
    ];

    foreach ($seedChannels as $channel) {
        foreach (channelIdentityKeys($channel) as $field => $value) {
            $identityPool[$field][$value] = true;
        }
    }

    foreach ($channels as $channel) {
        $channelName = (string) ($channel['Channel'] ?? '');
        if ($channelName === '') {
            continue;
        }

        foreach (channelIdentityKeys($channel) as $field => $value) {
            if (isset($identityPool[$field][$value])) {
                $related[$channelName] = $channel;
                break;
            }
        }
    }

    return array_values($related);
}

$extension = normalizeExtension((string) ($_SESSION["ext"] ?? ""));

if ($extension === '') {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "message" => "No tienes una extension valida configurada en tu sesion.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $socket = amiOpenConnection();
    $channels = amiFetchCoreChannels($socket);
    $matchedChannels = findExtensionChannels($channels, $extension);
    $channelsToHangup = findRelatedChannels($channels, $matchedChannels);

    if (count($matchedChannels) === 0) {
        amiLogoff($socket);
        echo json_encode([
            "ok" => true,
            "message" => "No habia canales activos para tu extension.",
            "extension" => $extension,
            "channels" => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $colgados = 0;
    foreach ($channelsToHangup as $channel) {
        $channelName = (string) ($channel['Channel'] ?? '');
        if ($channelName === '') {
            continue;
        }

        amiSendAction($socket, [
            'Action' => 'Hangup',
            'Channel' => $channelName,
        ]);
        amiReadBlock($socket);
        $colgados++;
    }

    $liberada = false;
    for ($i = 0; $i < 8; $i++) {
        usleep(500000);
        $channelsActuales = amiFetchCoreChannels($socket);
        $restantes = findExtensionChannels($channelsActuales, $extension);
        if (count($restantes) === 0) {
            $liberada = true;
            break;
        }
    }

    amiLogoff($socket);

    if (!$liberada) {
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "message" => "Se intentaron colgar los canales, pero la extension aun aparece ocupada. Espera un momento y vuelve a intentar.",
            "extension" => $extension,
            "channels" => $colgados,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dejamos un margen corto adicional para que Asterisk y el softphone
    // terminen de soltar la sesion antes de la siguiente marcacion.
    usleep(800000);

    echo json_encode([
        "ok" => true,
        "message" => "Extension limpiada correctamente. Ya puedes volver a marcar.",
        "extension" => $extension,
        "channels" => $colgados,
        "related_channels" => count($channelsToHangup),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
