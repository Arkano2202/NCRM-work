<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";

requireLogin();
requirePermission("asignar_individual");

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);
$tps = $data["tps"] ?? [];
$usuario = trim((string) ($data["usuario"] ?? ""));

if (!is_array($tps) || empty($tps) || $usuario === "") {
    echo json_encode(["ok" => false, "error" => "Datos incompletos"], JSON_UNESCAPED_UNICODE);
    exit;
}

$tpsNormalizados = [];
foreach ($tps as $tp) {
    $tp = trim((string) $tp);
    if ($tp !== "") {
        $tpsNormalizados[$tp] = true;
    }
}
$tpsNormalizados = array_keys($tpsNormalizados);

if (empty($tpsNormalizados)) {
    echo json_encode(["ok" => false, "error" => "No hay clientes validos seleccionados"], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioSafe = $conn->real_escape_string($usuario);
$resUser = $conn->query("SELECT Tipo, pertenece FROM users WHERE Nombre = '$usuarioSafe' LIMIT 1");
if (!$resUser || $resUser->num_rows === 0) {
    echo json_encode(["ok" => false, "error" => "Usuario no encontrado"], JSON_UNESCAPED_UNICODE);
    exit;
}

$userInfo = $resUser->fetch_assoc();
$tipoDestino = (int) ($userInfo["Tipo"] ?? 0);
$perteneceUsuario = (string) ($userInfo["pertenece"] ?? "");

if ($tipoDestino === 1) {
    $perteneceUsuario = "Sin Asignar";
}

switch ($tipoDestino) {
    case 1: $estado = "Reciclado"; break;
    case 2: $estado = "Asignado"; break;
    case 3: $estado = "Convertido"; break;
    case 7: $estado = "Convergente"; break;
    default: $estado = "Asignado";
}

$tipoSesion = (int) ($_SESSION["tipo"] ?? 0);
$nombreSesion = trim((string) ($_SESSION["nombre"] ?? ""));
$userIdSesion = (int) ($_SESSION["user_id"] ?? 0);
$perteneceSesion = trim((string) ($_SESSION["pertenece"] ?? ""));
$usuarioSession = trim((string) ($_SESSION["usuario"] ?? "Sistema"));
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');

$tpSql = array_map(static fn($tp) => "'" . $conn->real_escape_string($tp) . "'", $tpsNormalizados);
$where = "TP IN (" . implode(",", $tpSql) . ")";

if (in_array($tipoSesion, [9, 10], true)) {
    $where .= " AND pertenece = '" . $conn->real_escape_string($perteneceSesion) . "'";
} elseif (in_array($tipoSesion, [4, 5, 8], true)) {
    $where .= " AND EXISTS (
        SELECT 1 FROM users u
        WHERE LOWER(TRIM(clientes.Asignado)) = LOWER(TRIM(u.Nombre))
        AND u.Grupo = '" . $conn->real_escape_string((string) $userIdSesion) . "'
    )";
} elseif ($tipoSesion !== 1) {
    $where .= " AND LOWER(TRIM(Asignado)) = LOWER(TRIM('" . $conn->real_escape_string($nombreSesion) . "'))";
}

$clientes = [];
$resClientes = $conn->query("SELECT TP, Nombre, Apellido, Asignado FROM clientes WHERE $where");
if ($resClientes) {
    while ($row = $resClientes->fetch_assoc()) {
        $clientes[] = $row;
    }
}

if (empty($clientes)) {
    echo json_encode(["ok" => false, "error" => "No se encontraron clientes dentro de tu alcance"], JSON_UNESCAPED_UNICODE);
    exit;
}

$tpAfectados = array_map(static fn($row) => "'" . $conn->real_escape_string((string) $row["TP"]) . "'", $clientes);
$perteneceSafe = $conn->real_escape_string($perteneceUsuario);
$estadoSafe = $conn->real_escape_string($estado);

$conn->begin_transaction();

try {
    $updateOk = $conn->query("
        UPDATE clientes
        SET Asignado = '$usuarioSafe', Estado = '$estadoSafe', pertenece = '$perteneceSafe', FechaAsignacion = '" . $conn->real_escape_string($fechaHoraBogota) . "'
        WHERE TP IN (" . implode(",", $tpAfectados) . ")
    ");

    if (!$updateOk) {
        throw new RuntimeException("No fue posible actualizar los clientes seleccionados.");
    }

    $stmtHistorico = $conn->prepare("
        INSERT INTO historico (tp, nombre_cliente, asignado, usuario_session, fecha_hora, accion, modulo, memo)
        VALUES (?, ?, ?, ?, ?, 'ASIGNACION', 'ASIGNAR', ?)
    ");

    if (!$stmtHistorico) {
        throw new RuntimeException("No fue posible preparar el historico.");
    }

    foreach ($clientes as $cliente) {
        $tpCliente = (string) ($cliente["TP"] ?? "");
        $nombreCompleto = trim((string) (($cliente["Nombre"] ?? "") . " " . ($cliente["Apellido"] ?? "")));
        $asignadoActual = (string) ($cliente["Asignado"] ?? "");
        $memo = "Asignado de ($asignadoActual) a ($usuario), Estado a ($estado), Pertenece a ($perteneceUsuario)";
        $stmtHistorico->bind_param("ssssss", $tpCliente, $nombreCompleto, $usuario, $usuarioSession, $fechaHoraBogota, $memo);
        if (!$stmtHistorico->execute()) {
            throw new RuntimeException("No fue posible registrar el historico de la asignacion.");
        }
    }

    $stmtHistorico->close();
    $conn->commit();

    echo json_encode([
        "ok" => true,
        "updated" => count($clientes),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
