<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/env.php";
require_once __DIR__ . "/db.php";

requireLogin();
requirePermission("leads");

function amiSendAction($socket, array $fields): void
{
    foreach ($fields as $key => $value) {
        fputs($socket, $key . ": " . $value . "\r\n");
    }
    fputs($socket, "\r\n");
}

function amiReadResponse($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) {
            break;
        }

        $response .= $line;

        if (trim($line) === '') {
            break;
        }
    }

    return $response;
}

function buildOriginateChannels(string $extension, string $context): array
{
    return [
        "Local/{$extension}@{$context}/n",
        "PJSIP/{$extension}",
        "SIP/{$extension}",
    ];
}

$host = env("ASTERISK_HOST");
$port = (int) env("ASTERISK_PORT", 5038);
$username = env("ASTERISK_USERNAME");
$secret = env("ASTERISK_SECRET");
$timeout = (int) env("ASTERISK_TIMEOUT", 30000);
$context = env("ASTERISK_CONTEXT", "from-internal");
$callerIdName = trim((string) env("ASTERISK_CALLERID", ""));
$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = trim((string) ($_SESSION["nombre"] ?? ""));
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));

$tp = trim($_GET["tp"] ?? "");
$extOrigen = preg_replace('/\D+/', '', (string) ($_SESSION["ext"] ?? ""));

if ($tp === "" || $extOrigen === "") {
    http_response_code(400);
    exit("Faltan parametros.");
}

$tpSafe = $conn->real_escape_string($tp);
$where = "TP = '$tpSafe'";

if (in_array($tipo, [9, 10], true)) {
    $where .= " AND pertenece = '" . $conn->real_escape_string($pertenece) . "'";
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $where .= " AND EXISTS (
        SELECT 1 FROM users u
        WHERE LOWER(TRIM(clientes.Asignado)) = LOWER(TRIM(u.Nombre))
        AND u.Grupo = '" . $conn->real_escape_string((string) $userId) . "'
    )";
} elseif ($tipo !== 1) {
    $where .= " AND LOWER(TRIM(Asignado)) = LOWER(TRIM('" . $conn->real_escape_string($nombre) . "'))";
}

$res = $conn->query("SELECT Numero FROM clientes WHERE $where LIMIT 1");
if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    exit("Cliente no encontrado o fuera de tu alcance.");
}

$row = $res->fetch_assoc();
$numeroDestino = preg_replace('/\D+/', '', (string) ($row["Numero"] ?? ""));
if ($numeroDestino === "") {
    http_response_code(400);
    exit("Numero destino invalido.");
}

$usuarioSesion = trim((string) ($_SESSION["usuario"] ?? ""));
$fechaHoy = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
$ultimoTpAnterior = null;
$filasActualizadasUltimoTp = 0;

if ($usuarioSesion !== '') {
    $stmtUltimoTpActual = $conn->prepare("SELECT UltimoTP FROM monitoreo WHERE usuario = ? AND fecha = ? ORDER BY id DESC LIMIT 1");
    if ($stmtUltimoTpActual) {
        $stmtUltimoTpActual->bind_param("ss", $usuarioSesion, $fechaHoy);
        $stmtUltimoTpActual->execute();
        $resUltimoTpActual = $stmtUltimoTpActual->get_result();
        $ultimoTpAnterior = $resUltimoTpActual ? (string) (($resUltimoTpActual->fetch_assoc()['UltimoTP'] ?? '')) : null;
        $stmtUltimoTpActual->close();
    }

    $stmtUltimoTp = $conn->prepare("UPDATE monitoreo SET UltimoTP = ? WHERE usuario = ? AND fecha = ?");
    if ($stmtUltimoTp) {
        $stmtUltimoTp->bind_param("sss", $tp, $usuarioSesion, $fechaHoy);
        $stmtUltimoTp->execute();
        $filasActualizadasUltimoTp = $stmtUltimoTp->affected_rows;
        $stmtUltimoTp->close();
    }
}

if (!$host || !$username || !$secret) {
    http_response_code(500);
    exit("Configuracion AMI incompleta.");
}

$socket = @fsockopen($host, $port, $errno, $errstr, 10);
if (!$socket) {
    http_response_code(500);
    exit("Error conexion AMI: $errstr ($errno)");
}

amiSendAction($socket, [
    "Action" => "Login",
    "Username" => $username,
    "Secret" => $secret,
    "Events" => "off",
]);

$loginResponse = amiReadResponse($socket);
if (stripos($loginResponse, "Response: Success") === false) {
    fclose($socket);
    http_response_code(500);
    exit("Login AMI fallido.");
}

$callerId = $callerIdName !== '' ? "\"{$callerIdName}\" <{$extOrigen}>" : "\"{$extOrigen}\" <{$extOrigen}>";
$actionIdBase = "crm-call-" . time() . "-" . mt_rand(1000, 9999);
$originateAccepted = false;
$lastResponse = '';

foreach (buildOriginateChannels($extOrigen, $context) as $index => $channel) {
    amiSendAction($socket, [
        "Action" => "Originate",
        "ActionID" => $actionIdBase . "-" . $index,
        "Channel" => $channel,
        "Exten" => $numeroDestino,
        "Context" => $context,
        "Priority" => "1",
        "CallerID" => $callerId,
        "Timeout" => (string) $timeout,
        "Async" => "true",
    ]);

    $lastResponse = amiReadResponse($socket);
    if (stripos($lastResponse, "Response: Success") !== false) {
        $originateAccepted = true;
        break;
    }
}

amiSendAction($socket, ["Action" => "Logoff"]);
fclose($socket);

header("Content-Type: application/json; charset=UTF-8");

if ($originateAccepted) {
    echo json_encode([
        "ok" => true,
        "message" => "Llamada iniciada.",
        "debug" => [
            "tp_enviado" => $tp,
            "usuario_sesion" => $usuarioSesion,
            "fecha_hoy" => $fechaHoy,
            "ultimo_tp_anterior" => $ultimoTpAnterior,
            "filas_actualizadas_ultimo_tp" => $filasActualizadasUltimoTp,
            "originate_accepted" => $originateAccepted,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($usuarioSesion !== '') {
    $stmtRestoreTp = $conn->prepare("UPDATE monitoreo SET UltimoTP = ? WHERE usuario = ? AND fecha = ?");
    if ($stmtRestoreTp) {
        $restoreTp = (string) ($ultimoTpAnterior ?? '');
        $stmtRestoreTp->bind_param("sss", $restoreTp, $usuarioSesion, $fechaHoy);
        $stmtRestoreTp->execute();
        $stmtRestoreTp->close();
    }
}

http_response_code(500);
echo json_encode([
    "ok" => false,
    "message" => "No fue posible iniciar la llamada. Revisa la extension, el contexto o el canal configurado en Asterisk.",
    "debug" => [
        "tp_enviado" => $tp,
        "usuario_sesion" => $usuarioSesion,
        "fecha_hoy" => $fechaHoy,
        "ultimo_tp_anterior" => $ultimoTpAnterior,
        "filas_actualizadas_ultimo_tp" => $filasActualizadasUltimoTp,
        "originate_accepted" => $originateAccepted,
        "last_response" => $lastResponse,
    ],
], JSON_UNESCAPED_UNICODE);
