<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/monitoreo_dia.php";

requireLogin();
requirePermission("leads");

header("Content-Type: application/json; charset=UTF-8");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$usuario = trim((string) ($_SESSION["usuario"] ?? ""));
$estadoJornada = estadoJornadaAgente($conn, $usuario, $tipo);

echo json_encode([
    "bloqueado" => (bool) ($estadoJornada["bloqueado"] ?? false),
    "mensaje" => (string) ($estadoJornada["mensaje"] ?? ""),
], JSON_UNESCAPED_UNICODE);
