<?php

require_once __DIR__ . "/monitoreo_dia.php";

function cdrMensajeErrorCargaArchivo(int $codigo): string
{
    switch ($codigo) {
        case UPLOAD_ERR_OK:
            return 'Carga completada correctamente.';
        case UPLOAD_ERR_INI_SIZE:
            return 'El archivo CDR supera el limite upload_max_filesize del servidor (' . ini_get('upload_max_filesize') . ').';
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo CDR supera el limite permitido por el formulario.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo CDR solo se subio parcialmente.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se ha seleccionado ningun archivo.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'El servidor no tiene configurada la carpeta temporal para cargas.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'El servidor no pudo escribir el archivo temporal en disco.';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extension de PHP detuvo la carga del archivo CDR.';
        default:
            return 'Ocurrio un error al subir el archivo CDR (codigo ' . $codigo . ').';
    }
}

function cdrTextoASegundos(?string $valor): int
{
    return tiempoTextoASegundos((string) $valor);
}

function cdrSegundosATexto(int $segundos): string
{
    return segundosATiempoTexto($segundos);
}

function cdrNormalizarHoraTexto(?string $valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '0') {
        return '00:00:00';
    }

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
        return $valor;
    }

    if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
        return $valor . ':00';
    }

    return '00:00:00';
}

function cdrDuracionValorASegundos(?string $valor): int
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return 0;
    }

    if (preg_match('/(\d+)s/', $valor, $matches)) {
        return (int) $matches[1];
    }

    if (is_numeric($valor)) {
        return (int) $valor;
    }

    return 0;
}

function cdrDiferenciaFechaHoraSegundos(string $fecha, string $horaInicio, string $horaFin): int
{
    $inicio = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . cdrNormalizarHoraTexto($horaInicio), new DateTimeZone('America/Bogota'));
    $fin = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . cdrNormalizarHoraTexto($horaFin), new DateTimeZone('America/Bogota'));

    if (!$inicio || !$fin) {
        return 0;
    }

    return max(0, $fin->getTimestamp() - $inicio->getTimestamp());
}

function cdrCargarCsvEnTabla(mysqli $conn, array $file, string $fechaInicio, string $fechaFin): array
{
    $codigoError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($codigoError === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(cdrMensajeErrorCargaArchivo($codigoError));
    }

    if ($codigoError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(cdrMensajeErrorCargaArchivo($codigoError));
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('El archivo CDR no es valido.');
    }

    $rows = [];
    if (($handle = fopen($tmpPath, 'r')) === false) {
        throw new RuntimeException('No fue posible abrir el archivo CSV.');
    }

    while (($data = fgetcsv($handle, 0, ',')) !== false) {
        $rows[] = $data;
    }
    fclose($handle);

    if (count($rows) <= 1) {
        throw new RuntimeException('El archivo esta vacio o no contiene datos.');
    }

    $header = $rows[0];
    $dataRows = array_slice($rows, 1);
    $totalFilasOriginales = count($dataRows);
    $processedRows = [];

    foreach ($dataRows as $row) {
        $processedRow = $row;
        while (count($processedRow) < 16) {
            $processedRow[] = '';
        }

        if (count($processedRow) > 16) {
            $processedRow = array_slice($processedRow, 0, 16);
        }

        if (isset($processedRow[4]) && $processedRow[4] !== '' && preg_match('/;(\d+)/', (string) $processedRow[4], $matches)) {
            $processedRow[5] = $matches[1];
        }

        if (isset($processedRow[8]) && $processedRow[8] !== '') {
            $duracion = (string) $processedRow[8];
            if (preg_match('/(\d+)s/', $duracion, $matches)) {
                $processedRow[8] = (string) ((int) $matches[1]);
            } elseif (is_numeric($duracion)) {
                $processedRow[8] = (string) ((int) $duracion);
            }
        }

        $processedRows[] = $processedRow;
    }

    if (!$conn->query("TRUNCATE TABLE cdr_report")) {
        throw new RuntimeException('No fue posible vaciar la tabla cdr_report.');
    }

    $stmt = $conn->prepare("
        INSERT INTO cdr_report (
            fecha, origen, ring_group, destino, canal_origen, account_code,
            canal_destino, estado, duracion, unique_id, grabacion,
            cnum, cnam, outbound_cnum, did, user_field
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la insercion del CDR.');
    }

    $importedCount = 0;
    foreach ($processedRows as $data) {
        $fecha = (string) ($data[0] ?? '');
        $origen = preg_replace('/\D+/', '', trim((string) ($data[1] ?? '')));
        $ringGroup = (string) ($data[2] ?? '');
        $destino = (string) ($data[3] ?? '');
        $canalOrigen = (string) ($data[4] ?? '');
        $accountCode = (string) ($data[5] ?? '');
        $canalDestino = (string) ($data[6] ?? '');
        $estado = (string) ($data[7] ?? '');
        $duracion = (string) ($data[8] ?? '');
        $uniqueId = (string) ($data[9] ?? '');
        $grabacion = (string) ($data[10] ?? '');
        $cnum = (string) ($data[11] ?? '');
        $cnam = (string) ($data[12] ?? '');
        $outboundCnum = (string) ($data[13] ?? '');
        $did = (string) ($data[14] ?? '');
        $userField = (string) ($data[15] ?? '');

        $stmt->bind_param(
            "ssssssssssssssss",
            $fecha,
            $origen,
            $ringGroup,
            $destino,
            $canalOrigen,
            $accountCode,
            $canalDestino,
            $estado,
            $duracion,
            $uniqueId,
            $grabacion,
            $cnum,
            $cnam,
            $outboundCnum,
            $did,
            $userField
        );

        if ($stmt->execute()) {
            $importedCount++;
        }
    }
    $stmt->close();

    return [
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin,
        'filas_originales' => $totalFilasOriginales,
        'filas_procesadas' => count($processedRows),
        'importadas' => $importedCount,
        'header' => $header,
    ];
}

function cdrMaximoSegundosPorGrupoId(int $grupoId): int
{
    if ($grupoId === 2) {
        return (3 * 3600) + (5 * 60);
    }

    if ($grupoId === 1 || $grupoId === 3) {
        return (2 * 3600) + (50 * 60);
    }

    return (2 * 3600) + (50 * 60);
}

function cdrObtenerFilasReporteTm(mysqli $conn, string $fechaInicio, string $fechaFin): array
{
    $filas = [];
    $zona = new DateTimeZone('America/Bogota');
    $horaActual = (new DateTimeImmutable('now', $zona))->format('H:i:s');

    $stmt = $conn->prepare("
        SELECT
            m.usuario,
            m.fecha,
            m.h_entrada,
            m.h_salida,
            m.almuerzo,
            m.descanso,
            m.formacion,
            m.bano,
            m.t_novedad,
            m.t_novedad_a,
            u.Nombre,
            u.Ext,
            u.grupo_id
        FROM monitoreo m
        INNER JOIN users u ON u.Usuario = m.usuario
        WHERE m.fecha BETWEEN ? AND ?
        ORDER BY u.Nombre ASC, m.fecha ASC
    ");

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el cruce de monitoreo.');
    }

    $stmt->bind_param("ss", $fechaInicio, $fechaFin);
    $stmt->execute();
    $res = $stmt->get_result();
    $registros = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    if (empty($registros)) {
        throw new RuntimeException("No se encontraron registros en monitoreo para el rango seleccionado.");
    }

    $stmtTa = $conn->prepare("
        SELECT duracion
        FROM cdr_report
        WHERE account_code = '1' AND origen = ? AND DATE(fecha) = ?
    ");

    if (!$stmtTa) {
        throw new RuntimeException('No fue posible preparar la consulta de TA.');
    }

    foreach ($registros as $registro) {
        $extension = preg_replace('/\D+/', '', trim((string) ($registro['Ext'] ?? '')));
        $fecha = (string) ($registro['fecha'] ?? '');
        $entrada = cdrNormalizarHoraTexto((string) ($registro['h_entrada'] ?? '0'));
        $salidaOriginal = cdrNormalizarHoraTexto((string) ($registro['h_salida'] ?? '0'));
        $salidaCalculo = $salidaOriginal !== '00:00:00' ? $salidaOriginal : $horaActual;

        $stmtTa->bind_param("ss", $extension, $fecha);
        $stmtTa->execute();
        $resultTa = $stmtTa->get_result();
        $taSegundos = 0;
        if ($resultTa) {
            while ($rowTa = $resultTa->fetch_assoc()) {
                $taSegundos += cdrDuracionValorASegundos((string) ($rowTa['duracion'] ?? '0'));
            }
        }

        $tNovedadASegundos = cdrTextoASegundos((string) ($registro['t_novedad_a'] ?? '0'));
        $taSegundos += $tNovedadASegundos;

        $tdSegundos = 0;
        if ($entrada !== '00:00:00') {
            $tdSegundos = cdrDiferenciaFechaHoraSegundos($fecha, $entrada, $salidaCalculo);
        }

        $tAlmSegundos = cdrTextoASegundos((string) ($registro['almuerzo'] ?? '0'));
        $tDesaSegundos = cdrTextoASegundos((string) ($registro['descanso'] ?? '0'));
        $tFormaSegundos = cdrTextoASegundos((string) ($registro['formacion'] ?? '0'));
        $tBanoSegundos = cdrTextoASegundos((string) ($registro['bano'] ?? '0'));
        $tNovedadSegundos = cdrTextoASegundos((string) ($registro['t_novedad'] ?? '0'));

        $tiSegundos = $tBanoSegundos + $tFormaSegundos + $tDesaSegundos + $tAlmSegundos;
        $maximo = cdrMaximoSegundosPorGrupoId((int) ($registro['grupo_id'] ?? 0));

        if ($tiSegundos > $maximo) {
            $tmSegundos = ($tdSegundos - ($maximo + $taSegundos + $tNovedadSegundos)) + ($tiSegundos - $maximo);
        } else {
            $tmSegundos = $tdSegundos - ($tiSegundos + $taSegundos + $tNovedadSegundos);
        }

        $tmSegundos -= $tNovedadASegundos;
        if ($tmSegundos < 0) {
            $tmSegundos = 0;
        }

        $filas[] = [
            'Nombre' => (string) ($registro['Nombre'] ?? ''),
            'Extension' => $extension,
            'Fecha' => $fecha,
            'T_Bano' => cdrSegundosATexto($tBanoSegundos),
            'T_Forma' => cdrSegundosATexto($tFormaSegundos),
            'T_Alm' => cdrSegundosATexto($tAlmSegundos),
            'TD' => cdrSegundosATexto($tdSegundos),
            'T_Desa' => cdrSegundosATexto($tDesaSegundos),
            'TM' => cdrSegundosATexto($tmSegundos),
            'TA' => cdrSegundosATexto($taSegundos),
            'Ingreso' => $entrada,
            'Salida' => $salidaOriginal !== '00:00:00' ? $salidaOriginal : $salidaCalculo,
            'T_Novedad' => cdrSegundosATexto($tNovedadSegundos + $tNovedadASegundos),
        ];
    }

    $stmtTa->close();

    return $filas;
}

function cdrDescargarReporteTm(string $fechaInicio, string $fechaFin, array $filas, array $meta = []): void
{
    $nombreArchivo = "reporte_tm_{$fechaInicio}_al_{$fechaFin}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo "<h2>Reporte TM - Rango: " . htmlspecialchars($fechaInicio) . " al " . htmlspecialchars($fechaFin) . "</h2>";

    if (!empty($meta)) {
        echo "<p>Filas originales CSV: " . (int) ($meta['filas_originales'] ?? 0) . "</p>";
        echo "<p>Filas procesadas CSV: " . (int) ($meta['filas_procesadas'] ?? 0) . "</p>";
        echo "<p>Registros importados en cdr_report: " . (int) ($meta['importadas'] ?? 0) . "</p>";
    }

    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Nombre</th>";
    echo "<th>Extension</th>";
    echo "<th>Fecha</th>";
    echo "<th>T_Bano</th>";
    echo "<th>T_Forma</th>";
    echo "<th>T_Alm</th>";
    echo "<th>TD</th>";
    echo "<th>T_Desa</th>";
    echo "<th>TM</th>";
    echo "<th>TA</th>";
    echo "<th>Ingreso</th>";
    echo "<th>Salida</th>";
    echo "<th>T_Novedad</th>";
    echo "</tr>";

    foreach ($filas as $fila) {
        echo "<tr>";
        foreach (['Nombre', 'Extension', 'Fecha', 'T_Bano', 'T_Forma', 'T_Alm', 'TD', 'T_Desa', 'TM', 'TA', 'Ingreso', 'Salida', 'T_Novedad'] as $campo) {
            echo "<td>" . htmlspecialchars((string) ($fila[$campo] ?? '')) . "</td>";
        }
        echo "</tr>";
    }

    echo "</table>";
    exit;
}
