<?php
require_once __DIR__ . '/../core/session_config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/i18n.php';
require_once __DIR__ . '/common.php';

requireLogin();

header('Content-Type: application/json; charset=UTF-8');

function monitorDurationToSeconds(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $parts = explode(':', $value);
    if (count($parts) !== 3) {
        return 0;
    }

    return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
}

function monitorSecondsToDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $rest = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $rest);
}

function monitorExternalCandidates(array $channels, string $extension): array
{
    $candidates = [];

    foreach ($channels as $channel) {
        foreach (['CallerIDNum', 'ConnectedLineNum'] as $field) {
            $raw = trim((string) ($channel[$field] ?? ''));
            $normalized = normalizeExtension($raw);

            if ($normalized === '' || $normalized === $extension) {
                continue;
            }

            if (strlen($normalized) < 6) {
                continue;
            }

            $candidates[$normalized] = true;
        }
    }

    return array_keys($candidates);
}

function monitorCandidateKeys(string $number): array
{
    $normalized = normalizeExtension($number);
    if ($normalized === '') {
        return [];
    }

    $keys = [$normalized];
    $length = strlen($normalized);

    if ($length >= 10) {
        $keys[] = substr($normalized, -10);
    }

    if ($length >= 8) {
        $keys[] = substr($normalized, -8);
    }

    return array_values(array_unique($keys));
}

$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';

if (!canView('monitor')) {
    http_response_code(403);
    echo json_encode(["en_llamada" => []]);
    exit;
}

try {
    $socket = amiOpenConnection();
    $channels = amiFetchCoreChannels($socket);
    amiLogoff($socket);
} catch (Throwable $e) {
    echo json_encode([
        "en_llamada" => [],
        "estados" => [],
        "debug" => $debugMode ? ['error' => $e->getMessage()] : null,
    ]);
    exit;
}

$statusByExtension = [];
$extensions = [];
$agentsByExtension = [];
$tpActualByExtension = [];
$today = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
$now = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
$monitoreoTieneUltimoTp = false;
$checkUltimoTp = $conn->query("SHOW COLUMNS FROM monitoreo LIKE 'UltimoTP'");
if ($checkUltimoTp instanceof mysqli_result) {
    $monitoreoTieneUltimoTp = $checkUltimoTp->num_rows > 0;
    $checkUltimoTp->free();
}

$usersSql = "
    SELECT u.Ext, u.Usuario, m.h_salida, m.estado, m.h_referencia" . ($monitoreoTieneUltimoTp ? ", m.UltimoTP" : "") . "
    FROM users u
    LEFT JOIN monitoreo m
        ON m.usuario = u.Usuario
        AND m.fecha = '" . $conn->real_escape_string($today) . "'
    WHERE u.Ext IS NOT NULL AND u.Ext != ''
";
$extensionsResult = $conn->query($usersSql);

if ($extensionsResult) {
    while ($row = $extensionsResult->fetch_assoc()) {
        $ext = normalizeExtension((string) ($row['Ext'] ?? ''));
        if ($ext === '') {
            continue;
        }

        $extensions[] = $ext;
        $agentsByExtension[$ext] = [
            'usuario' => (string) ($row['Usuario'] ?? ''),
            'h_salida' => trim((string) ($row['h_salida'] ?? '0')),
            'estado' => trim((string) ($row['estado'] ?? '')),
            'h_referencia' => trim((string) ($row['h_referencia'] ?? '0')),
            'UltimoTP' => trim((string) ($row['UltimoTP'] ?? '')),
        ];
    }
}

foreach (array_unique($extensions) as $extension) {
    $matches = findExtensionChannels($channels, $extension);
    if (!empty($matches)) {
        $maxDuration = 0;
        foreach ($matches as $match) {
            $maxDuration = max($maxDuration, monitorDurationToSeconds((string) ($match['Duration'] ?? '')));
        }
        $statusByExtension[(string) $extension] = [
            'code' => 'llamada',
            'label' => t('monitor.busy'),
            'tone' => 'busy',
            'duration_seconds' => $maxDuration,
            'duration_label' => monitorSecondsToDuration($maxDuration),
        ];
    } else {
        $statusByExtension[(string) $extension] = [
            'code' => 'libre',
            'label' => t('monitor.free'),
            'tone' => 'free',
            'duration_seconds' => null,
            'duration_label' => '',
        ];
        $tpActualByExtension[(string) $extension] = null;
    }

    $monitoreo = $agentsByExtension[$extension] ?? null;
    $tpActualByExtension[(string) $extension] = null;
    if (!$monitoreo || $monitoreo['usuario'] === '') {
        $statusByExtension[(string) $extension] = [
            'code' => 'sin_iniciar',
            'label' => t('times.pending'),
            'tone' => 'away',
            'duration_seconds' => null,
            'duration_label' => '',
        ];
        continue;
    }

    if ($monitoreo['h_salida'] !== '' && $monitoreo['h_salida'] !== '0') {
        $statusByExtension[(string) $extension] = [
            'code' => 'fuera_turno',
            'label' => t('times.finished'),
            'tone' => 'offline',
            'duration_seconds' => null,
            'duration_label' => '',
        ];
        continue;
    }

    $estadoMonitoreo = $monitoreo['estado'];
    if ($estadoMonitoreo !== '' && $estadoMonitoreo !== '0' && strcasecmp($estadoMonitoreo, 'Puesto') !== 0) {
        $durationSeconds = null;
        $durationLabel = '';
        $hReferencia = $monitoreo['h_referencia'] ?? '0';
        if ($hReferencia !== '' && $hReferencia !== '0') {
            $inicio = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $hReferencia, new DateTimeZone('America/Bogota'));
            if ($inicio instanceof DateTimeImmutable) {
                $durationSeconds = max(0, $now->getTimestamp() - $inicio->getTimestamp());
                $durationLabel = monitorSecondsToDuration($durationSeconds);
            }
        }
        $statusByExtension[(string) $extension] = [
            'code' => 'estado_' . strtolower(str_replace(' ', '_', $estadoMonitoreo)),
            'label' => $estadoMonitoreo,
            'tone' => 'away',
            'duration_seconds' => $durationSeconds,
            'duration_label' => $durationLabel,
        ];
    }

    if (($statusByExtension[(string) $extension]['code'] ?? '') === 'llamada') {
        $ultimoTp = trim((string) ($monitoreo['UltimoTP'] ?? ''));
        $tpActualByExtension[(string) $extension] = $ultimoTp !== '' ? $ultimoTp : null;
    }
}

$payload = [
    "en_llamada" => array_map('strval', array_keys($statusByExtension)),
    "estados" => $statusByExtension,
    "tp_actual" => $tpActualByExtension,
];

$payload["en_llamada"] = array_values(array_filter(
    array_map('strval', array_keys($statusByExtension)),
    static fn($extension) => (($statusByExtension[$extension]['code'] ?? '') === 'llamada')
));

if ($debugMode) {
    $debugExtension = '8010';
    $payload["debug"] = [
        "extension_focus" => $debugExtension,
        "extensions_from_users" => array_values(array_filter(array_unique($extensions), static fn($ext) => (string) $ext === $debugExtension)),
        "matched_extensions" => array_values(array_filter($payload["en_llamada"], static fn($ext) => $ext === $debugExtension)),
        "monitoreo" => $agentsByExtension[$debugExtension] ?? null,
        "resolved_status" => $statusByExtension[$debugExtension] ?? null,
        "channels" => array_values(array_filter(array_map(static function (array $channel): array {
            return [
                'Channel' => $channel['Channel'] ?? '',
                'CallerIDNum' => $channel['CallerIDNum'] ?? '',
                'ConnectedLineNum' => $channel['ConnectedLineNum'] ?? '',
                'Application' => $channel['Application'] ?? '',
                'ChannelStateDesc' => $channel['ChannelStateDesc'] ?? '',
                'Duration' => $channel['Duration'] ?? '',
            ];
        }, $channels), static function (array $channel) use ($debugExtension): bool {
            foreach (['Channel', 'CallerIDNum', 'ConnectedLineNum'] as $field) {
                if (strpos((string) ($channel[$field] ?? ''), $debugExtension) !== false) {
                    return true;
                }
            }

            return false;
        })),
    ];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
