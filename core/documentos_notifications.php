<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/documentos.php";
require_once __DIR__ . "/i18n.php";

requireLogin();
requirePermission("documentos_review");

header("Content-Type: application/json; charset=UTF-8");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));

if (!in_array($tipo, [9, 10], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => t('documents.invalid_scope')], JSON_UNESCAPED_UNICODE);
    exit;
}

$summary = obtenerResumenDocumentosFloor($conn, $pertenece);

echo json_encode([
    'ok' => true,
    'summary' => $summary,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
