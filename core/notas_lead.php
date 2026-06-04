<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";

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
    echo json_encode(["ok" => false, "error" => "TP invalido"]);
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
} elseif ($tipo !== 1) {
    $where .= " AND LOWER(TRIM(Asignado)) = LOWER(TRIM('" . $conn->real_escape_string($nombre) . "'))";
}

$resCliente = $conn->query("SELECT TP, Nombre, Apellido FROM clientes WHERE $where LIMIT 1");
if (!$resCliente || $resCliente->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Cliente no encontrado o fuera de tu alcance"]);
    exit;
}

$cliente = $resCliente->fetch_assoc();
$stmt = $conn->prepare("
    SELECT UltimaGestion, Descripcion, FechaUltimaGestion, user
    FROM notas
    WHERE TP = ?
    ORDER BY FechaUltimaGestion DESC
");
$stmt->bind_param("s", $tp);
$stmt->execute();
$result = $stmt->get_result();

$notas = [];
while ($row = $result->fetch_assoc()) {
    $notas[] = [
        "gestion" => (string) ($row["UltimaGestion"] ?? ""),
        "descripcion" => (string) ($row["Descripcion"] ?? ""),
        "fecha" => (string) ($row["FechaUltimaGestion"] ?? ""),
        "usuario" => (string) ($row["user"] ?? ""),
    ];
}
$stmt->close();

echo json_encode([
    "ok" => true,
    "cliente" => [
        "tp" => (string) ($cliente["TP"] ?? ""),
        "nombre" => trim((string) (($cliente["Nombre"] ?? "") . " " . ($cliente["Apellido"] ?? ""))),
    ],
    "total_notas" => count($notas),
    "notas" => $notas,
], JSON_UNESCAPED_UNICODE);
