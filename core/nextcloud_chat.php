<?php

function nextcloudDocumentRouting(): array
{
    return [
        'medellin' => [
            'base_url' => 'https://nc.schp.mx',
            'user' => 'claudflareca@gmail.com',
            'token' => 'q6aWH-MEafm-pmAye-Dxirc-k3jgC',
            'rooms' => ['vwxavtex', 'pn22e2kt'],
        ],
        'cali' => [
            'base_url' => 'https://ncc.schp.mx',
            'user' => 'claudflareca@gmail.com',
            'token' => 'zgAGT-tGLTX-cFJLg-Z7yw3-EydmL',
            'rooms' => ['e7udwu9c'],
        ],
    ];
}

function nextcloudNormalizeCity(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $replacements = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
    ];

    return strtr($value, $replacements);
}

function nextcloudConfigForCity(string $city): ?array
{
    $routing = nextcloudDocumentRouting();
    $key = nextcloudNormalizeCity($city);
    return $routing[$key] ?? null;
}

function nextcloudSendMessage(string $roomId, string $user, string $token, string $message, string $baseUrl): array
{
    $url = rtrim($baseUrl, '/') . '/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($roomId);
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['message' => $message]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $token,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $error === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $error,
        'response' => $response,
        'room_id' => $roomId,
    ];
}

function nextcloudNotifyNewDocument(string $pertenece, string $agenteNombre, string $tipoDocumento, string $fechaCreado, string $observacion = ''): array
{
    $config = nextcloudConfigForCity($pertenece);
    if ($config === null) {
        return [
            'ok' => false,
            'reason' => 'city_not_mapped',
            'results' => [],
        ];
    }

    $message = "Nuevo documento solicitado\n"
        . "Ciudad: {$pertenece}\n"
        . "Agente: {$agenteNombre}\n"
        . "Documento: {$tipoDocumento}\n"
        . "Fecha: {$fechaCreado}";

    if ($observacion !== '') {
        $message .= "\nObservacion: {$observacion}";
    }

    $results = [];
    foreach ((array) ($config['rooms'] ?? []) as $roomId) {
        $results[] = nextcloudSendMessage(
            (string) $roomId,
            (string) ($config['user'] ?? ''),
            (string) ($config['token'] ?? ''),
            $message,
            (string) ($config['base_url'] ?? 'https://nc.schp.mx')
        );
    }

    $allOk = !empty($results);
    foreach ($results as $result) {
        if (empty($result['ok'])) {
            $allOk = false;
            break;
        }
    }

    return [
        'ok' => $allOk,
        'reason' => $allOk ? null : 'send_failed',
        'results' => $results,
    ];
}
