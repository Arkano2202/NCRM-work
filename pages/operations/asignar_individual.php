<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("asignar_individual");

$registrosPorPagina = isset($_GET['limite']) ? (int) $_GET['limite'] : 20;
if ($registrosPorPagina < 1) $registrosPorPagina = 20;
if ($registrosPorPagina > 5000) $registrosPorPagina = 5000;

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $registrosPorPagina;

$order = $_GET['order'] ?? 'TP';
$dir = $_GET['dir'] ?? 'DESC';
$allowed = ['TP', 'Nombre', 'Apellido', 'Numero', 'Correo', 'Pais', 'pertenece', 'Asignado', 'Estado', 'UltimaGestion', 'Campana'];
if (!in_array($order, $allowed, true)) $order = 'TP';
$dir = $dir === 'ASC' ? 'ASC' : 'DESC';

$busqueda = $_GET['busqueda'] ?? '';
if (is_array($busqueda)) $busqueda = implode(',', $busqueda);
$campoBusqueda = $_GET['campo_busqueda'] ?? 'nombre';

function buildQuery($extra = []) {
    $params = $_GET;
    foreach ($extra as $k => $v) {
        $params[$k] = $v;
    }
    return '?' . http_build_query($params);
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$resConsultas = $conn->query("
    SELECT id, nombre, filtros, fecha_creacion
    FROM consultas_guardadas
    WHERE usuario_id = $usuarioId AND tipo = 'asignacion'
    ORDER BY fecha_creacion DESC
");

$whereConditions = [];

if ($busqueda !== '') {
    $terminos = explode(',', $busqueda);

    switch ($campoBusqueda) {
        case 'tp': $campos = ['TP']; break;
        case 'nombre': $campos = ['Nombre', 'Apellido']; break;
        case 'asignado': $campos = ['Asignado']; break;
        case 'ambos': $campos = ['TP', 'Nombre']; break;
        default: $campos = ['Nombre', 'Apellido'];
    }

    $condiciones = [];
    foreach ($terminos as $t) {
        $t = trim($t);
        if ($t === '') continue;
        $t = $conn->real_escape_string($t);

        $sub = [];
        foreach ($campos as $campo) {
            $sub[] = "$campo LIKE '%$t%'";
        }
        $condiciones[] = "(" . implode(" OR ", $sub) . ")";
    }

    if (!empty($condiciones)) {
        $whereConditions[] = "(" . implode(" OR ", $condiciones) . ")";
    }
}

function filtroArray($campo, $param) {
    global $conn;
    if (isset($_GET[$param]) && is_array($_GET[$param])) {
        $vals = array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $_GET[$param]);
        return "$campo IN (" . implode(",", $vals) . ")";
    }
    return null;
}

function getOpciones($campo) {
    global $conn;
    $cacheKey = 'asignar_individual_distinct_' . $campo;
    $cache = $_SESSION[$cacheKey] ?? null;

    if (is_array($cache) && isset($cache['time'], $cache['values']) && (time() - (int) $cache['time']) < 180) {
        return (array) $cache['values'];
    }

    $res = $conn->query("SELECT DISTINCT $campo FROM clientes WHERE $campo IS NOT NULL AND $campo != '' ORDER BY $campo");
    $arr = [];
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            $arr[] = $r[$campo];
        }
        $res->free();
    }

    $_SESSION[$cacheKey] = [
        'time' => time(),
        'values' => $arr,
    ];

    return $arr;
}

foreach ([
    filtroArray("Pais", "paises"),
    filtroArray("pertenece", "pertenece"),
    filtroArray("Asignado", "asignados"),
    filtroArray("Apellido", "apellidos"),
    filtroArray("Estado", "estados"),
    filtroArray("UltimaGestion", "gestiones"),
    filtroArray("Campana", "campanas")
] as $f) {
    if ($f) $whereConditions[] = $f;
}

$where = count($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$paises = getOpciones("Pais");
$perteneceOpciones = getOpciones("pertenece");
$asignados = getOpciones("Asignado");
$apellidos = getOpciones("Apellido");
$estados = getOpciones("Estado");
$gestiones = getOpciones("UltimaGestion");
$campanas = getOpciones("Campana");

$total = (int) $conn->query("SELECT COUNT(*) as t FROM clientes $where")->fetch_assoc()['t'];
$totalPaginas = max(1, (int) ceil($total / $registrosPorPagina));
$sql = "
    SELECT TP, Nombre, Apellido, Numero, Correo, Pais, pertenece, Asignado, Estado, UltimaGestion, Campana
    FROM clientes
    $where
    ORDER BY $order $dir
    LIMIT $registrosPorPagina OFFSET $offset
";
$result = $conn->query($sql);

function checked($name, $value) {
    if (!isset($_GET[$name])) return "";
    $data = $_GET[$name];
    if (!is_array($data)) $data = [$data];
    return in_array($value, $data, true) ? "checked" : "";
}

function ordenar($campo, $order, $dir) {
    $newDir = ($order == $campo && $dir == 'ASC') ? 'DESC' : 'ASC';
    $icon = ($order == $campo) ? ($dir == 'ASC' ? '&uarr;' : '&darr;') : '';
    return "<a href='" . buildQuery(['order' => $campo, 'dir' => $newDir, 'pagina' => 1]) . "'>$campo $icon</a>";
}

function filterIcon(string $titulo): string {
    $icons = [
        'Pais' => '&#127758;',
        'Pertenece' => '&#127970;',
        'Asignado' => '&#128100;',
        'Apellido' => '&#9998;',
        'Estado' => '&#9873;',
        'Gestion' => '&#10227;',
        'Campana' => '&#128227;',
    ];

    return $icons[$titulo] ?? '&#9679;';
}

function renderFilter($titulo, $name, $data) {
    echo "<div class='filter-box'>";
    echo "<div class='filter-box-head'><span class='filter-box-icon'>" . filterIcon($titulo) . "</span><strong>" . htmlspecialchars($titulo) . "</strong></div>";
    echo "<div class='filter-box-body'>";
    foreach ($data as $v) {
        $safe = htmlspecialchars($v);
        echo "<label><span class='filter-box-check'><input type='checkbox' name='{$name}[]' value='{$safe}' " . checked($name, $v) . "></span><span class='filter-box-text'>{$safe}</span></label>";
    }
    echo "</div></div>";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignar Individual</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.assign-shell {
    display: grid;
    gap: 18px;
}

.assign-search-card,
.assign-saved-card {
    padding: 18px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.7);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.assign-saved-card h3 {
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

.filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
    align-items: start;
    width: 100%;
    min-width: 0;
}

.filters .filter-box {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 262px;
    max-height: 262px;
    padding: 16px 0 12px;
    overflow: hidden;
    box-sizing: border-box;
    border-radius: 16px;
    border: 1px solid rgba(31, 41, 51, 0.12);
    background: color-mix(in srgb, var(--panel-strong) 94%, white);
    box-shadow: 0 10px 24px color-mix(in srgb, var(--ink) 8%, transparent);
}

.filter-box-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 16px 12px;
    margin-bottom: 4px;
}

.filter-box-icon {
    color: var(--brand-dark);
    font-size: 0.95rem;
}

.filters .filter-box strong {
    display: block;
    font-size: 0.76rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #334155;
}

.filter-box-body {
    flex: 1 1 auto;
    padding: 0 12px 0 16px;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: color-mix(in srgb, var(--brand) 45%, transparent) transparent;
}

.filter-box-body::-webkit-scrollbar {
    width: 8px;
}

.filter-box-body::-webkit-scrollbar-thumb {
    background: color-mix(in srgb, var(--brand) 45%, transparent);
    border-radius: 999px;
}

.filters .filter-box label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 0.95rem;
    color: var(--ink);
    line-height: 1.25;
}

.filters .filter-box input[type="checkbox"],
.leads-table input[type="checkbox"] {
    width: 18px;
    min-width: 18px;
    height: 18px;
    margin: 0;
    padding: 0;
    border-radius: 6px;
    accent-color: var(--brand);
    box-shadow: none;
    flex: 0 0 auto;
}

.filter-box-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    padding-top: 1px;
}

.filter-box-text {
    display: block;
    color: var(--ink);
    word-break: break-word;
}

.assign-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 18px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.assign-toolbar-left {
    display: flex;
    flex-direction: column;
    gap: 12px;
    flex: 1 1 420px;
}

.assign-toolbar-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.assign-toolbar-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.assign-toolbar-meta input[type="number"] {
    width: 140px;
    height: auto;
}

.assign-toolbar-right {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
    width: min(320px, 100%);
}

.assign-toolbar-right select,
.assign-toolbar-right button {
    width: 100%;
}

.assign-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

.assign-table-wrap .leads-table {
    min-width: 1180px;
}

@media (max-width: 768px) {
    .consultas-grid {
        grid-template-columns: 1fr;
    }

    .assign-toolbar-right,
    .assign-toolbar-left {
        flex-basis: 100%;
        width: 100%;
    }

    .assign-toolbar-actions > *,
    .assign-toolbar-meta > * {
        width: 100%;
    }

    .assign-toolbar-meta input[type="number"] {
        width: 100%;
    }
}

@media (min-width: 769px) and (max-width: 1080px) {
    .consultas-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="card">
<div class="assign-shell">

<h2>Asignar Individual</h2>

<form method="GET">
<div class="assign-search-card">
<div class="search-bar">
<input type="text" name="busqueda" value="<?= htmlspecialchars($busqueda) ?>">
<select name="campo_busqueda">
<option value="ambos" <?= $campoBusqueda == 'ambos' ? 'selected' : '' ?>>TP y Nombre</option>
<option value="tp" <?= $campoBusqueda == 'tp' ? 'selected' : '' ?>>TP</option>
<option value="nombre" <?= $campoBusqueda == 'nombre' ? 'selected' : '' ?>>Nombre</option>
<option value="asignado" <?= $campoBusqueda == 'asignado' ? 'selected' : '' ?>>Asignado</option>
</select>
<button class="btn-primary">Buscar</button>
</div>
</div>

<div class="assign-saved-card">
<h3>Consultas Guardadas</h3>
<div class="consultas-grid">
<?php while ($c = $resConsultas->fetch_assoc()): ?>
<div class="consulta-card">
<div class="consulta-info">
<strong><?= htmlspecialchars($c['nombre']) ?></strong>
<small><?= htmlspecialchars($c['fecha_creacion']) ?></small>
</div>
<div class="acciones-consulta">
<button type="button" class="btn-play cargar-consulta" data-filtros='<?= htmlspecialchars($c["filtros"], ENT_QUOTES) ?>'>&#9654;</button>
<button type="button" class="btn-filter editar-consulta" data-id="<?= (int) $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>" data-filtros='<?= htmlspecialchars($c["filtros"], ENT_QUOTES) ?>'>Editar</button>
<button type="button" class="btn-delete eliminar-consulta" data-id="<?= (int) $c['id'] ?>">Eliminar</button>
</div>
</div>
<?php endwhile; ?>
</div>
</div>

<div class="filters">
<?php
renderFilter("Pais", "paises", $paises);
renderFilter("Pertenece", "pertenece", $perteneceOpciones);
renderFilter("Asignado", "asignados", $asignados);
renderFilter("Apellido", "apellidos", $apellidos);
renderFilter("Estado", "estados", $estados);
renderFilter("Gestion", "gestiones", $gestiones);
renderFilter("Campana", "campanas", $campanas);
?>
</div>

<button type="button" onclick="guardarConsulta()" class="btn-success">Guardar Consulta</button>

<div class="assign-toolbar">
<div class="assign-toolbar-left">
<div class="assign-toolbar-actions">
<button class="btn-primary">Aplicar Filtros</button>
<a href="<?= htmlspecialchars(routeUrl('assign_individual')) ?>" class="btn-clear">Limpiar</a>
<span class="total-registros">Total: <?= number_format($total) ?></span>
</div>

<div class="assign-toolbar-meta">
<input type="number" name="limite" value="<?= $registrosPorPagina ?>" min="1" max="5000" onkeypress="if(event.key==='Enter'){this.form.submit();}">
<span>numero de registros</span>
</div>
</div>

<div class="assign-toolbar-right">
<select id="usuario">
<option value="">Seleccionar usuario</option>
</select>
<button type="button" onclick="asignar()" class="btn-assign" id="assign-button">Asignar</button>
</div>
</div>
</form>

<br>

<div class="results-info" id="selected-count-indicator">
Seleccionados: <strong>0</strong>
</div>

<div class="assign-table-wrap">
<table class="leads-table">
<thead>
<tr>
<th><input type="checkbox" id="check-all-leads"></th>
<th><?= ordenar('TP', $order, $dir) ?></th>
<th><?= ordenar('Nombre', $order, $dir) ?></th>
<th><?= ordenar('Apellido', $order, $dir) ?></th>
<th><?= ordenar('Numero', $order, $dir) ?></th>
<th><?= ordenar('Correo', $order, $dir) ?></th>
<th><?= ordenar('Pais', $order, $dir) ?></th>
<th><?= ordenar('pertenece', $order, $dir) ?></th>
<th><?= ordenar('Asignado', $order, $dir) ?></th>
<th><?= ordenar('Estado', $order, $dir) ?></th>
<th><?= ordenar('UltimaGestion', $order, $dir) ?></th>
<th><?= ordenar('Campana', $order, $dir) ?></th>
</tr>
</thead>
<tbody>
<?php while ($r = $result->fetch_assoc()): ?>
<tr>
<td><input type="checkbox" class="check" value="<?= htmlspecialchars($r['TP']) ?>"></td>
<td><?= htmlspecialchars($r['TP']) ?></td>
<td><?= htmlspecialchars($r['Nombre']) ?></td>
<td><?= htmlspecialchars($r['Apellido']) ?></td>
<td><?= htmlspecialchars($r['Numero']) ?></td>
<td><?= htmlspecialchars($r['Correo']) ?></td>
<td><?= htmlspecialchars($r['Pais']) ?></td>
<td><?= htmlspecialchars($r['pertenece'] ?? '') ?></td>
<td><?= htmlspecialchars($r['Asignado']) ?></td>
<td><?= htmlspecialchars($r['Estado']) ?></td>
<td><?= htmlspecialchars($r['UltimaGestion'] ?? '') ?></td>
<td><?= htmlspecialchars($r['Campana'] ?? '') ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>

<div class="pagination">
<?php
for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++) {
    $cls = ($i == $pagina) ? "active" : "";
    echo "<a class='$cls' href='" . buildQuery(['pagina' => $i]) . "'>$i</a>";
}
?>
</div>

</div>
</div>
</div>
</div>

<script>
fetch(<?= json_encode(appUrl('core/obtener_usuarios.php')) ?>)
    .then(r => r.json())
    .then(data => {
        let s = document.getElementById("usuario");
        s.innerHTML = "<option value=''>Seleccionar usuario</option>";
        data.forEach(u => {
            let o = document.createElement("option");
            o.value = u.nombre;
            o.text = u.nombre;
            s.appendChild(o);
        });
    })
    .catch(err => console.error("ERROR cargando usuarios:", err));

function guardarConsulta() {
    let nombre = prompt("Nombre:");
    if (!nombre) return;
    persistirConsulta({ nombre });
}

function recolectarFiltrosFormulario() {
    let form = new FormData(document.querySelector("form"));
    let filtros = {};

    form.forEach((v, k) => {
        let key = k.replace("[]", "");
        if (filtros[key]) filtros[key].push(v);
        else filtros[key] = [v];
    });

    return filtros;
}

function persistirConsulta({ id = null, nombre }) {
    let filtros = recolectarFiltrosFormulario();

    fetch(<?= json_encode(appUrl('core/guardar_consulta.php')) ?>, {
        method: "POST",
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, nombre, filtros })
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            location.reload();
            return;
        }
        alert(res.error || "No fue posible guardar la consulta");
    })
    .catch(err => {
        console.error(err);
        alert("Error en la peticion");
    });
}

function asignar() {
    const assignButton = document.getElementById("assign-button");
    let usuario = document.getElementById("usuario").value;
    if (!usuario) { alert("Selecciona usuario"); return; }

    let tps = [...document.querySelectorAll(".check:checked")].map(c => c.value);
    if (tps.length === 0) { alert("Selecciona clientes"); return; }

    if (assignButton) {
        assignButton.disabled = true;
        assignButton.textContent = `Asignando ${tps.length}...`;
    }

    fetch(<?= json_encode(appUrl('core/asignar.php')) ?>, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tps, usuario })
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert(res.error || "Error al asignar");
    })
    .catch(err => {
        console.error(err);
        alert("Error en la peticion");
    })
    .finally(() => {
        if (assignButton) {
            assignButton.disabled = false;
            assignButton.textContent = "Asignar";
        }
    });
}

document.addEventListener("click", function(e) {
    if (e.target.classList.contains("cargar-consulta")) {
        let raw = e.target.getAttribute("data-filtros");
        let filtros;
        try { filtros = JSON.parse(raw); } catch (err) { console.error("ERROR JSON:", raw); return; }

        let url = new URL(window.location.href);
        url.search = "";

        Object.keys(filtros).forEach(k => {
            if (k === "pagina" || k === "page") {
                return;
            }
            if (k === "limite") {
                return;
            }
            let v = filtros[k];
            if (Array.isArray(v)) v.forEach(x => url.searchParams.append(k + "[]", x));
            else url.searchParams.append(k, v);
        });

        url.searchParams.set("limite", "20");

        window.location.href = url;
    }

    if (e.target.classList.contains("editar-consulta")) {
        let id = e.target.getAttribute("data-id");
        let nombreActual = e.target.getAttribute("data-nombre") || "";
        let nombreNuevo = prompt("Modificar nombre de la consulta:", nombreActual);
        if (!nombreNuevo) return;

        if (!confirm("Se actualizara esta consulta con los filtros que tienes actualmente en pantalla. Deseas continuar?")) {
            return;
        }

        persistirConsulta({ id: Number(id), nombre: nombreNuevo });
    }

    if (e.target.classList.contains("eliminar-consulta")) {
        let id = e.target.getAttribute("data-id");
        if (!confirm("Eliminar esta consulta?")) return;

        fetch(<?= json_encode(appUrl('core/eliminar_consulta.php')) ?>, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) location.reload();
            else alert("Error al eliminar");
        })
        .catch(err => {
            console.error(err);
            alert("Error en la peticion");
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const masterCheck = document.getElementById("check-all-leads");
    const countIndicator = document.getElementById("selected-count-indicator");
    const checks = [...document.querySelectorAll(".check")];
    if (!masterCheck || !countIndicator) return;

    function updateSelectedCount() {
        const checked = checks.filter(item => item.checked).length;
        countIndicator.innerHTML = `Seleccionados: <strong>${checked}</strong>`;
        masterCheck.checked = checks.length > 0 && checked === checks.length;
        masterCheck.indeterminate = checked > 0 && checked < checks.length;
    }

    masterCheck.addEventListener("change", function () {
        checks.forEach((checkbox) => {
            checkbox.checked = masterCheck.checked;
        });
        updateSelectedCount();
    });

    checks.forEach((checkbox) => {
        checkbox.addEventListener("change", function () {
            updateSelectedCount();
        });
    });

    updateSelectedCount();
});
</script>

</body>
</html>
