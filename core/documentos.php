<?php

function obtenerTiposDocumento(mysqli $conn): array
{
    $tipos = [];
    $sql = "SELECT id, nombre FROM tipo_documento ORDER BY nombre ASC";
    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $tipos[] = $row;
        }
        $result->free();
    }

    return $tipos;
}

function obtenerCamposDocumentoPorTipo(mysqli $conn): array
{
    $camposPorTipo = [];
    $sql = "
        SELECT tipo_documento_id, nombre_campo
        FROM campo_documento
        ORDER BY tipo_documento_id ASC, id ASC
    ";
    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $tipoId = (int) ($row['tipo_documento_id'] ?? 0);
            if (!isset($camposPorTipo[$tipoId])) {
                $camposPorTipo[$tipoId] = [];
            }
            $camposPorTipo[$tipoId][] = (string) ($row['nombre_campo'] ?? '');
        }
        $result->free();
    }

    return $camposPorTipo;
}

function obtenerSolicitudesDocumentoUsuario(mysqli $conn, int $userId, int $limite = 12): array
{
    $solicitudes = [];
    $limite = max(1, min($limite, 50));
    $inicioDia = (new DateTimeImmutable('today', new DateTimeZone('America/Bogota')))->format('Y-m-d 00:00:00');
    $finDia = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Bogota')))->format('Y-m-d 00:00:00');

    $sql = "
        SELECT id, tipo_doc, fecha_creado, estado, hora_estado, auxiliar, causa, observacion_documento, observaciones_auxiliar
        FROM documentos
        WHERE usuario_id = ?
          AND fecha_creado >= ?
          AND fecha_creado < ?
        ORDER BY fecha_creado DESC
        LIMIT {$limite}
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $solicitudes;
    }

    $stmt->bind_param('iss', $userId, $inicioDia, $finDia);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $solicitudes[] = $row;
    }
    $stmt->close();

    return $solicitudes;
}

function obtenerSolicitudesDocumentoFloor(mysqli $conn, string $pertenece): array
{
    $solicitudes = [];
    $pertenece = trim($pertenece);
    $inicioDia = (new DateTimeImmutable('today', new DateTimeZone('America/Bogota')))->format('Y-m-d 00:00:00');
    $finDia = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Bogota')))->format('Y-m-d 00:00:00');

    if ($pertenece === '') {
        return $solicitudes;
    }

    $sql = "
        SELECT
            d.id,
            d.fecha_creado,
            d.tipo_doc,
            d.estado,
            d.hora_estado,
            d.auxiliar,
            d.causa,
            d.observaciones_auxiliar,
            d.observacion_documento,
            u.Nombre AS asesor_nombre,
            u.Usuario AS asesor_usuario,
            u.pertenece
        FROM documentos d
        INNER JOIN users u ON d.usuario_id = u.id
        WHERE d.fecha_creado >= ?
          AND d.fecha_creado < ?
          AND u.pertenece = ?
        ORDER BY
            CASE WHEN d.estado IN ('Enviado', 'Rechazado') THEN 1 ELSE 0 END,
            d.fecha_creado DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $solicitudes;
    }

    $stmt->bind_param('sss', $inicioDia, $finDia, $pertenece);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $solicitudes[] = $row;
    }
    $stmt->close();

    return $solicitudes;
}

function obtenerResumenDocumentosFloor(mysqli $conn, string $pertenece): array
{
    $summary = [
        'pending_count' => 0,
        'active_count' => 0,
        'latest_document_id' => 0,
        'latest_created_at' => '',
        'latest_advisor_name' => '',
        'latest_doc_type' => '',
        'latest_status' => '',
    ];

    $documents = obtenerSolicitudesDocumentoFloor($conn, $pertenece);
    if (empty($documents)) {
        return $summary;
    }

    foreach ($documents as $document) {
        $estado = trim((string) ($document['estado'] ?? ''));
        if ($estado === 'Pendiente') {
            $summary['pending_count']++;
        }
        if (!in_array($estado, ['Enviado', 'Rechazado'], true)) {
            $summary['active_count']++;
        }
    }

    $latest = $documents[0];
    $summary['latest_document_id'] = (int) ($latest['id'] ?? 0);
    $summary['latest_created_at'] = trim((string) ($latest['fecha_creado'] ?? ''));
    $summary['latest_advisor_name'] = trim((string) ($latest['asesor_nombre'] ?? ''));
    $summary['latest_doc_type'] = trim((string) ($latest['tipo_doc'] ?? ''));
    $summary['latest_status'] = trim((string) ($latest['estado'] ?? ''));

    return $summary;
}

function documentoVisibleParaFloor(mysqli $conn, int $documentoId, string $pertenece): bool
{
    $sql = "
        SELECT d.id
        FROM documentos d
        INNER JOIN users u ON d.usuario_id = u.id
        WHERE d.id = ?
          AND u.pertenece = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $documentoId, $pertenece);
    $stmt->execute();
    $result = $stmt->get_result();
    $visible = $result instanceof mysqli_result && $result->num_rows > 0;
    $stmt->close();

    return $visible;
}

function obtenerDetalleDocumentoFloor(mysqli $conn, int $documentoId, string $pertenece): ?array
{
    if (!documentoVisibleParaFloor($conn, $documentoId, $pertenece)) {
        return null;
    }

    $sql = "
        SELECT
            d.id,
            d.tipo_doc,
            d.estado,
            d.causa,
            d.observaciones_auxiliar,
            d.observacion_documento,
            d.hora_estado,
            d.auxiliar,
            u.Nombre AS asesor_nombre,
            u.Usuario AS asesor_usuario
        FROM documentos d
        INNER JOIN users u ON d.usuario_id = u.id
        WHERE d.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $result = $stmt->get_result();
    $detalle = $result->fetch_assoc() ?: null;
    $stmt->close();

    if ($detalle === null) {
        return null;
    }

    $detalle['campos'] = [];
    $stmtCampos = $conn->prepare("
        SELECT nombre_campo, valor
        FROM documentos_campos
        WHERE documento_id = ?
        ORDER BY id ASC
    ");

    if ($stmtCampos) {
        $stmtCampos->bind_param('i', $documentoId);
        $stmtCampos->execute();
        $resultCampos = $stmtCampos->get_result();
        while ($row = $resultCampos->fetch_assoc()) {
            $detalle['campos'][] = $row;
        }
        $stmtCampos->close();
    }

    return $detalle;
}

function estadosRevisionDocumento(): array
{
    return ['En Proceso', 'En Revision', 'Rechazado', 'Enviado'];
}

function causasRechazoDocumento(): array
{
    return [
        'Informacion Incompleta',
        'Asesor no confirma documento',
        'Solicitud Cancelada',
        'Otros',
    ];
}
