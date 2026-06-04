<?php
require_once __DIR__ . '/../core/env.php';

function amiConfig(): array
{
    return [
        'host' => env('ASTERISK_HOST'),
        'port' => (int) env('ASTERISK_PORT', 5038),
        'username' => env('ASTERISK_USERNAME'),
        'secret' => env('ASTERISK_SECRET'),
    ];
}

function amiOpenConnection()
{
    $config = amiConfig();

    if (!$config['host'] || !$config['username'] || !$config['secret']) {
        throw new RuntimeException('Configuracion AMI incompleta.');
    }

    $socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, 10);
    if (!$socket) {
        throw new RuntimeException("Error al conectar con AMI: $errstr ($errno)");
    }

    amiSendAction($socket, [
        'Action' => 'Login',
        'Username' => $config['username'],
        'Secret' => $config['secret'],
        'Events' => 'off',
    ]);

    $response = amiReadBlock($socket);
    if (stripos($response, 'Response: Success') === false) {
        fclose($socket);
        throw new RuntimeException('Error de autenticacion en AMI.');
    }

    return $socket;
}

function amiSendAction($socket, array $fields): void
{
    foreach ($fields as $key => $value) {
        fputs($socket, $key . ': ' . $value . "\r\n");
    }
    fputs($socket, "\r\n");
}

function amiReadBlock($socket): string
{
    $buffer = '';

    while (!feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) {
            break;
        }

        $buffer .= $line;

        if (trim($line) === '') {
            break;
        }
    }

    return $buffer;
}

function amiFetchCoreChannels($socket): array
{
    amiSendAction($socket, ['Action' => 'CoreShowChannels']);

    $channels = [];
    $current = [];

    while (!feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) {
            break;
        }

        $line = trim($line);

        if ($line === '') {
            if (($current['Event'] ?? '') === 'CoreShowChannel') {
                $channels[] = $current;
            }
            $current = [];
            continue;
        }

        $parts = explode(': ', $line, 2);
        if (count($parts) === 2) {
            $current[$parts[0]] = $parts[1];
        }

        if (($current['Event'] ?? '') === 'CoreShowChannelsComplete') {
            break;
        }
    }

    return $channels;
}

function normalizeExtension(string $value): string
{
    return preg_replace('/\D+/', '', trim($value));
}

function channelTechnology(string $channel): string
{
    $parts = explode('/', $channel, 2);
    return strtoupper($parts[0] ?? '');
}

function channelNamePart(string $channel): string
{
    $parts = explode('/', $channel, 2);
    return $parts[1] ?? '';
}

function channelLooksLikeExtensionLeg(array $channel, string $extension): bool
{
    $name = (string) ($channel['Channel'] ?? '');
    if ($name === '') {
        return false;
    }

    $technology = channelTechnology($name);
    if (!in_array($technology, ['SIP', 'PJSIP', 'LOCAL'], true)) {
        return false;
    }

    $namePart = channelNamePart($name);
    if ($technology === 'LOCAL') {
        return str_starts_with($namePart, $extension . '@');
    }

    return str_starts_with($namePart, $extension . '-') || str_starts_with($namePart, $extension . '@') || $namePart === $extension;
}

function extensionChannelPriority(array $channel, string $extension): int
{
    $channelName = (string) ($channel['Channel'] ?? '');
    if ($channelName === '') {
        return 0;
    }

    $score = 0;

    if (channelLooksLikeExtensionLeg($channel, $extension)) {
        $score += 100;
    }

    $technology = channelTechnology($channelName);
    if (in_array($technology, ['PJSIP', 'SIP'], true)) {
        $score += 20;
    } elseif ($technology === 'LOCAL') {
        $score += 5;
    }

    $callerId = normalizeExtension((string) ($channel['CallerIDNum'] ?? ''));
    $connected = normalizeExtension((string) ($channel['ConnectedLineNum'] ?? ''));
    if ($callerId === $extension) {
        $score += 10;
    }
    if ($connected === $extension) {
        $score += 3;
    }

    return $score;
}

function findExtensionChannels(array $channels, string $extension): array
{
    $matches = [];

    foreach ($channels as $channel) {
        $channelName = (string) ($channel['Channel'] ?? '');
        if ($channelName === '') {
            continue;
        }

        $callerId = normalizeExtension((string) ($channel['CallerIDNum'] ?? ''));
        $connected = normalizeExtension((string) ($channel['ConnectedLineNum'] ?? ''));

        if (channelLooksLikeExtensionLeg($channel, $extension) || $callerId === $extension || $connected === $extension) {
            $matches[] = $channel;
        }
    }

    usort($matches, static function (array $a, array $b) use ($extension): int {
        $aChannel = (string) ($a['Channel'] ?? '');
        $bChannel = (string) ($b['Channel'] ?? '');
        $aPriority = extensionChannelPriority($a, $extension);
        $bPriority = extensionChannelPriority($b, $extension);

        if ($aPriority !== $bPriority) {
            return $bPriority <=> $aPriority;
        }

        $aLocal = channelTechnology($aChannel) === 'LOCAL';
        $bLocal = channelTechnology($bChannel) === 'LOCAL';

        if ($aLocal === $bLocal) {
            return strcmp($aChannel, $bChannel);
        }

        return $aLocal <=> $bLocal;
    });

    return $matches;
}

function findBestExtensionChannel(array $channels, string $extension): ?array
{
    $matches = findExtensionChannels($channels, $extension);
    return $matches[0] ?? null;
}

function buildSpySourceChannel(string $extension): string
{
    return 'Local/' . $extension . '@from-internal';
}

function amiLogoff($socket): void
{
    amiSendAction($socket, ['Action' => 'Logoff']);
    fclose($socket);
}

function monitorOriginateChannels(string $extension): array
{
    $context = env('ASTERISK_CONTEXT', 'from-internal');

    return [
        'Local/' . $extension . '@' . $context . '/n',
        'PJSIP/' . $extension,
        'SIP/' . $extension,
    ];
}

function originateMonitorAction($socket, string $monitorExtension, string $targetChannel, string $mode, string $callerIdLabel): bool
{
    $actionBase = 'monitor-' . strtolower($mode) . '-' . time() . '-' . mt_rand(1000, 9999);
    $data = $targetChannel . ',' . $mode;

    foreach (monitorOriginateChannels($monitorExtension) as $index => $sourceChannel) {
        amiSendAction($socket, [
            'Action' => 'Originate',
            'ActionID' => $actionBase . '-' . $index,
            'Channel' => $sourceChannel,
            'Application' => 'ChanSpy',
            'Data' => $data,
            'CallerID' => $callerIdLabel . '<' . $monitorExtension . '>',
            'Async' => 'true',
        ]);

        $response = amiReadBlock($socket);
        if (stripos($response, 'Response: Success') !== false) {
            return true;
        }
    }

    return false;
}
