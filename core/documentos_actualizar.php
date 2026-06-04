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
$nombre = trim((string) ($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? ''));

if (!in_array($tipo, [9, 10], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => t('documents.review_save_error')]);
    exit;
}

$documentoId = (int) ($_POST['documento_id'] ?? 0);
$estado = trim((string) ($_POST['estado'] ?? ''));
$causa = trim((string) ($_POST['causa'] ?? ''));
$observaciones = trim((string) ($_POST['observaciones_auxiliar'] ?? ''));

if ($documentoId <= 0 || !documentoVisibleParaFloor($conn, $documentoId, $pertenece)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => t('documents.invalid_scope')]);
    exit;
}

if (!in_array($estado, estadosRevisionDocumento(), true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => t('documents.invalid_status')]);
    exit;
}

if ($estado !== 'Rechazado') {
    $causa = '';
}

$horaEstado = date('H:i:s');

$stmt = $conn->prepare("
    UPDATE documentos
    SET estado = ?, causa = ?, observaciones_auxiliar = ?, hora_estado = ?, auxiliar = ?
    WHERE id = ?
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => t('documents.review_save_error')]);
    exit;
}

$stmt->bind_param('sssssi', $estado, $causa, $observaciones, $horaEstado, $nombre, $documentoId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => t('documents.review_save_error')]);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => t('documents.review_saved'),
    'hora_estado' => $horaEstado,
    'auxiliar' => $nombre,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
