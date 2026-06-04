<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";

requireLogin();

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data["nombre"], $data["filtros"])) {
    echo json_encode(["ok" => false, "error" => "Datos invalidos"]);
    exit;
}

$tipoConsulta = trim((string) ($data["tipo"] ?? "asignacion"));
$tiposPermitidos = [
    "asignacion" => "asignar_individual",
    "exportacion" => "exportar_leads",
];

if (!isset($tiposPermitidos[$tipoConsulta])) {
    echo json_encode(["ok" => false, "error" => "Tipo de consulta invalido"]);
    exit;
}

requirePermission($tiposPermitidos[$tipoConsulta]);

$consultaId = (int) ($data["id"] ?? 0);
$nombre = trim((string) $data["nombre"]);
$filtrosJson = json_encode($data["filtros"], JSON_UNESCAPED_UNICODE);
$usuarioId = (int) ($_SESSION["user_id"] ?? 0);
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');

if ($nombre === "" || $filtrosJson === false) {
    echo json_encode(["ok" => false, "error" => "Datos invalidos"]);
    exit;
}

$esActualizacion = $consultaId > 0;

if ($esActualizacion) {
    $stmt = $conn->prepare("
        UPDATE consultas_guardadas
        SET nombre = ?, filtros = ?
        WHERE id = ? AND usuario_id = ? AND tipo = ?
    ");
    $stmt->bind_param("ssiis", $nombre, $filtrosJson, $consultaId, $usuarioId, $tipoConsulta);
} else {
    $stmt = $conn->prepare("
        INSERT INTO consultas_guardadas (usuario_id, nombre, filtros, fecha_creacion, tipo)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $usuarioId, $nombre, $filtrosJson, $fechaHoraBogota, $tipoConsulta);
}

$ok = $stmt->execute();
$affectedRows = $stmt->affected_rows;
$stmtError = $stmt->error;
$stmt->close();

if (!$ok) {
    echo json_encode([
        "ok" => false,
        "error" => "No fue posible guardar la consulta" . ($stmtError !== "" ? ": " . $stmtError : ""),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($esActualizacion && $affectedRows === 0) {
    $stmtExiste = $conn->prepare("
        SELECT id
        FROM consultas_guardadas
        WHERE id = ? AND usuario_id = ? AND tipo = ?
        LIMIT 1
    ");
    $stmtExiste->bind_param("iis", $consultaId, $usuarioId, $tipoConsulta);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();

    if (!$existe) {
        echo json_encode([
            "ok" => false,
            "error" => "La consulta ya no existe o no pertenece a este modulo.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    "ok" => true,
    "modo" => $esActualizacion ? "actualizada" : "creada",
    "filas" => $affectedRows,
], JSON_UNESCAPED_UNICODE);
