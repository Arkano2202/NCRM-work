<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/documentos.php";
require_once __DIR__ . "/i18n.php";

requireLogin();
requirePermission("documentos_review");

header('Content-Type: application/json; charset=UTF-8');

$tipo = (int) ($_SESSION['tipo'] ?? 0);
$pertenece = trim((string) ($_SESSION['pertenece'] ?? ''));
$documentoId = (int) ($_GET['id'] ?? 0);

if (!in_array($tipo, [9, 10], true) || $documentoId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => t('documents.invalid_scope')]);
    exit;
}

$detalle = obtenerDetalleDocumentoFloor($conn, $documentoId, $pertenece);
if ($detalle === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => t('documents.invalid_scope')]);
    exit;
}

echo json_encode([
    'ok' => true,
    'document' => $detalle,
    'statuses' => estadosRevisionDocumento(),
    'causes' => causasRechazoDocumento(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
