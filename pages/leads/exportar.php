<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("exportar_leads");

$preservedSearchParams = $_GET;
unset($preservedSearchParams['search'], $preservedSearchParams['limit'], $preservedSearchParams['page']);

function renderExportHiddenQueryInputs($value, string $namePrefix = ''): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $nextName = $namePrefix === '' ? (string) $key : $namePrefix . '[' . $key . ']';
            renderExportHiddenQueryInputs($item, $nextName);
        }
        return;
    }

    if ($namePrefix === '') {
        return;
    }

    echo '<input type="hidden" name="' . htmlspecialchars($namePrefix, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . PHP_EOL;
}

function exportCachedDistinctOptions(mysqli $conn, string $campo, int $ttl = 180): array
{
    $cacheKey = 'exportar_distinct_' . $campo;
    $cache = $_SESSION[$cacheKey] ?? null;

    if (is_array($cache) && isset($cache['time'], $cache['values']) && (time() - (int) $cache['time']) < $ttl) {
        return (array) $cache['values'];
    }

    $res = $conn->query("SELECT DISTINCT $campo FROM clientes WHERE $campo IS NOT NULL AND $campo != '' ORDER BY $campo");
    $values = $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    $_SESSION[$cacheKey] = [
        'time' => time(),
        'values' => $values,
    ];

    return $values;
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$resConsultas = $conn->query("
    SELECT id, nombre, filtros, fecha_creacion
    FROM consultas_guardadas
    WHERE usuario_id = $usuarioId AND tipo = 'exportacion'
    ORDER BY fecha_creacion DESC
");

function arrayFilterIn($conn, $campo, $param) {
    if (empty($_GET[$param]) || !is_array($_GET[$param])) {
        return null;
    }

    $vals = array_map([$conn, 'real_escape_string'], $_GET[$param]);
    return "$campo IN ('" . implode("','", $vals) . "')";
}

function buildSearchFilters($conn) {
    $filtros = [];

    if (!empty($_GET['search'])) {
        $palabras = explode(',', $_GET['search']);
        $sub = [];
        foreach ($palabras as $p) {
            $p = $conn->real_escape_string(trim($p));
            if ($p === '') continue;
            $sub[] = "(TP LIKE '%$p%' OR Nombre LIKE '%$p%' OR Apellido LIKE '%$p%')";
        }
        if (!empty($sub)) $filtros[] = "(" . implode(" OR ", $sub) . ")";
    }

    foreach ([
        arrayFilterIn($conn, "Pais", "pais"),
        arrayFilterIn($conn, "Asignado", "asignado"),
        arrayFilterIn($conn, "Apellido", "apellido"),
        arrayFilterIn($conn, "Estado", "estado"),
        arrayFilterIn($conn, "UltimaGestion", "gestion"),
        arrayFilterIn($conn, "Campana", "campana"),
    ] as $filtro) {
        if ($filtro) $filtros[] = $filtro;
    }

    $fechaCreacion = trim((string) ($_GET['fecha_creacion'] ?? ''));
    if ($fechaCreacion !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCreacion)) {
        $fechaCreacionSafe = $conn->real_escape_string($fechaCreacion);
        $filtros[] = "DATE(FechaCreacion) = '$fechaCreacionSafe'";
    }

    return $filtros;
}

function exportStatusText(bool $randomMode): string
{
    return $randomMode
        ? 'Vista random activa. La consulta visible ya esta mezclada antes de descargar.'
        : 'Selecciona registros o exporta todos los filtrados.';
}

function renderExportRows(mysqli_result $result): string
{
    ob_start();
    while ($row = $result->fetch_assoc()): ?>
<tr>
<td><input type="checkbox" class="lead-selector" data-tp="<?= htmlspecialchars($row['TP']) ?>"></td>
<td><?= htmlspecialchars($row['TP']) ?></td>
<td><?= htmlspecialchars($row['Nombre']) ?></td>
<td><?= htmlspecialchars($row['Apellido']) ?></td>
<td><?= htmlspecialchars($row['Correo']) ?></td>
<td><?= htmlspecialchars($row['Numero']) ?></td>
<td><?= htmlspecialchars($row['Pais'] ?? '') ?></td>
<td><?= htmlspecialchars($row['Campana'] ?? '') ?></td>
<td><?= htmlspecialchars($row['Asignado'] ?? '') ?></td>
<td><?= htmlspecialchars($row['FechaAsignacion'] ?? '') ?></td>
<td><?= htmlspecialchars($row['Estado'] ?? '') ?></td>
<td><?= htmlspecialchars($row['UltimaGestion'] ?? '') ?></td>
<td><?= htmlspecialchars($row['FechaUltimaGestion'] ?? '') ?></td>
<td><?= htmlspecialchars($row['FechaCreacion'] ?? '') ?></td>
<td><?= htmlspecialchars($row['pertenece'] ?? '') ?></td>
</tr>
<?php endwhile;
    return (string) ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exportar'])) {
    $filtros = [];
    $exportRandom = (string) ($_REQUEST['random'] ?? '0') === '1';
    $selectedOrder = [];

    if (isset($_POST['exportar_todo'])) {
        $filtros = buildSearchFilters($conn);
    } elseif (!empty($_POST['seleccionados_json'])) {
        $seleccionados = json_decode((string) $_POST['seleccionados_json'], true);
        if (is_array($seleccionados) && !empty($seleccionados)) {
            $ids = array_map([$conn, 'real_escape_string'], $seleccionados);
            $selectedOrder = $ids;
            $filtros[] = "TP IN ('" . implode("','", $ids) . "')";
        }
    } elseif (!empty($_POST['seleccionados'])) {
        $ids = array_map([$conn, 'real_escape_string'], $_POST['seleccionados']);
        $selectedOrder = $ids;
        $filtros[] = "TP IN ('" . implode("','", $ids) . "')";
    }

    $where = !empty($filtros) ? "WHERE " . implode(" AND ", $filtros) : "";
    if (!empty($selectedOrder)) {
        $orderBySql = "ORDER BY FIELD(TP, '" . implode("','", $selectedOrder) . "')";
    } elseif ($exportRandom) {
        $randomSeedPost = trim((string) ($_REQUEST['random_seed'] ?? ''));
        if ($randomSeedPost === '') {
            $randomSeedPost = bin2hex(random_bytes(8));
        }
        $randomSeedPostSafe = $conn->real_escape_string($randomSeedPost);
        $orderBySql = "ORDER BY SHA2(CONCAT(TP, '|', '$randomSeedPostSafe'), 256)";
    } else {
        $orderBySql = "";
    }
    $sql = "SELECT TP, Nombre, Apellido, Correo, Numero, Pais, Campana, Asignado, FechaAsignacion, Estado, UltimaGestion, FechaUltimaGestion, FechaCreacion, pertenece FROM clientes $where $orderBySql";
    $res = $conn->query($sql);

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=" . ($exportRandom ? "export_random_" : "export_") . date("Ymd_His") . ".xls");

    echo "<table border='1'><tr>
        <th>TP</th><th>Nombre</th><th>Apellido</th><th>Correo</th><th>Telefono</th>
        <th>Pais</th><th>Campana</th><th>Asignado</th><th>FechaAsignacion</th><th>Estado</th><th>UltimaGestion</th><th>FechaUltimaGestion</th><th>FechaCreacion</th><th>Pertenece</th>
    </tr>";

    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        foreach (['TP', 'Nombre', 'Apellido', 'Correo', 'Numero', 'Pais', 'Campana', 'Asignado', 'FechaAsignacion', 'Estado', 'UltimaGestion', 'FechaUltimaGestion', 'FechaCreacion', 'pertenece'] as $campo) {
            echo "<td>" . htmlspecialchars((string) ($row[$campo] ?? '')) . "</td>";
        }
        echo "</tr>";
    }

    echo "</table>";
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = (int) ($_GET['limit'] ?? 20);
if ($limit <= 0 || $limit > 5000) $limit = 20;
$offset = ($page - 1) * $limit;
$randomMode = (string) ($_GET['random'] ?? '0') === '1';
$randomSeed = trim((string) ($_GET['random_seed'] ?? ''));
if ($randomMode && $randomSeed === '') {
    $randomSeed = bin2hex(random_bytes(8));
}

$sort = $_GET['sort'] ?? 'TP';
$order = strtoupper($_GET['order'] ?? 'DESC');
$allowedSort = ['TP', 'Nombre', 'Apellido', 'Numero', 'Correo', 'UltimaGestion', 'Campana'];
if (!in_array($sort, $allowedSort, true)) $sort = 'TP';
$order = $order === 'ASC' ? 'ASC' : 'DESC';

$filtros = buildSearchFilters($conn);
$where = !empty($filtros) ? "WHERE " . implode(" AND ", $filtros) : "";
$total = (int) $conn->query("SELECT COUNT(*) as total FROM clientes $where")->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($total / $limit));
$randomSeedSafe = $conn->real_escape_string($randomSeed);
$orderBySql = $randomMode
    ? "ORDER BY SHA2(CONCAT(TP, '|', '$randomSeedSafe'), 256)"
    : "ORDER BY $sort $order";
$result = $conn->query("
    SELECT TP, Nombre, Apellido, Correo, Numero, Pais, Campana, Asignado, FechaAsignacion, Estado, UltimaGestion, FechaUltimaGestion, FechaCreacion, pertenece
    FROM clientes
    $where
    $orderBySql
    LIMIT $limit OFFSET $offset
");
$tableRowsHtml = renderExportRows($result);

if (isset($_GET['ajax_export_table']) && $_GET['ajax_export_table'] === '1') {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        'ok' => true,
        'tbody_html' => $tableRowsHtml,
        'status_text' => exportStatusText($randomMode),
        'random_mode' => $randomMode,
        'random_seed' => $randomSeed,
        'query_url' => buildUrl([
            'random' => $randomMode ? 1 : 0,
            'random_seed' => $randomMode ? $randomSeed : null,
            'page' => $page,
            'ajax_export_table' => null,
        ]),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$paises = exportCachedDistinctOptions($conn, 'Pais');
$asignados = exportCachedDistinctOptions($conn, 'Asignado');
$apellidos = exportCachedDistinctOptions($conn, 'Apellido');
$estados = exportCachedDistinctOptions($conn, 'Estado');
$gestiones = exportCachedDistinctOptions($conn, 'UltimaGestion');
$campanas = exportCachedDistinctOptions($conn, 'Campana');

function chk($k, $v) {
    return (isset($_GET[$k]) && is_array($_GET[$k]) && in_array($v, $_GET[$k], true)) ? 'checked' : '';
}

function buildUrl($params = []) {
    $merged = array_merge($_GET, $params);
    foreach ($merged as $key => $value) {
        if ($value === null || $value === '') {
            unset($merged[$key]);
        }
    }
    return '?' . http_build_query($merged);
}

function sortLink($column, $label, $sort, $order) {
    $newOrder = ($sort === $column && $order === 'ASC') ? 'DESC' : 'ASC';
    $icon = $sort === $column ? ($order === 'ASC' ? ' &uarr;' : ' &darr;') : '';
    return "<a href='" . buildUrl(['sort' => $column, 'order' => $newOrder, 'random' => 0, 'random_seed' => null]) . "'>$label$icon</a>";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Exportar Leads</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.export-shell {
    display: grid;
    gap: 18px;
    width: 100%;
    min-width: 0;
}

.export-shell > * {
    width: 100%;
    min-width: 0;
    max-width: 100%;
}

.export-hero {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.export-copy {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.export-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.export-copy p {
    color: var(--muted);
}

.search-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 0;
    align-items: center;
    flex-wrap: wrap;
    padding: 18px 20px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.input-search {
    flex: 1 1 320px;
}

.input-limit {
    width: 84px;
    text-align: center;
}

.actions-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin: 0;
    padding: 18px 20px;
    background: rgba(255, 255, 255, 0.78);
    border-radius: 24px;
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.actions-left {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.actions-right {
    font-size: 0.95rem;
    color: var(--muted);
}

.actions-right strong {
    color: var(--brand);
}

.btn-export {
    background: linear-gradient(135deg, #23906a, var(--success));
    box-shadow: 0 10px 24px rgba(31, 143, 98, 0.22);
}

.export-table-card {
    padding: 0;
    overflow: hidden;
    width: 100%;
    max-width: 100%;
}

.export-table-wrap {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
}

.export-table {
    min-width: 980px;
}

.export-saved-card {
    padding: 18px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.7);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.export-saved-card h3 {
    margin-bottom: 12px;
}

.consultas-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.consulta-card {
    height: 100%;
}

@media (max-width: 860px) {
    .export-hero,
    .actions-bar {
        flex-direction: column;
        align-items: flex-start;
    }

    .search-bar > *,
    .actions-left > * {
        width: 100%;
    }

    .input-limit {
        width: 100%;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="export-shell">
<section class="export-hero">
    <div class="export-copy">
        <span class="export-kicker">Salida de datos</span>
        <h1>Exportar Leads</h1>
        <p>Filtra la base, revisa los registros visibles y exporta solo lo que necesitas.</p>
    </div>
    <div class="actions-right">Total disponible: <strong><?= number_format($total) ?></strong></div>
</section>

<section class="export-saved-card">
    <h3>Consultas Guardadas</h3>
    <div class="consultas-grid">
        <?php if ($resConsultas && $resConsultas->num_rows > 0): ?>
            <?php while ($c = $resConsultas->fetch_assoc()): ?>
            <div class="consulta-card">
                <div class="consulta-header">
                    <strong><?= htmlspecialchars($c['nombre']) ?></strong>
                    <small><?= htmlspecialchars($c['fecha_creacion']) ?></small>
                </div>
                <div class="acciones-consulta">
                    <button type="button" class="btn-play cargar-consulta" data-filtros='<?= htmlspecialchars($c["filtros"], ENT_QUOTES) ?>'>&#9654;</button>
                    <button type="button" class="btn-filter editar-consulta" data-id="<?= (int) $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>">Editar</button>
                    <button type="button" class="btn-delete eliminar-consulta" data-id="<?= (int) $c['id'] ?>">Eliminar</button>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="results-info">Aun no tienes consultas guardadas para exportacion.</div>
        <?php endif; ?>
    </div>
</section>

<form method="GET" class="search-bar">
<?php foreach ($preservedSearchParams as $paramName => $paramValue) { renderExportHiddenQueryInputs($paramValue, (string) $paramName); } ?>
<input type="text" name="search" class="input-search" placeholder="Busqueda global" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
<input type="hidden" name="page" value="1">
<input type="number" name="limit" class="input-limit" value="<?= $limit ?>">
<button class="btn-filter">Buscar</button>
</form>

<form method="GET" id="filtrosForm">
<div class="filters-container">
<div class="filter-card"><div class="filter-title">Pais</div><div class="filter-scroll"><?php foreach ($paises as $p): ?><label><input type="checkbox" name="pais[]" value="<?= htmlspecialchars($p['Pais']) ?>" <?= chk('pais', $p['Pais']) ?>><?= htmlspecialchars($p['Pais']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Asignado</div><div class="filter-scroll"><?php foreach ($asignados as $a): ?><label><input type="checkbox" name="asignado[]" value="<?= htmlspecialchars($a['Asignado']) ?>" <?= chk('asignado', $a['Asignado']) ?>><?= htmlspecialchars($a['Asignado']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Apellido</div><div class="filter-scroll"><?php foreach ($apellidos as $a): ?><label><input type="checkbox" name="apellido[]" value="<?= htmlspecialchars($a['Apellido']) ?>" <?= chk('apellido', $a['Apellido']) ?>><?= htmlspecialchars($a['Apellido']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Estado</div><div class="filter-scroll"><?php foreach ($estados as $e): ?><label><input type="checkbox" name="estado[]" value="<?= htmlspecialchars($e['Estado']) ?>" <?= chk('estado', $e['Estado']) ?>><?= htmlspecialchars($e['Estado']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Gestion</div><div class="filter-scroll"><?php foreach ($gestiones as $g): ?><label><input type="checkbox" name="gestion[]" value="<?= htmlspecialchars($g['UltimaGestion']) ?>" <?= chk('gestion', $g['UltimaGestion']) ?>><?= htmlspecialchars($g['UltimaGestion']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Campana</div><div class="filter-scroll"><?php foreach ($campanas as $c): ?><label><input type="checkbox" name="campana[]" value="<?= htmlspecialchars($c['Campana']) ?>" <?= chk('campana', $c['Campana']) ?>><?= htmlspecialchars($c['Campana']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Fecha Creacion</div><div class="filter-scroll"><input type="date" name="fecha_creacion" value="<?= htmlspecialchars((string) ($_GET['fecha_creacion'] ?? '')) ?>" class="input-search" style="width:100%;"></div></div>
</div>
</form>

<form method="POST" onsubmit="return validarExportacion()">
<input type="hidden" name="seleccionados_json" id="seleccionados_json" value="">
<input type="hidden" name="random" id="exportRandomFlag" value="<?= $randomMode ? '1' : '0' ?>">
<input type="hidden" name="random_seed" id="exportRandomSeed" value="<?= htmlspecialchars($randomSeed) ?>">
<div class="actions-bar">
<div class="actions-left">
<button type="button" onclick="guardarConsultaExportacion()" class="btn-success">Guardar Consulta</button>
<button type="submit" class="btn-filter" form="filtrosForm">Aplicar Filtros</button>
<a href="<?= htmlspecialchars(routeUrl('export_leads')) ?>" class="btn-clear">Limpiar</a>
<button type="button" id="randomizeTableButton" class="btn-filter">Random</button>
<button type="button" id="normalOrderButton" class="btn-clear"<?= $randomMode ? '' : ' hidden' ?>>Orden normal</button>
<button type="submit" name="exportar" value="1" class="btn-export">Exportar Excel</button>
</div>
<div class="actions-right" id="exportStatusText"><?= htmlspecialchars(exportStatusText($randomMode)) ?></div>
</div>

<div class="table-container export-table-card">
<div class="export-table-wrap">
<table class="leads-table export-table">
<thead>
<tr>
<th><input type="checkbox" onclick="toggleAll(this)"></th>
<th><?= sortLink('TP', 'TP', $sort, $order) ?></th>
<th><?= sortLink('Nombre', 'Nombre', $sort, $order) ?></th>
<th><?= sortLink('Apellido', 'Apellido', $sort, $order) ?></th>
<th><?= sortLink('Correo', 'Correo', $sort, $order) ?></th>
<th><?= sortLink('Numero', 'Numero', $sort, $order) ?></th>
<th>Pais</th>
<th><?= sortLink('Campana', 'Campana', $sort, $order) ?></th>
<th>Asignado</th>
<th>Fecha Asignacion</th>
<th>Estado</th>
<th><?= sortLink('UltimaGestion', 'Gestion', $sort, $order) ?></th>
<th>Fecha Gestion</th>
<th>Fecha Creacion</th>
<th>Pertenece</th>
</tr>
</thead>
<tbody id="exportTableBody"><?= $tableRowsHtml ?></tbody>
</table>
</div>
</div>

<div class="pagination">
<?php
$range = 2;
$start = max(1, $page - $range);
$end = min($totalPages, $page + $range);
?>
<?php if ($page > 1): ?><a href="<?= htmlspecialchars(buildUrl(['page' => $page - 1])) ?>">&laquo;</a><?php endif; ?>
<?php for ($i = $start; $i <= $end; $i++): ?>
<a href="<?= htmlspecialchars(buildUrl(['page' => $i])) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
<?php if ($page < $totalPages): ?><a href="<?= htmlspecialchars(buildUrl(['page' => $page + 1])) ?>">&raquo;</a><?php endif; ?>
</div>
</form>

</div>
</div>
</div>

<script>
function recolectarFiltrosExportacion() {
    const params = new URLSearchParams(new FormData(document.getElementById('filtrosForm')));
    const searchForm = document.querySelector('.search-bar');
    if (searchForm) {
        const searchInput = searchForm.querySelector('input[name="search"]');
        const limitInput = searchForm.querySelector('input[name="limit"]');

        if (searchInput) {
            params.set('search', searchInput.value || '');
        }

        if (limitInput) {
            params.set('limit', limitInput.value || '');
        }
    }

    const currentUrl = new URL(window.location.href);
    ['sort', 'order'].forEach((key) => {
        const value = currentUrl.searchParams.get(key);
        if (value !== null && value !== '') {
            params.set(key, value);
        }
    });

    const grouped = {};
    for (let [key, value] of params.entries()) {
        const indexedMatch = key.match(/^(.*)\[\d+\]$/);
        if (indexedMatch) {
            key = indexedMatch[1] + '[]';
        }

        if (key.endsWith('[]')) {
            const cleanKey = key.slice(0, -2);
            if (!Array.isArray(grouped[cleanKey])) grouped[cleanKey] = [];
            if (!grouped[cleanKey].includes(value)) {
                grouped[cleanKey].push(value);
            }
        } else if (Object.prototype.hasOwnProperty.call(grouped, key)) {
            if (!Array.isArray(grouped[key])) grouped[key] = [grouped[key]];
            if (!grouped[key].includes(value)) {
                grouped[key].push(value);
            }
        } else {
            grouped[key] = value;
        }
    }

    return grouped;
}

function aplicarFiltrosExportacion(filtros) {
    const url = new URL(window.location.href);
    url.search = '';

    Object.entries(filtros || {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach(item => url.searchParams.append(`${key}[]`, item));
        } else if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, value);
        }
    });

    window.location.href = url.toString();
}

function guardarConsultaExportacion(id = null, nombrePrefill = '') {
    let nombre = '';
    if (id) {
        nombre = String(nombrePrefill || '').trim();
        if (!nombre) return;
    } else {
        nombre = prompt('Nombre de la consulta:', nombrePrefill || '');
        if (!nombre) return;
        nombre = nombre.trim();
        if (!nombre) return;
    }

    let filtros = recolectarFiltrosExportacion();

    fetch(<?= json_encode(appUrl('core/guardar_consulta.php')) ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre, filtros, tipo: 'exportacion' })
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            location.reload();
        } else {
            alert(res.error || 'Error al guardar la consulta');
        }
    })
    .catch(() => alert('Error en la peticion'));
}

function toggleAll(s){ document.querySelectorAll('.lead-selector').forEach(c => c.checked = s.checked); }
function recolectarParametrosActualesExportacion() {
    const filtros = recolectarFiltrosExportacion();
    const params = new URLSearchParams();

    Object.entries(filtros || {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach(item => params.append(`${key}[]`, item));
        } else if (value !== null && value !== undefined && value !== '') {
            params.set(key, value);
        }
    });

    return params;
}

function actualizarVistaExportacion(data) {
    const tableBody = document.getElementById('exportTableBody');
    const status = document.getElementById('exportStatusText');
    const normalButton = document.getElementById('normalOrderButton');
    const hidden = document.getElementById('seleccionados_json');

    if (tableBody && typeof data.tbody_html === 'string') {
        tableBody.innerHTML = data.tbody_html;
    }
    if (status && typeof data.status_text === 'string') {
        status.textContent = data.status_text;
    }
    if (normalButton) {
        normalButton.hidden = !data.random_mode;
    }
    if (hidden) {
        hidden.value = '';
    }
}

async function solicitarTablaExportacionRandom(randomMode) {
    const params = recolectarParametrosActualesExportacion();
    params.set('page', '1');
    params.set('ajax_export_table', '1');

    if (randomMode) {
        params.set('random', '1');
        params.set('random_seed', `${Date.now()}_${Math.random().toString(16).slice(2)}`);
    } else {
        params.delete('random');
        params.delete('random_seed');
    }

    const response = await fetch(`<?= htmlspecialchars(routeUrl('export_leads'), ENT_QUOTES) ?>?${params.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No fue posible actualizar la tabla');
    }

    actualizarVistaExportacion(data);
    if (typeof data.query_url === 'string' && data.query_url !== '') {
        window.history.replaceState({}, '', data.query_url);
    }
}

function validarExportacion(){
    let c = [...document.querySelectorAll('.lead-selector:checked')];
    let hidden = document.getElementById("seleccionados_json");
    let postForm = document.querySelector('form[method="POST"]');
    if (hidden) {
        hidden.value = "";
    }
    if (c.length === 0) {
        if (confirm("Exportar todos los filtrados?")) {
            let i = document.createElement("input");
            i.type = "hidden"; i.name = "exportar_todo"; i.value = "1";
            postForm.appendChild(i);
            return true;
        }
        return false;
    }
    if (hidden) {
        hidden.value = JSON.stringify(c.map(item => item.dataset.tp));
    }
    return true;
}

const randomizeTableButton = document.getElementById('randomizeTableButton');
if (randomizeTableButton) {
    randomizeTableButton.addEventListener('click', async function () {
        randomizeTableButton.disabled = true;
        try {
            await solicitarTablaExportacionRandom(true);
        } catch (error) {
            alert(error.message || 'No fue posible mezclar la consulta');
        } finally {
            randomizeTableButton.disabled = false;
        }
    });
}

const normalOrderButton = document.getElementById('normalOrderButton');
if (normalOrderButton) {
    normalOrderButton.addEventListener('click', async function () {
        normalOrderButton.disabled = true;
        try {
            await solicitarTablaExportacionRandom(false);
        } catch (error) {
            alert(error.message || 'No fue posible restaurar el orden normal');
        } finally {
            normalOrderButton.disabled = false;
        }
    });
}

document.addEventListener('click', function (e) {
    if (e.target.classList.contains('cargar-consulta')) {
        const filtros = JSON.parse(e.target.getAttribute('data-filtros') || '{}');
        aplicarFiltrosExportacion(filtros);
    }

    if (e.target.classList.contains('editar-consulta')) {
        const id = e.target.getAttribute('data-id');
        const nombreActual = e.target.getAttribute('data-nombre') || '';
        let nombreNuevo = prompt('Modificar nombre de la consulta:', nombreActual);
        if (!nombreNuevo) return;

        nombreNuevo = nombreNuevo.trim();
        if (!nombreNuevo) return;

        if (!confirm('Se actualizara esta consulta con los filtros que tienes actualmente en pantalla. Deseas continuar?')) {
            return;
        }

        guardarConsultaExportacion(id, nombreNuevo);
    }

    if (e.target.classList.contains('eliminar-consulta')) {
        const id = e.target.getAttribute('data-id');
        if (!confirm('Eliminar esta consulta?')) return;

        fetch(<?= json_encode(appUrl('core/eliminar_consulta.php')) ?>, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, tipo: 'exportacion' })
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                location.reload();
            } else {
                alert(res.error || 'Error al eliminar');
            }
        })
        .catch(() => alert('Error en la peticion'));
    }
});
</script>

</body>
</html>
