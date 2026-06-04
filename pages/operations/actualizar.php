<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

requireLogin();
requirePermission("actualizar");

date_default_timezone_set('America/Bogota');
libxml_use_internal_errors(true);
ini_set('memory_limit', '1024M');
set_time_limit(0);

$preview = [];
$rows = [];
$error = "";
$archivoTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ncrm_update_" . session_id() . ".xlsx";

function normalizarCabeceraActualizacion(string $header): string
{
    $header = trim($header);
    $map = [
        'Pertenece' => 'pertenece',
        'pertenece' => 'pertenece',
    ];

    return $map[$header] ?? $header;
}

if (isset($_GET['plantilla']) && $_GET['plantilla'] === '1') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['TP', 'Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana', 'pertenece'],
        ['TP-000001', 'Juan', 'Perez', 'juan.perez@correo.com', '573001112233', 'Colombia', 'Campana Abril', 'Medellin'],
        ['TP-000002', 'Maria', 'Gomez', 'maria.gomez@correo.com', '525511223344', 'Mexico', 'Campana Abril', 'CDMX'],
    ], null, 'A1');

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="plantilla_actualizacion_leads.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    try {
        $archivo = $_FILES['archivo'];

        if ($archivo['error'] !== UPLOAD_ERR_OK) throw new Exception("Error al subir archivo");
        if ($archivo['size'] > 20 * 1024 * 1024) throw new Exception("Archivo muy grande (max 20MB)");

        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls'], true)) throw new Exception("Solo archivos Excel (.xlsx, .xls)");

        move_uploaded_file($archivo['tmp_name'], $archivoTemp);

        $reader = IOFactory::createReader(IOFactory::identify($archivoTemp));
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($archivoTemp);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        $headers = array_map('normalizarCabeceraActualizacion', array_values($rows[1] ?? []));
        if (($headers[0] ?? '') !== 'TP') throw new Exception("La primera columna debe ser TP");

        $preview = array_slice($rows, 1, 10);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['procesar'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        if (!file_exists($archivoTemp)) throw new Exception("Archivo no encontrado");

        $reader = IOFactory::createReader(IOFactory::identify($archivoTemp));
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($archivoTemp);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        $headers = array_map('normalizarCabeceraActualizacion', array_values($rows[1] ?? []));
        $campos = array_slice($headers, 1);
        $columnasActualizables = array_values(array_filter($campos, static fn ($campo) => $campo !== '' && $campo !== 'FechaAsignacion'));

        $procesados = 0;
        $actualizados = 0;
        $rechazados = [];
        $filasActualizadasExport = [];
        $usuarioSession = $conn->real_escape_string($_SESSION['usuario'] ?? 'Sistema');
        $fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
        $filasPendientes = [];
        $tpsSolicitados = [];
        $numerosSolicitados = [];
        $numeroFrecuencia = [];

        foreach ($rows as $index => $row) {
            if ($index == 1) continue;

            $tp = trim((string) ($row['A'] ?? ''));
            if ($tp === '') continue;

            $filaData = ['row' => $row, 'line' => $index];
            $filasPendientes[$tp] = $filaData;
            $tpsSolicitados[$tp] = true;

            $indiceNumero = array_search('Numero', $campos, true);
            if ($indiceNumero !== false) {
                $colNumero = chr(66 + $indiceNumero);
                $numeroNuevo = preg_replace('/\D+/', '', trim((string) ($row[$colNumero] ?? '')));
                if ($numeroNuevo !== '') {
                    $numerosSolicitados[$numeroNuevo] = true;
                    $numeroFrecuencia[$numeroNuevo] = ($numeroFrecuencia[$numeroNuevo] ?? 0) + 1;
                }
            }
        }

        $clientesByTp = [];
        $tpList = array_keys($tpsSolicitados);
        $columnasConsulta = array_unique(array_merge(
            ['TP', 'Nombre', 'Apellido', 'Asignado'],
            $columnasActualizables
        ));
        $columnasSql = implode(',', array_map(static fn ($col) => "`$col`", $columnasConsulta));

        foreach (array_chunk($tpList, 500) as $chunk) {
            $chunkEscapado = array_map(static fn ($tpValue) => "'" . $conn->real_escape_string($tpValue) . "'", $chunk);
            $sqlChunk = "SELECT $columnasSql FROM clientes WHERE TP IN (" . implode(',', $chunkEscapado) . ")";
            $resultChunk = $conn->query($sqlChunk);
            if ($resultChunk) {
                while ($cliente = $resultChunk->fetch_assoc()) {
                    $clientesByTp[$cliente['TP']] = $cliente;
                }
            }
        }

        $duenosNumero = [];
        $numeroList = array_keys($numerosSolicitados);
        foreach (array_chunk($numeroList, 500) as $chunk) {
            $chunkEscapado = array_map(static fn ($numero) => "'" . $conn->real_escape_string($numero) . "'", $chunk);
            $sqlNumeros = "SELECT TP, Numero FROM clientes WHERE Numero IN (" . implode(',', $chunkEscapado) . ")";
            $resultNumeros = $conn->query($sqlNumeros);
            if ($resultNumeros) {
                while ($numeroRow = $resultNumeros->fetch_assoc()) {
                    $duenosNumero[$numeroRow['Numero']] = $numeroRow['TP'];
                }
            }
        }

        $conn->begin_transaction();
        $historicoBatch = [];
        $updatesPendientes = [];

        foreach ($filasPendientes as $tp => $filaData) {
            $row = $filaData['row'];
            $clienteActual = $clientesByTp[$tp] ?? null;

            if ($clienteActual === null) {
                $rechazados[] = [$tp, 'No existe'];
                continue;
            }

            $procesados++;
            $updates = [];
            $cambios = [];
            $valoresActualizados = [];

            foreach ($campos as $i => $campo) {
                $col = chr(66 + $i);
                $valorNuevo = trim((string) ($row[$col] ?? ''));
                if ($valorNuevo === '') continue;

                if ($campo === 'Numero') {
                    $valorNuevo = preg_replace('/\D+/', '', $valorNuevo);
                    if ($valorNuevo === '') {
                        continue;
                    }

                    if (($numeroFrecuencia[$valorNuevo] ?? 0) > 1) {
                        $rechazados[] = [$tp, 'Numero repetido dentro del archivo'];
                        continue 2;
                    }

                    $propietarioNumero = $duenosNumero[$valorNuevo] ?? null;
                    if ($propietarioNumero !== null && $propietarioNumero !== $tp) {
                        $rechazados[] = [$tp, 'Numero repetido con ' . $propietarioNumero];
                        continue 2;
                    }
                }

                $valorActual = trim((string) ($clienteActual[$campo] ?? ''));
                if ($valorNuevo !== $valorActual) {
                    $valorNuevoSql = $conn->real_escape_string($valorNuevo);
                    $updates[] = "$campo = '$valorNuevoSql'";
                    $valoresActualizados[$campo] = $valorNuevoSql;
                    $cambios[] = "$campo de ($valorActual) a ($valorNuevo)";

                    if ($campo === 'Asignado') {
                        $fechaAsignacionSql = $conn->real_escape_string($fechaHoraBogota);
                        $valoresActualizados['FechaAsignacion'] = $fechaAsignacionSql;
                        $cambios[] = "FechaAsignacion a ($fechaHoraBogota)";
                    }
                }
            }

            if (empty($updates)) {
                continue;
            }

            $updatesPendientes[$tp] = $valoresActualizados;
            $filasActualizadasExport[$tp] = array_values($row);

            $nombreCompleto = trim(($clienteActual['Nombre'] ?? '') . ' ' . ($clienteActual['Apellido'] ?? ''));
            $asignado = $clienteActual['Asignado'] ?? '';
            $memo = implode(", ", $cambios);

            $historicoBatch[] = sprintf(
                "('%s','%s','%s','%s','%s','ACTUALIZACION EN BLOQUE','ACTUALIZAR','%s')",
                $conn->real_escape_string($tp),
                $conn->real_escape_string($nombreCompleto),
                $conn->real_escape_string($asignado),
                $usuarioSession,
                $conn->real_escape_string($fechaHoraBogota),
                $conn->real_escape_string($memo)
            );

            if (count($historicoBatch) >= 250) {
                $conn->query("
                    INSERT INTO historico (tp, nombre_cliente, asignado, usuario_session, fecha_hora, accion, modulo, memo)
                    VALUES " . implode(',', $historicoBatch)
                );
                $historicoBatch = [];
            }
        }

        foreach (array_chunk($updatesPendientes, 200, true) as $chunkUpdates) {
            $tpChunk = array_keys($chunkUpdates);
            $casePorCampo = [];

            foreach ($chunkUpdates as $tp => $camposActualizar) {
                $tpSafe = $conn->real_escape_string($tp);
                foreach ($camposActualizar as $campo => $valorEscapado) {
                    $casePorCampo[$campo][] = "WHEN '$tpSafe' THEN '" . $valorEscapado . "'";
                }
            }

            $setParts = [];
            foreach ($casePorCampo as $campo => $cases) {
                $setParts[] = "`$campo` = CASE `TP` " . implode(' ', $cases) . " ELSE `$campo` END";
            }

            if (empty($setParts)) {
                continue;
            }

            $tpChunkSql = implode(',', array_map(static fn ($tpValue) => "'" . $conn->real_escape_string($tpValue) . "'", $tpChunk));
            $sqlBatchUpdate = "UPDATE clientes SET " . implode(', ', $setParts) . " WHERE TP IN ($tpChunkSql)";

            if (!$conn->query($sqlBatchUpdate)) {
                throw new Exception('No se pudo actualizar el bloque de leads');
            }

            $actualizados += count($chunkUpdates);
        }

        if (!empty($historicoBatch)) {
            $conn->query("
                INSERT INTO historico (tp, nombre_cliente, asignado, usuario_session, fecha_hora, accion, modulo, memo)
                VALUES " . implode(',', $historicoBatch)
            );
        }

        $conn->commit();

        $archivoRechazados = null;
        $archivoProcesados = null;
        $uploadsDir = dirname(__DIR__, 2) . "/uploads";
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        if (!empty($rechazados)) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['TP', 'Motivo']], null, 'A1');
            $sheet->fromArray($rechazados, null, 'A2');

            $archivoRechazados = "rechazados_update_" . date("Ymd_His") . ".xlsx";
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($uploadsDir . "/" . $archivoRechazados);
        }

        if (!empty($filasActualizadasExport)) {
            $spreadsheetProcesados = new Spreadsheet();
            $sheetProcesados = $spreadsheetProcesados->getActiveSheet();
            $encabezadosOriginales = array_values($rows[1] ?? []);
            if (!empty($encabezadosOriginales)) {
                $sheetProcesados->fromArray($encabezadosOriginales, null, 'A1');
            }
            $sheetProcesados->fromArray(array_values($filasActualizadasExport), null, 'A2');

            $archivoProcesados = "procesados_update_" . date("Ymd_His") . ".xlsx";
            $writerProcesados = IOFactory::createWriter($spreadsheetProcesados, 'Xlsx');
            $writerProcesados->save($uploadsDir . "/" . $archivoProcesados);
        }

        if (file_exists($archivoTemp)) {
            unlink($archivoTemp);
        }

        echo json_encode([
            "procesados" => $procesados,
            "actualizados" => $actualizados,
            "archivo" => $archivoRechazados,
            "archivo_procesados" => $archivoProcesados
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        if ($conn instanceof mysqli && $conn->errno === 0) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actualizar Leads</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="card">

<h2>Actualizacion Masiva</h2>
<p style="color:#94a3b8;">El archivo debe iniciar con columna TP</p>

<div class="top-actions" style="margin: 16px 0 20px;">
    <div class="results-info">Usa la plantilla oficial para actualizar leads con el formato correcto. Incluye la columna extra `pertenece`.</div>
    <a href="<?= htmlspecialchars(routeUrl('update_leads', ['plantilla' => 1])) ?>" class="btn-clear">Descargar plantilla Excel</a>
</div>

<?php if ($error !== ""): ?>
<div class="alert error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="upload-box">
<input type="file" name="archivo" accept=".xlsx,.xls" required>
<span>Sube archivo Excel</span>
</div>
<button class="btn-primary">Subir Archivo</button>
</form>

<?php if (!empty($preview)): ?>
<div style="margin-top:25px;"><h3>Vista previa</h3></div>

<div class="table-container">
<table class="leads-table">
<thead>
<tr>
<?php foreach (array_values($rows[1] ?? []) as $h): ?>
<th><?= htmlspecialchars((string) $h) ?></th>
<?php endforeach; ?>
</tr>
</thead>

<tbody>
<?php foreach ($preview as $row): ?>
<tr>
<?php foreach ($row as $col): ?>
<td><?= htmlspecialchars((string) $col) ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div style="margin-top:20px;">
<button id="btnActualizar" class="btn-call" onclick="iniciarCarga()">Confirmar Actualizacion</button>
</div>

<div style="margin-top:15px;">
<div class="progress-container">
<div id="barra" class="progress-bar">0%</div>
</div>
</div>

<div id="resultadoCarga" class="resultado"></div>
<?php endif; ?>

</div>
</div>

<script>
function iniciarCarga() {
    let barra = document.getElementById("barra");
    let btn = document.getElementById("btnActualizar");
    let resultado = document.getElementById("resultadoCarga");

    btn.disabled = true;
    btn.innerText = "Procesando...";

    let progreso = 0;
    let intervalo = setInterval(() => {
        progreso += 5;
        if (progreso > 95) progreso = 95;
        barra.style.width = progreso + "%";
        barra.innerText = progreso + "%";
    }, 300);

    fetch(<?= json_encode(routeUrl('update_leads', ['procesar' => 1])) ?>)
        .then(res => res.json())
        .then(data => {
            clearInterval(intervalo);
            barra.style.width = "100%";
            barra.innerText = "100%";

            if (data.error) {
                resultado.innerHTML = `<div style="color:#ef4444;">${data.error}</div>`;
                return;
            }

            let noActualizados = data.procesados - data.actualizados;
            let botonProcesados = data.archivo_procesados ? `<a href="${<?= json_encode(appUrl('uploads')) ?>}/${data.archivo_procesados}" class="btn-primary" style="margin-top:10px;display:inline-block;margin-right:10px;">Descargar procesados</a>` : "";
            let boton = data.archivo ? `<a href="${<?= json_encode(appUrl('uploads')) ?>}/${data.archivo}" class="btn-primary" style="margin-top:10px;display:inline-block;">Descargar rechazados</a>` : "";

            resultado.innerHTML = `
                <div style="color:#22c55e;">Procesados: ${data.procesados}</div>
                <div style="color:#3b82f6;">Actualizados: ${data.actualizados}</div>
                <div style="color:#ef4444;">No actualizados: ${noActualizados}</div>
                ${botonProcesados}
                ${boton}
            `;
        });
}
</script>

</body>
</html>
