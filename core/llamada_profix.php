<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/db.php";

requireLogin();
requirePermission("leads");

header("Content-Type: application/json; charset=UTF-8");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = trim((string) ($_SESSION["nombre"] ?? ""));
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));

$tp = trim((string) ($_GET["tp"] ?? ""));
if ($tp === "") {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "message" => "No se recibio un TP valido.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
} elseif (!in_array($tipo, [1, 9, 10, 4, 5, 8], true)) {
    $where .= " AND Asignado = '" . $conn->real_escape_string($nombre) . "'";
}

$res = $conn->query("SELECT TP, Numero FROM clientes WHERE $where LIMIT 1");
if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    echo json_encode([
        "ok" => false,
        "message" => "Cliente no encontrado o fuera de tu alcance.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $res->fetch_assoc();
$numeroOriginal = trim((string) ($row["Numero"] ?? ""));
$numeroDestino = preg_replace('/\D+/', '', $numeroOriginal);

if ($numeroDestino === "") {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "message" => "Numero destino invalido.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    "ok" => true,
    "tp" => trim((string) ($row["TP"] ?? $tp)),
    "numero" => $numeroDestino,
    "numero_original" => $numeroOriginal,
], JSON_UNESCAPED_UNICODE);
exit;
