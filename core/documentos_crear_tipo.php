<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/i18n.php";

requireLogin();
requirePermission("documentos_review");

header('Content-Type: application/json; charset=UTF-8');

$tipo = (int) ($_SESSION["tipo"] ?? 0);
if (!in_array($tipo, [9, 10], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => t('documents.create_type_error')]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$nombre = trim((string) ($payload['nombre'] ?? ''));
$camposRaw = trim((string) ($payload['campos'] ?? ''));

$campos = array_values(array_filter(array_map(
    static fn($value) => trim((string) $value),
    preg_split('/\s*,\s*/', $camposRaw) ?: []
), static fn($value) => $value !== ''));

if ($nombre === '' || empty($campos)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => t('documents.create_type_invalid')]);
    exit;
}

$conn->begin_transaction();

try {
    $stmtTipo = $conn->prepare("INSERT INTO tipo_documento (nombre) VALUES (?)");
    if (!$stmtTipo) {
        throw new RuntimeException('prepare_tipo');
    }

    $stmtTipo->bind_param('s', $nombre);
    if (!$stmtTipo->execute()) {
        throw new RuntimeException('execute_tipo');
    }

    $tipoDocumentoId = (int) $stmtTipo->insert_id;
    $stmtTipo->close();

    $stmtCampo = $conn->prepare("INSERT INTO campo_documento (tipo_documento_id, nombre_campo) VALUES (?, ?)");
    if (!$stmtCampo) {
        throw new RuntimeException('prepare_campo');
    }

    foreach ($campos as $campo) {
        $stmtCampo->bind_param('is', $tipoDocumentoId, $campo);
        if (!$stmtCampo->execute()) {
            throw new RuntimeException('execute_campo');
        }
    }

    $stmtCampo->close();
    $conn->commit();

    echo json_encode([
        'ok' => true,
        'message' => t('documents.create_type_success'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => t('documents.create_type_error')]);
}
