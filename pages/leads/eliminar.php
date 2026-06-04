<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("eliminar_leads");

$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
$preservedSearchParams = $_GET;
unset($preservedSearchParams['search'], $preservedSearchParams['limit'], $preservedSearchParams['page']);

function renderDeleteHiddenQueryInputs($value, string $namePrefix = ''): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $nextName = $namePrefix === '' ? (string) $key : $namePrefix . '[' . $key . ']';
            renderDeleteHiddenQueryInputs($item, $nextName);
        }
        return;
    }

    if ($namePrefix === '') {
        return;
    }

    echo '<input type="hidden" name="' . htmlspecialchars($namePrefix, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . PHP_EOL;
}

function eliminarCachedDistinctOptions(mysqli $conn, string $campo, int $ttl = 180): array
{
    $cacheKey = 'eliminar_distinct_' . $campo;
    $cache = $_SESSION[$cacheKey] ?? null;

    if (is_array($cache) && isset($cache['time'], $cache['values']) && (time() - (int) $cache['time']) < $ttl) {
        return (array) $cache['values'];
    }

    $res = $conn->query("SELECT DISTINCT $campo FROM clientes WHERE $campo IS NOT NULL AND $campo != ''");
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

function eliminarCachedUserNames(mysqli $conn, string $scopeKey, string $sql, int $ttl = 180): array
{
    $cacheKey = 'eliminar_users_' . $scopeKey;
    $cache = $_SESSION[$cacheKey] ?? null;

    if (is_array($cache) && isset($cache['time'], $cache['values']) && (time() - (int) $cache['time']) < $ttl) {
        return (array) $cache['values'];
    }

    $res = $conn->query($sql);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'])) {
    $seleccionadosPost = [];

    if (!empty($_POST['seleccionados_json'])) {
        $seleccionadosJson = json_decode((string) $_POST['seleccionados_json'], true);
        if (is_array($seleccionadosJson)) {
            $seleccionadosPost = $seleccionadosJson;
        }
    } elseif (!empty($_POST['seleccionados']) && is_array($_POST['seleccionados'])) {
        $seleccionadosPost = $_POST['seleccionados'];
    }

    $seleccionadosPost = array_values(array_filter(array_map('trim', array_map('strval', $seleccionadosPost))));

    if (!empty($seleccionadosPost)) {
    $ids = array_map([$conn, 'real_escape_string'], $seleccionadosPost);
    $in = "'" . implode("','", $ids) . "'";
    $usuarioSession = $conn->real_escape_string($_SESSION['nombre'] ?? '');

    $resHist = $conn->query("SELECT TP, Nombre, Apellido, Asignado FROM clientes WHERE TP IN ($in)");
    while ($h = $resHist->fetch_assoc()) {
        $tp = $conn->real_escape_string($h['TP']);
        $nombreCliente = $conn->real_escape_string(trim($h['Nombre'] . ' ' . $h['Apellido']));
        $asignado = $conn->real_escape_string($h['Asignado']);

        $conn->query("
            INSERT INTO historico (TP, nombre_cliente, asignado, accion, usuario_session, fecha_hora, modulo, memo)
            VALUES (
                '$tp',
                '$nombreCliente',
                '$asignado',
                'ELIMINADO',
                '$usuarioSession',
                '" . $conn->real_escape_string($fechaHoraBogota) . "',
                'ELIMINAR',
                'Eliminado desde modulo eliminar'
            )
        ");
    }

    $conn->query("
        INSERT INTO clientes_eliminados (
            Nombre, Apellido, Correo, Numero, Auxiliar, Pais, TP, Campana, grupo_id, Asignado,
            FechaCreacion, FechaAsignacion, Estado, UltimaGestion, FechaUltimaGestion, fecha_eliminacion
        )
        SELECT
            Nombre, Apellido, Correo, Numero, Auxiliar, Pais, TP, Campana, grupo_id, Asignado,
            FechaCreacion, FechaAsignacion, Estado, UltimaGestion, FechaUltimaGestion, '" . $conn->real_escape_string($fechaHoraBogota) . "'
        FROM clientes
        WHERE TP IN ($in)
    ");

    $conn->query("DELETE FROM clientes WHERE TP IN ($in)");
    $redirectParams = $_GET;
    $redirectParams["msg"] = "ok";
    header("Location: " . routeUrl("delete_leads", $redirectParams));
    exit;
    }
}

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = $_SESSION["nombre"] ?? "";
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = $_SESSION["pertenece"] ?? "";
$verTelefono = (($_SESSION["phone"] ?? 0) == 1);
$verCorreo = (($_SESSION["mail"] ?? 0) == 1);

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = (int) ($_GET['limit'] ?? 10);
if ($limit <= 0 || $limit > 5000) $limit = 10;
$offset = ($page - 1) * $limit;

$sort = $_GET['sort'] ?? 'TP';
$order = strtoupper($_GET['order'] ?? 'DESC');
$allowedSort = ['TP', 'Nombre', 'Apellido', 'Numero', 'Correo', 'Estado', 'Asignado', 'Pais', 'UltimaGestion', 'FechaUltimaGestion'];
if (!in_array($sort, $allowedSort, true)) $sort = 'TP';
$order = $order === 'ASC' ? 'ASC' : 'DESC';

$sqlBase = "";
$prefix = "clientes";
$nombresEquipoTl = [];

if (in_array($tipo, [4, 5, 8], true)) {
    $resEquipoTl = $conn->query("SELECT Nombre FROM users WHERE Grupo = '" . $conn->real_escape_string((string) $userId) . "' ORDER BY Nombre");
    if ($resEquipoTl instanceof mysqli_result) {
        while ($rowEquipoTl = $resEquipoTl->fetch_assoc()) {
            $nombreIntegrante = trim((string) ($rowEquipoTl['Nombre'] ?? ''));
            if ($nombreIntegrante !== '') {
                $nombresEquipoTl[] = $nombreIntegrante;
            }
        }
        $resEquipoTl->free();
    }
}

if ($tipo == 1) {
    $sqlBase = "FROM clientes";
} elseif (in_array($tipo, [9, 10], true)) {
    $sqlBase = "FROM clientes WHERE pertenece = '" . $conn->real_escape_string($pertenece) . "'";
} elseif (in_array($tipo, [4, 5, 8], true)) {
    if (!empty($nombresEquipoTl)) {
        $equipoSql = array_map(
            static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'",
            array_values(array_unique($nombresEquipoTl))
        );
        $sqlBase = "FROM clientes WHERE Asignado IN (" . implode(', ', $equipoSql) . ")";
    } else {
        $sqlBase = "FROM clientes WHERE 1 = 0";
    }
} else {
    $sqlBase = "FROM clientes WHERE Asignado = '" . $conn->real_escape_string($nombre) . "'";
}

$filtros = [];
if (!empty($_GET['search'])) {
    $palabras = explode(',', $_GET['search']);
    $subFiltros = [];
    foreach ($palabras as $palabra) {
        $palabra = trim($palabra);
        if ($palabra === '') continue;
        $palabra = $conn->real_escape_string($palabra);

        $condiciones = [
            "$prefix.TP LIKE '%$palabra%'",
            "$prefix.Nombre LIKE '%$palabra%'",
            "$prefix.Apellido LIKE '%$palabra%'",
            "$prefix.Estado LIKE '%$palabra%'",
            "$prefix.Asignado LIKE '%$palabra%'",
            "$prefix.Pais LIKE '%$palabra%'",
            "$prefix.UltimaGestion LIKE '%$palabra%'"
        ];

        if ($verTelefono) $condiciones[] = "$prefix.Numero LIKE '%$palabra%'";
        if ($verCorreo) $condiciones[] = "$prefix.Correo LIKE '%$palabra%'";
        $subFiltros[] = "(" . implode(" OR ", $condiciones) . ")";
    }
    if (!empty($subFiltros)) $filtros[] = "(" . implode(" OR ", $subFiltros) . ")";
}

foreach ([
    ['pais', 'Pais'],
    ['asignado', 'Asignado'],
    ['apellido', 'Apellido'],
    ['gestion', 'UltimaGestion'],
    ['estado', 'Estado']
] as [$param, $campo]) {
    if (!empty($_GET[$param]) && is_array($_GET[$param])) {
        $vals = array_map([$conn, 'real_escape_string'], $_GET[$param]);
        $filtros[] = "$prefix.$campo IN ('" . implode("','", $vals) . "')";
    }
}

if (!empty($filtros)) {
    $sqlBase .= (strpos($sqlBase, 'WHERE') !== false ? " AND " : " WHERE ") . implode(" AND ", $filtros);
}

$total = (int) $conn->query("SELECT COUNT(*) as total $sqlBase")->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($total / $limit));
$sql = "SELECT $prefix.TP, $prefix.Nombre, $prefix.Apellido, $prefix.Numero, $prefix.Correo, $prefix.Estado, $prefix.Asignado, $prefix.Pais, $prefix.UltimaGestion, $prefix.FechaUltimaGestion $sqlBase ORDER BY $prefix.$sort $order LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

$paises = eliminarCachedDistinctOptions($conn, 'Pais');
$apellidos = eliminarCachedDistinctOptions($conn, 'Apellido');
$gestiones = eliminarCachedDistinctOptions($conn, 'UltimaGestion');
$estados = eliminarCachedDistinctOptions($conn, 'Estado');

if ($tipo == 1) {
    $usuarios = eliminarCachedUserNames($conn, 'all', "SELECT Nombre FROM users WHERE Nombre IS NOT NULL AND Nombre != ''");
} elseif (in_array($tipo, [9, 10], true)) {
    $usuarios = eliminarCachedUserNames($conn, 'city_' . $pertenece, "SELECT Nombre FROM users WHERE pertenece = '" . $conn->real_escape_string($pertenece) . "' AND Nombre IS NOT NULL AND Nombre != ''");
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $usuarios = array_map(static fn(string $item): array => ['Nombre' => $item], array_values(array_unique($nombresEquipoTl)));
} else {
    $usuarios = [['Nombre' => $nombre]];
}

function buildUrl($params = []) { return '?' . http_build_query(array_merge($_GET, $params)); }
function checked($key, $val) { return (isset($_GET[$key]) && in_array($val, $_GET[$key], true)) ? 'checked' : ''; }
function sortLink($column, $label, $sort, $order) {
    $newOrder = ($sort === $column && $order === 'ASC') ? 'DESC' : 'ASC';
    return "<a href='" . buildUrl(['sort' => $column, 'order' => $newOrder]) . "'>$label</a>";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Eliminar Leads</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<h1>Eliminar Leads</h1>

<form method="GET" style="margin-bottom:10px; display:flex; gap:10px;">
<?php foreach ($preservedSearchParams as $paramName => $paramValue) { renderDeleteHiddenQueryInputs($paramValue, (string) $paramName); } ?>
<input type="text" name="search" placeholder="Buscar: camilo, salome..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
<input type="hidden" name="page" value="1">
<input type="number" name="limit" value="<?= $limit ?>" style="width:80px;">
<button class="btn-filter">Buscar</button>
</form>

<form method="GET">
<?php if (!empty($_GET['search'])): ?>
<input type="hidden" name="search" value="<?= htmlspecialchars((string) $_GET['search']) ?>">
<?php endif; ?>
<input type="hidden" name="limit" value="<?= $limit ?>">
<?php if (!empty($_GET['sort'])): ?>
<input type="hidden" name="sort" value="<?= htmlspecialchars((string) $_GET['sort']) ?>">
<?php endif; ?>
<?php if (!empty($_GET['order'])): ?>
<input type="hidden" name="order" value="<?= htmlspecialchars((string) $_GET['order']) ?>">
<?php endif; ?>
<input type="hidden" name="page" value="1">
<div class="filters-container">
<div class="filter-card"><div class="filter-title">Pais</div><div class="filter-scroll"><?php foreach ($paises as $p): ?><label><input type="checkbox" name="pais[]" value="<?= htmlspecialchars($p['Pais']) ?>" <?= checked('pais', $p['Pais']) ?>><?= htmlspecialchars($p['Pais']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Asignado</div><div class="filter-scroll"><?php foreach ($usuarios as $u): ?><label><input type="checkbox" name="asignado[]" value="<?= htmlspecialchars($u['Nombre']) ?>" <?= checked('asignado', $u['Nombre']) ?>><?= htmlspecialchars($u['Nombre']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Apellido</div><div class="filter-scroll"><?php foreach ($apellidos as $a): ?><label><input type="checkbox" name="apellido[]" value="<?= htmlspecialchars($a['Apellido']) ?>" <?= checked('apellido', $a['Apellido']) ?>><?= htmlspecialchars($a['Apellido']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Gestion</div><div class="filter-scroll"><?php foreach ($gestiones as $g): ?><label><input type="checkbox" name="gestion[]" value="<?= htmlspecialchars($g['UltimaGestion']) ?>" <?= checked('gestion', $g['UltimaGestion']) ?>><?= htmlspecialchars($g['UltimaGestion']) ?></label><?php endforeach; ?></div></div>
<div class="filter-card"><div class="filter-title">Estado</div><div class="filter-scroll"><?php foreach ($estados as $e): ?><label><input type="checkbox" name="estado[]" value="<?= htmlspecialchars($e['Estado']) ?>" <?= checked('estado', $e['Estado']) ?>><?= htmlspecialchars($e['Estado']) ?></label><?php endforeach; ?></div></div>
</div>
<div style="margin-top:10px;">
<button class="btn-filter">Aplicar Filtros</button>
<a href="<?= htmlspecialchars(routeUrl('delete_leads')) ?>" class="btn-clear">Limpiar</a>
</div>
</form>

<div class="results-info">
Mostrando <strong><?= $total > 0 ? $offset + 1 : 0 ?></strong> - <strong><?= min($offset + $limit, $total) ?></strong> de <strong><?= number_format($total) ?></strong> registros
</div>

<form method="POST" onsubmit="return confirmarEliminacion()">
<input type="hidden" name="seleccionados_json" id="seleccionados_json" value="">
<button type="submit" name="eliminar" class="btn-delete">Eliminar seleccionados</button>

<table class="leads-table">
<thead>
<tr>
<th><input type="checkbox" id="selectAllEliminar"></th>
<th><?= sortLink('TP', 'TP', $sort, $order) ?></th>
<th><?= sortLink('Nombre', 'Nombre', $sort, $order) ?></th>
<th><?= sortLink('Apellido', 'Apellido', $sort, $order) ?></th>
<th><?= sortLink('Numero', 'Telefono', $sort, $order) ?></th>
<th><?= sortLink('Correo', 'Email', $sort, $order) ?></th>
<th><?= sortLink('Estado', 'Estado', $sort, $order) ?></th>
<th><?= sortLink('Asignado', 'Asignado', $sort, $order) ?></th>
<th><?= sortLink('Pais', 'Pais', $sort, $order) ?></th>
<th><?= sortLink('UltimaGestion', 'Gestion', $sort, $order) ?></th>
<th><?= sortLink('FechaUltimaGestion', 'Fecha', $sort, $order) ?></th>
</tr>
</thead>
<tbody>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
<td><input type="checkbox" value="<?= htmlspecialchars($row['TP']) ?>" class="delete-selector" data-tp="<?= htmlspecialchars($row['TP'], ENT_QUOTES) ?>"></td>
<td><?= htmlspecialchars($row['TP']) ?></td>
<td><?= htmlspecialchars($row['Nombre']) ?></td>
<td><?= htmlspecialchars($row['Apellido']) ?></td>
<td><?= $verTelefono ? htmlspecialchars($row['Numero']) : '******' ?></td>
<td><?= $verCorreo ? htmlspecialchars($row['Correo']) : '******' ?></td>
<td><?= htmlspecialchars($row['Estado']) ?></td>
<td><?= htmlspecialchars($row['Asignado']) ?></td>
<td><?= htmlspecialchars($row['Pais']) ?></td>
<td><?= htmlspecialchars($row['UltimaGestion'] ?? '') ?></td>
<td><?= htmlspecialchars($row['FechaUltimaGestion'] ?? '') ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</form>

<?php
$range = 2;
$start = max(1, $page - $range);
$end = min($totalPages, $page + $range);
?>
<div class="pagination">
<?php if ($page > 1): ?><a href="<?= buildUrl(['page' => $page - 1]) ?>">&laquo;</a><?php endif; ?>
<?php if ($start > 1): ?><a href="<?= buildUrl(['page' => 1]) ?>">1</a><?php if ($start > 2): ?><span>...</span><?php endif; ?><?php endif; ?>
<?php for ($i = $start; $i <= $end; $i++): ?><a href="<?= buildUrl(['page' => $i]) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a><?php endfor; ?>
<?php if ($end < $totalPages): ?><?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?><a href="<?= buildUrl(['page' => $totalPages]) ?>"><?= $totalPages ?></a><?php endif; ?>
<?php if ($page < $totalPages): ?><a href="<?= buildUrl(['page' => $page + 1]) ?>">&raquo;</a><?php endif; ?>
</div>

</div>
</div>

<script>
function confirmarEliminacion() {
    const seleccionados = document.querySelectorAll('.delete-selector:checked');
    if (seleccionados.length === 0) {
        alert("Debes seleccionar al menos un registro");
        return false;
    }
    const hidden = document.getElementById('seleccionados_json');
    if (hidden) {
        hidden.value = JSON.stringify([...seleccionados].map((checkbox) => checkbox.dataset.tp || checkbox.value));
    }
    return confirm("Seguro que deseas eliminar los registros seleccionados?");
}

const selectAllEliminar = document.getElementById('selectAllEliminar');
if (selectAllEliminar) {
    selectAllEliminar.addEventListener('change', function () {
        document.querySelectorAll('.delete-selector').forEach((checkbox) => {
            checkbox.checked = this.checked;
        });
    });
}
</script>

</body>
</html>
