<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

requireLogin();
requirePermission("cargar");

date_default_timezone_set('America/Bogota');
libxml_use_internal_errors(true);
ini_set('memory_limit', '1024M');
set_time_limit(0);
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');

$preview = [];
$error = "";
$archivoTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ncrm_carga_" . session_id() . ".xlsx";

function crearReaderExcel(string $archivo)
{
    $reader = IOFactory::createReader(IOFactory::identify($archivo));
    $reader->setReadDataOnly(true);
    if (method_exists($reader, 'setReadEmptyCells')) {
        $reader->setReadEmptyCells(false);
    }
    if (method_exists($reader, 'setIgnoreRowsWithNoCells')) {
        $reader->setIgnoreRowsWithNoCells(true);
    }

    return $reader;
}

function leerPreviewExcel(string $archivo, int $limite = 10): array
{
    $reader = crearReaderExcel($archivo);
    $spreadsheet = $reader->load($archivo);
    $sheet = $spreadsheet->getActiveSheet();
    $highestColumn = $sheet->getHighestDataColumn();
    $highestRow = min($sheet->getHighestDataRow(), $limite + 1);
    $rows = $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, true, true, true);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return $rows;
}

function iterarFilasExcel(string $archivo): Generator
{
    $reader = crearReaderExcel($archivo);
    $spreadsheet = $reader->load($archivo);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();

    try {
        for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
            yield $rowIndex => [
                'A' => trim((string) $sheet->getCell("A{$rowIndex}")->getFormattedValue()),
                'B' => trim((string) $sheet->getCell("B{$rowIndex}")->getFormattedValue()),
                'C' => trim((string) $sheet->getCell("C{$rowIndex}")->getFormattedValue()),
                'D' => trim((string) $sheet->getCell("D{$rowIndex}")->getFormattedValue()),
                'E' => trim((string) $sheet->getCell("E{$rowIndex}")->getFormattedValue()),
                'F' => trim((string) $sheet->getCell("F{$rowIndex}")->getFormattedValue()),
            ];
        }
    } finally {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}

function escaparSql(?string $value, mysqli $conn): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    return "'" . $conn->real_escape_string($value) . "'";
}

function obtenerNumerosExistentes(array $numeros, mysqli $conn): array
{
    $existentes = [];

    foreach (array_chunk($numeros, 1000) as $chunk) {
        if (empty($chunk)) {
            continue;
        }

        $quoted = array_map(
            static fn($numero) => "'" . $conn->real_escape_string($numero) . "'",
            $chunk
        );

        $sql = "SELECT Numero FROM clientes WHERE Numero IN (" . implode(',', $quoted) . ")";
        $res = $conn->query($sql);
        if (!$res) {
            throw new Exception("No fue posible validar numeros existentes.");
        }

        while ($row = $res->fetch_assoc()) {
            $existentes[(string) $row['Numero']] = true;
        }
    }

    return $existentes;
}

if (isset($_GET['plantilla']) && $_GET['plantilla'] === '1') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana'],
        ['Juan', 'Perez', 'juan.perez@correo.com', '573001112233', 'Colombia', 'Campana Abril'],
        ['Maria', 'Gomez', 'maria.gomez@correo.com', '525511223344', 'Mexico', 'Campana Abril'],
    ], null, 'A1');

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="plantilla_carga_leads.xlsx"');
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

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir archivo");
        }

        if ($archivo['size'] > 20 * 1024 * 1024) {
            throw new Exception("Archivo muy grande (max 20MB)");
        }

        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            throw new Exception("Solo archivos Excel (.xlsx, .xls)");
        }

        move_uploaded_file($archivo['tmp_name'], $archivoTemp);

        $rows = leerPreviewExcel($archivoTemp, 10);

        $headers = array_values($rows[1] ?? []);
        $esperados = ['Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana'];

        foreach ($esperados as $i => $campo) {
            if (!isset($headers[$i]) || trim((string) $headers[$i]) !== $campo) {
                throw new Exception("Error de estructura: se esperaba '$campo'");
            }
        }

        $preview = array_slice($rows, 1, 10, true);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['procesar'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $inicioProceso = microtime(true);

        if (!file_exists($archivoTemp)) {
            throw new Exception("Archivo no encontrado");
        }

        $rows = iterarFilasExcel($archivoTemp);

        $res = $conn->query("SELECT MAX(CAST(SUBSTRING(TP,4) AS UNSIGNED)) as max_tp FROM clientes");
        $contadorTP = (int) (($res->fetch_assoc()['max_tp'] ?? 0)) + 1;

        $batchSize = 1000;
        $values = [];
        $procesados = 0;
        $insertados = 0;
        $rechazados = [];
        $validRows = [];
        $numerosArchivo = [];
        $seenNumeros = [];
        $headers = null;

        foreach ($rows as $index => $row) {
            if ($index === 1) {
                $headers = array_values($row);
                $esperados = ['Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana'];
                foreach ($esperados as $i => $campo) {
                    if (!isset($headers[$i]) || trim((string) $headers[$i]) !== $campo) {
                        throw new Exception("Error de estructura: se esperaba '$campo'");
                    }
                }
                continue;
            }

            $nombre = (string) ($row['A'] ?? '');
            $apellido = (string) ($row['B'] ?? '');
            $correo = (string) ($row['C'] ?? '');
            $numero = preg_replace('/\D+/', '', (string) ($row['D'] ?? ''));
            $pais = (string) ($row['E'] ?? '');
            $campana = (string) ($row['F'] ?? '');

            if ($nombre === '' || $numero === '') {
                $rechazados[] = [$nombre, $apellido, $correo, $numero, $pais, $campana, 'Nombre o numero vacio'];
                continue;
            }

            if (isset($seenNumeros[$numero])) {
                $rechazados[] = [$nombre, $apellido, $correo, $numero, $pais, $campana, 'Numero duplicado en archivo'];
                continue;
            }

            $seenNumeros[$numero] = true;
            $numerosArchivo[] = $numero;
            $validRows[] = [
                'nombre' => $nombre,
                'apellido' => $apellido,
                'correo' => $correo,
                'numero' => $numero,
                'pais' => $pais,
                'campana' => $campana,
            ];
        }

        $numerosExistentes = obtenerNumerosExistentes($numerosArchivo, $conn);

        foreach ($validRows as $lead) {
            if (isset($numerosExistentes[$lead['numero']])) {
                $rechazados[] = [
                    $lead['nombre'],
                    $lead['apellido'],
                    $lead['correo'],
                    $lead['numero'],
                    $lead['pais'],
                    $lead['campana'],
                    'Numero duplicado en base de datos'
                ];
                continue;
            }

            $tp = "TP-" . str_pad((string) $contadorTP++, 6, "0", STR_PAD_LEFT);
            $values[] = "("
                . escaparSql($lead['nombre'], $conn) . ","
                . escaparSql($lead['apellido'], $conn) . ","
                . escaparSql($lead['correo'], $conn) . ","
                . escaparSql($lead['numero'], $conn) . ","
                . escaparSql($lead['pais'], $conn) . ","
                . escaparSql($tp, $conn) . ","
                . escaparSql($lead['campana'], $conn) . ","
                . "'Admin',"
                . "'Nuevo',"
                . "'" . $conn->real_escape_string($fechaHoraBogota) . "'"
                . ")";

            $procesados++;

            if (count($values) >= $batchSize) {
                $sql = "INSERT IGNORE INTO clientes (Nombre, Apellido, Correo, Numero, Pais, TP, Campana, Asignado, Estado, FechaCreacion) VALUES " . implode(",", $values);
                if (!$conn->query($sql)) {
                    throw new Exception("Error insertando lote de leads.");
                }
                $insertados += (int) $conn->affected_rows;
                $values = [];
            }
        }

        if (!empty($values)) {
            $sql = "INSERT IGNORE INTO clientes (Nombre, Apellido, Correo, Numero, Pais, TP, Campana, Asignado, Estado, FechaCreacion) VALUES " . implode(",", $values);
            if (!$conn->query($sql)) {
                throw new Exception("Error insertando lote final de leads.");
            }
            $insertados += (int) $conn->affected_rows;
        }

        $archivoRechazados = null;
        if (!empty($rechazados)) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana', 'Motivo']], null, 'A1');
            $sheet->fromArray($rechazados, null, 'A2');

            $uploadsDir = dirname(__DIR__, 2) . "/uploads";
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }

            $archivoRechazados = "rechazados_" . date("Ymd_His") . ".xlsx";
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($uploadsDir . "/" . $archivoRechazados);
        }

        if (file_exists($archivoTemp)) {
            unlink($archivoTemp);
        }

        $duracionSegundos = round(microtime(true) - $inicioProceso, 2);

        echo json_encode([
            "procesados" => $procesados,
            "insertados" => $insertados,
            "rechazados" => count($rechazados),
            "duracion" => $duracionSegundos,
            "archivo" => $archivoRechazados
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cargar Leads</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="card">
<h2>Carga Masiva de Leads</h2>
<p style="color:#94a3b8;">Sube archivos Excel (.xlsx o .xls)</p>

<div class="top-actions" style="margin: 16px 0 20px;">
    <div class="results-info">Usa la plantilla oficial para evitar errores de estructura y acelerar la validacion.</div>
    <a href="<?= htmlspecialchars(routeUrl('upload_leads', ['plantilla' => 1])) ?>" class="btn-clear">Descargar plantilla Excel</a>
</div>

<?php if ($error !== ""): ?>
<div class="alert error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="upload-box">
<input type="file" name="archivo" accept=".xlsx,.xls" required>
<span>Arrastra o selecciona tu archivo</span>
</div>
<button class="btn-primary">Subir Archivo</button>
</form>

<?php if (!empty($preview)): ?>
<div style="margin-top:25px; display:flex; justify-content:space-between;">
<h3>Vista previa</h3>
<span style="color:#94a3b8;">Primeros 10 registros</span>
</div>

<div class="table-container">
<table class="leads-table">
<thead>
<tr>
<th>#</th><th>Nombre</th><th>Apellido</th><th>Correo</th><th>Numero</th><th>Pais</th><th>Campana</th>
</tr>
</thead>
<tbody>
<?php $i = 1; foreach ($preview as $row): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['A'] ?? '') ?></td>
<td><?= htmlspecialchars($row['B'] ?? '') ?></td>
<td><?= htmlspecialchars($row['C'] ?? '') ?></td>
<td><?= htmlspecialchars($row['D'] ?? '') ?></td>
<td><?= htmlspecialchars($row['E'] ?? '') ?></td>
<td><?= htmlspecialchars($row['F'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div style="margin-top:20px;">
<button id="btnCargar" class="btn-call" onclick="iniciarCarga()">Confirmar Carga</button>
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
    let btn = document.getElementById("btnCargar");
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

    fetch(<?= json_encode(routeUrl('upload_leads', ['procesar' => 1])) ?>)
        .then(res => res.json())
        .then(data => {
            clearInterval(intervalo);
            barra.style.width = "100%";
            barra.innerText = "100%";

            if (data.error) {
                resultado.innerHTML = `<div style="color:#ef4444;">${data.error}</div>`;
                return;
            }

            let noCargados = data.rechazados ?? 0;
            let duracion = data.duracion ?? 0;
            let boton = data.archivo ? `<a href="${<?= json_encode(appUrl('uploads')) ?>}/${data.archivo}" class="btn-primary" style="margin-top:10px;display:inline-block;">Descargar rechazados</a>` : "";

            resultado.innerHTML = `
                <div style="color:#22c55e; font-weight:700;">Carga completada</div>
                <div style="margin-top:8px; color:#334155;">Procesados correctamente: <strong>${data.procesados}</strong></div>
                <div style="color:#3b82f6;">Insertados en base: <strong>${data.insertados}</strong></div>
                <div style="color:#ef4444;">Rechazados: <strong>${noCargados}</strong></div>
                <div style="color:#64748b;">Tiempo total: <strong>${duracion}s</strong></div>
                ${boton}
            `;
        });
}
</script>

</body>
</html>
