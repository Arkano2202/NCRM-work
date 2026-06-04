<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";

requireLogin();
requirePermission("asignar_individual");

header('Content-Type: application/json; charset=UTF-8');

$sql = "SELECT Nombre FROM users WHERE Nombre IS NOT NULL AND Nombre != '' ORDER BY Nombre ASC";
$result = $conn->query($sql);
$usuarios = [];

while ($row = $result->fetch_assoc()) {
    $usuarios[] = ["nombre" => $row["Nombre"]];
}

echo json_encode($usuarios, JSON_UNESCAPED_UNICODE);
