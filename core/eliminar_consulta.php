<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";

requireLogin();

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);
$id = (int) ($data["id"] ?? 0);
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

$usuarioId = (int) ($_SESSION["user_id"] ?? 0);

$stmt = $conn->prepare("DELETE FROM consultas_guardadas WHERE id = ? AND usuario_id = ? AND tipo = ?");
$stmt->bind_param("iis", $id, $usuarioId, $tipoConsulta);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(["ok" => $ok], JSON_UNESCAPED_UNICODE);
