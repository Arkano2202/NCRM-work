<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/monitoreo_dia.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";

requireLogin();
requirePermission("leads");

$preservedSearchParams = $_GET;
unset($preservedSearchParams['search'], $preservedSearchParams['limit'], $preservedSearchParams['page']);

function renderHiddenQueryInputs($value, string $namePrefix = ''): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $nextName = $namePrefix === '' ? (string) $key : $namePrefix . '[' . $key . ']';
            renderHiddenQueryInputs($item, $nextName);
        }
        return;
    }

    if ($namePrefix === '') {
        return;
    }

    echo '<input type="hidden" name="' . htmlspecialchars($namePrefix, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . PHP_EOL;
}

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = $_SESSION["nombre"] ?? "";
$usuario = trim((string) ($_SESSION["usuario"] ?? ""));
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = $_SESSION["pertenece"] ?? "";
$esAgente = !in_array($tipo, [1, 4, 5, 8, 9, 10], true);
$esAdmin = $tipo === 1;
$puedeVerNotasLead = $tipo === 1 || in_array($tipo, [9, 10], true);
$mostrarAuxiliarEnTabla = in_array($tipo, [9, 10], true);
$estadoJornada = estadoJornadaAgente($conn, $usuario, $tipo);
$bloqueoLeads = $estadoJornada['bloqueado'] ?? false;
$mensajeBloqueoLeads = $estadoJornada['mensaje'] ?? null;

$verTelefono = (($_SESSION["phone"] ?? 0) == 1);
$verCorreo = (($_SESSION["mail"] ?? 0) == 1);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $esAdmin && isset($_POST["lead_action"]) && $_POST["lead_action"] === "editar_lead") {
    $tpEditar = trim((string) ($_POST["tp_edit"] ?? ""));
    $nombreEdit = trim((string) ($_POST["edit_nombre"] ?? ""));
    $apellidoEdit = trim((string) ($_POST["edit_apellido"] ?? ""));
    $correoEdit = trim((string) ($_POST["edit_correo"] ?? ""));
    $numeroEdit = trim((string) ($_POST["edit_numero"] ?? ""));
    $auxiliarEdit = trim((string) ($_POST["edit_auxiliar"] ?? ""));
    $paisEdit = trim((string) ($_POST["edit_pais"] ?? ""));
    $campanaEdit = trim((string) ($_POST["edit_campana"] ?? ""));
    $estadoEdit = trim((string) ($_POST["edit_estado"] ?? ""));

    if ($tpEditar !== "") {
        $stmtUpdateLead = $conn->prepare("
            UPDATE clientes
            SET Nombre = ?, Apellido = ?, Correo = ?, Numero = ?, Auxiliar = ?, Pais = ?, Campana = ?, Estado = ?
            WHERE TP = ?
        ");
        $stmtUpdateLead->bind_param(
            "sssssssss",
            $nombreEdit,
            $apellidoEdit,
            $correoEdit,
            $numeroEdit,
            $auxiliarEdit,
            $paisEdit,
            $campanaEdit,
            $estadoEdit,
            $tpEditar
        );
        $stmtUpdateLead->execute();
        $stmtUpdateLead->close();
    }

    $redirectQuery = $_SERVER["QUERY_STRING"] ?? "";
    header("Location: " . routeUrl("leads") . ($redirectQuery !== "" ? "?" . $redirectQuery : ""));
    exit;
}

$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if ($limit <= 0 || $limit > 1000) $limit = 10;
$offset = ($page - 1) * $limit;
$quickView = trim((string) ($_GET['quick_view'] ?? ''));
$esAgenteFtd = $tipo === 2;
$esAgenteConvertido = $tipo === 3;
$esAgenteConvergente = $tipo === 7;

$sort = $_GET['sort'] ?? 'TP';
$order = strtoupper($_GET['order'] ?? 'DESC');
$allowedSort = ['TP', 'Nombre', 'Apellido', 'Numero', 'Auxiliar', 'Correo', 'Estado', 'Asignado', 'Pais', 'UltimaGestion', 'FechaUltimaGestion'];
if (!in_array($sort, $allowedSort, true)) $sort = 'TP';
$order = $order === 'ASC' ? 'ASC' : 'DESC';

$sqlBase = "";
$prefix = "clientes";
$nombresEquipoTl = [];

if (!$bloqueoLeads && in_array($tipo, [4, 5, 8], true)) {
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

if (!$bloqueoLeads && $tipo === 1) {
    $sqlBase = "FROM clientes";
} elseif (!$bloqueoLeads && in_array($tipo, [9, 10], true)) {
    $sqlBase = "FROM clientes WHERE pertenece = '" . $conn->real_escape_string($pertenece) . "'";
} elseif (!$bloqueoLeads && in_array($tipo, [4, 5, 8], true)) {
    if (!empty($nombresEquipoTl)) {
        $equipoSql = array_map(
            static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'",
            array_values(array_unique($nombresEquipoTl))
        );
        $sqlBase = "FROM clientes WHERE Asignado IN (" . implode(', ', $equipoSql) . ")";
    } else {
        $sqlBase = "FROM clientes WHERE 1 = 0";
    }
} elseif (!$bloqueoLeads) {
    $sqlBase = "FROM clientes WHERE Asignado = '" . $conn->real_escape_string($nombre) . "'";
}

$filtros = [];

if (!empty($_GET['search'])) {
    $palabras = explode(',', (string) $_GET['search']);
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

    if (!empty($subFiltros)) {
        $filtros[] = "(" . implode(" OR ", $subFiltros) . ")";
    }
}

foreach ([
    ['tp', 'TP', 'like'],
    ['nombre', 'Nombre', 'like'],
    ['apellido', 'Apellido', 'like'],
    ['fecha_desde', 'FechaUltimaGestion', 'gte'],
    ['fecha_hasta', 'FechaUltimaGestion', 'lte']
] as [$param, $campo, $modo]) {
    if (!empty($_GET[$param])) {
        $valor = $conn->real_escape_string((string) $_GET[$param]);
        if ($modo === 'like') $filtros[] = "$prefix.$campo LIKE '%$valor%'";
        if ($modo === 'gte') $filtros[] = "$prefix.$campo >= '$valor'";
        if ($modo === 'lte') $filtros[] = "$prefix.$campo <= '$valor'";
    }
}

foreach ([
    ['pais', 'Pais'],
    ['gestion', 'UltimaGestion'],
    ['estado', 'Estado'],
    ['asignado', 'Asignado']
] as [$param, $campo]) {
    if (!empty($_GET[$param]) && is_array($_GET[$param])) {
        $vals = array_map([$conn, 'real_escape_string'], $_GET[$param]);
        $filtros[] = "$prefix.$campo IN ('" . implode("','", $vals) . "')";
    }
}

if (!$bloqueoLeads && !empty($filtros)) {
    $sqlBase .= (strpos($sqlBase, 'WHERE') !== false ? " AND " : " WHERE ") . implode(" AND ", $filtros);
}

$sqlScopeBase = $sqlBase;

if (!$bloqueoLeads && $quickView !== '') {
    $quickCondition = quickViewCondition($prefix, $quickView);
    if ($quickCondition !== null) {
        $sqlBase .= (strpos($sqlBase, 'WHERE') !== false ? " AND " : " WHERE ") . $quickCondition;
    }
}

$total = 0;
$totalPages = 1;
$result = false;
$leadRows = [];
$noteCountsByTp = [];

if (!$bloqueoLeads) {
    $total = (int) $conn->query("SELECT COUNT(*) as total $sqlBase")->fetch_assoc()['total'];
    $totalPages = max(1, (int) ceil($total / $limit));

    $sql = "SELECT
        $prefix.TP,
        $prefix.Nombre,
        $prefix.Apellido,
        $prefix.Numero,
        $prefix.Correo,
        $prefix.Auxiliar,
        $prefix.Estado,
        $prefix.Asignado,
        $prefix.Pais,
        $prefix.Campana,
        $prefix.UltimaGestion,
        $prefix.FechaUltimaGestion
        $sqlBase
        ORDER BY $prefix.$sort $order
        LIMIT $limit OFFSET $offset";

    $result = $conn->query($sql);

    $_SESSION['leads_ids'] = [];
    if ($result instanceof mysqli_result) {
        while ($tmp = $result->fetch_assoc()) {
            $leadRows[] = $tmp;
            $_SESSION['leads_ids'][] = $tmp['TP'];
        }

        if (!empty($leadRows)) {
            $tpSql = array_map(
                static fn(string $tp): string => "'" . $conn->real_escape_string($tp) . "'",
                array_values(array_unique(array_map(static fn(array $row): string => (string) ($row['TP'] ?? ''), $leadRows)))
            );

            if (!empty($tpSql)) {
                $sqlNotas = "
                    SELECT TP, COUNT(*) AS total_notas
                    FROM notas
                    WHERE TP IN (" . implode(', ', $tpSql) . ")
                    GROUP BY TP
                ";
                $resNotasConteo = $conn->query($sqlNotas);
                if ($resNotasConteo instanceof mysqli_result) {
                    while ($notaRow = $resNotasConteo->fetch_assoc()) {
                        $noteCountsByTp[(string) ($notaRow['TP'] ?? '')] = (int) ($notaRow['total_notas'] ?? 0);
                    }
                    $resNotasConteo->free();
                }
            }
        }
    }
}

function buildUrl($params = []) {
    return '?' . http_build_query(array_merge($_GET, $params));
}

function sortLink($column, $label, $sort, $order, $limit) {
    $newOrder = ($sort === $column && $order === 'ASC') ? 'DESC' : 'ASC';
    $url = buildUrl(['sort' => $column, 'order' => $newOrder, 'limit' => $limit, 'page' => 1]);
    return "<a href='" . htmlspecialchars($url) . "'>" . htmlspecialchars($label) . "</a>";
}

function quickViewCondition(string $prefix, string $quickView): ?string {
    $gestionCampo = "$prefix.UltimaGestion";
    $estadoCampo = "$prefix.Estado";

    if ($quickView === 'nuevos') {
        return "$estadoCampo = 'Asignado' AND LOWER(TRIM($gestionCampo)) = 'sin gestion'";
    }

    if ($quickView === 'potenciales') {
        return "LOWER(TRIM($gestionCampo)) = 'potencial'";
    }

    if ($quickView === 'call_again') {
        return "LOWER(TRIM($gestionCampo)) = 'call again'";
    }

    if ($quickView === 'nuevo_convertido') {
        return "$estadoCampo = 'Convertido' AND LOWER(TRIM($gestionCampo)) = 'deposito'";
    }

    if ($quickView === 'potencial_convertido') {
        return "LOWER(TRIM($gestionCampo)) = 'potencial'";
    }

    if ($quickView === 'en_gestion') {
        return "LOWER(TRIM($gestionCampo)) = 'en gestion'";
    }

    if ($quickView === 'nuevo_convergente') {
        return "$estadoCampo = 'Convergente' AND ($gestionCampo IS NULL OR TRIM($gestionCampo) = '' OR LOWER(TRIM($gestionCampo)) IN ('sin gestion', 'deposito'))";
    }

    if ($quickView === 'potencial_conv') {
        return "LOWER(TRIM($gestionCampo)) = 'potencial conv'";
    }

    if ($quickView === 'en_gestion_conv') {
        return "LOWER(TRIM($gestionCampo)) = 'en gestion'";
    }

    return null;
}

function optionScopeSql(string $baseSql, string $extraCondition): string {
    if ($baseSql === "") {
        return "FROM clientes WHERE 1=0";
    }

    return $baseSql . (stripos($baseSql, 'WHERE') !== false ? " AND " : " WHERE ") . $extraCondition;
}

$hotButtonLabels = [
    'nuevos' => 'Nuevos',
    'potenciales' => 'Potenciales',
    'call_again' => 'Call Again',
    'nuevo_convertido' => 'Nuevo',
    'potencial_convertido' => 'Potencial',
    'en_gestion' => 'En Gestion',
    'nuevo_convergente' => 'Nuevo',
    'potencial_conv' => 'Potencial',
    'en_gestion_conv' => 'En Gestion',
];

function buildHotButtonsFromCounts(array $counts, string $quickView, array $keys, array $labels): array
{
    $buttons = [];
    foreach ($keys as $key) {
        $buttons[] = [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'count' => (int) ($counts[$key] ?? 0),
            'active' => $quickView === $key,
            'url' => buildUrl([
                'quick_view' => $key,
                'page' => 1,
            ]),
        ];
    }

    return $buttons;
}

$hotButtons = [];
if (!$bloqueoLeads && $esAgenteFtd) {
    $keys = ['nuevos', 'potenciales', 'call_again'];
    $sumParts = [];
    foreach ($keys as $key) {
        $condition = quickViewCondition($prefix, $key) ?? '1=0';
        $sumParts[] = "SUM(CASE WHEN $condition THEN 1 ELSE 0 END) AS `$key`";
    }
    $counts = array_fill_keys($keys, 0);
    $countRes = $conn->query("SELECT " . implode(', ', $sumParts) . " $sqlScopeBase");
    if ($countRes) {
        $counts = $countRes->fetch_assoc() ?: $counts;
    }
    $hotButtons = buildHotButtonsFromCounts($counts, $quickView, $keys, $hotButtonLabels);
}

if (!$bloqueoLeads && $esAgenteConvertido) {
    $keys = ['nuevo_convertido', 'potencial_convertido', 'en_gestion'];
    $sumParts = [];
    foreach ($keys as $key) {
        $condition = quickViewCondition($prefix, $key) ?? '1=0';
        $sumParts[] = "SUM(CASE WHEN $condition THEN 1 ELSE 0 END) AS `$key`";
    }
    $counts = array_fill_keys($keys, 0);
    $countRes = $conn->query("SELECT " . implode(', ', $sumParts) . " $sqlScopeBase");
    if ($countRes) {
        $counts = $countRes->fetch_assoc() ?: $counts;
    }
    $hotButtons = buildHotButtonsFromCounts($counts, $quickView, $keys, $hotButtonLabels);
}

if (!$bloqueoLeads && $esAgenteConvergente) {
    $keys = ['nuevo_convergente', 'potencial_conv', 'en_gestion_conv'];
    $sumParts = [];
    foreach ($keys as $key) {
        $condition = quickViewCondition($prefix, $key) ?? '1=0';
        $sumParts[] = "SUM(CASE WHEN $condition THEN 1 ELSE 0 END) AS `$key`";
    }
    $counts = array_fill_keys($keys, 0);
    $countRes = $conn->query("SELECT " . implode(', ', $sumParts) . " $sqlScopeBase");
    if ($countRes) {
        $counts = $countRes->fetch_assoc() ?: $counts;
    }
    $hotButtons = buildHotButtonsFromCounts($counts, $quickView, $keys, $hotButtonLabels);
}

$paises = [];
$res = $conn->query("SELECT DISTINCT $prefix.Pais " . optionScopeSql($sqlBase, "$prefix.Pais IS NOT NULL AND $prefix.Pais != ''") . " ORDER BY $prefix.Pais");
while ($r = $res->fetch_assoc()) $paises[] = $r['Pais'];

$gestiones = [];
$res = $conn->query("SELECT DISTINCT $prefix.UltimaGestion " . optionScopeSql($sqlBase, "$prefix.UltimaGestion IS NOT NULL AND $prefix.UltimaGestion != ''") . " ORDER BY $prefix.UltimaGestion");
while ($r = $res->fetch_assoc()) $gestiones[] = $r['UltimaGestion'];

$estados = [];
$res = $conn->query("SELECT DISTINCT $prefix.Estado " . optionScopeSql($sqlBase, "$prefix.Estado IS NOT NULL AND $prefix.Estado != ''") . " ORDER BY $prefix.Estado");
while ($r = $res->fetch_assoc()) $estados[] = $r['Estado'];

$usuarios = [];
if ($tipo === 1) {
    $res = $conn->query("SELECT Nombre FROM users ORDER BY Nombre");
} elseif (in_array($tipo, [9, 10], true)) {
    $res = $conn->query("SELECT Nombre FROM users WHERE pertenece = '" . $conn->real_escape_string($pertenece) . "' ORDER BY Nombre");
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $res = $conn->query("SELECT Nombre FROM users WHERE Grupo = '" . $conn->real_escape_string((string) $userId) . "' ORDER BY Nombre");
}
if (!empty($res)) while ($r = $res->fetch_assoc()) $usuarios[] = $r['Nombre'];

$activeFilterTags = [];

if ($quickView === 'nuevos') $activeFilterTags[] = 'Vista: Nuevos';
if ($quickView === 'potenciales') $activeFilterTags[] = 'Vista: Potenciales';
if ($quickView === 'call_again') $activeFilterTags[] = 'Vista: Call Again';
if ($quickView === 'nuevo_convertido') $activeFilterTags[] = 'Vista: Nuevo';
if ($quickView === 'potencial_convertido') $activeFilterTags[] = 'Vista: Potencial';
if ($quickView === 'en_gestion') $activeFilterTags[] = 'Vista: En Gestion';
if ($quickView === 'nuevo_convergente') $activeFilterTags[] = 'Vista: Nuevo';
if ($quickView === 'potencial_conv') $activeFilterTags[] = 'Vista: Potencial';
if ($quickView === 'en_gestion_conv') $activeFilterTags[] = 'Vista: En Gestion';
if (!empty($_GET['search'])) $activeFilterTags[] = 'Busqueda';
if (!empty($_GET['tp'])) $activeFilterTags[] = 'TP';
if (!empty($_GET['nombre'])) $activeFilterTags[] = 'Nombre';
if (!empty($_GET['apellido'])) $activeFilterTags[] = 'Apellido';
if (!empty($_GET['fecha_desde']) || !empty($_GET['fecha_hasta'])) $activeFilterTags[] = 'Fecha';

foreach ([
    'pais' => 'Pais',
    'gestion' => 'Gestion',
    'estado' => 'Estado',
    'asignado' => 'Asignado'
] as $param => $label) {
    if (!empty($_GET[$param]) && is_array($_GET[$param])) {
        $activeFilterTags[] = $label . ': ' . count($_GET[$param]);
    }
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("leads.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.leads-lock-card {
    padding: 26px 28px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.leads-lock-card p {
    margin: 12px 0 18px;
    color: var(--muted);
}

.leads-filter-modal .modal-box {
    width: min(920px, 100%);
}

.leads-filter-form {
    display: grid;
    gap: 18px;
}

.leads-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.leads-filter-card {
    padding: 16px 18px;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.leads-filter-card label {
    display: block;
    margin-bottom: 8px;
    color: var(--muted);
    font-size: 0.92rem;
    font-weight: 600;
}

.leads-filter-sections {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.leads-filter-section {
    padding: 16px 18px;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.leads-filter-section h4 {
    margin-bottom: 12px;
    font-size: 0.8rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.leads-filter-pills {
    display: flex;
    flex-wrap: wrap;
    align-content: flex-start;
    gap: 8px;
    max-height: 156px;
    overflow-y: auto;
    padding-right: 6px;
}

.leads-filter-pills label {
    margin-bottom: 0;
}

.lead-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.leads-hot-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin: 16px 0 8px;
}

.hot-button {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 18px;
    text-decoration: none;
    background: color-mix(in srgb, var(--panel-strong) 94%, white);
    border: 1px solid var(--line);
    box-shadow: 0 10px 24px color-mix(in srgb, var(--ink) 8%, transparent);
    color: var(--ink);
    min-width: 168px;
}

.hot-button:hover {
    background: color-mix(in srgb, var(--brand) 9%, var(--panel-strong));
}

.hot-button.active {
    background: linear-gradient(135deg, var(--accent), var(--brand));
    color: white;
    border-color: transparent;
    box-shadow: 0 14px 28px color-mix(in srgb, var(--brand) 28%, transparent);
}

.hot-button-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.hot-button-title {
    font-weight: 700;
}

.hot-button-hint {
    font-size: 0.76rem;
    color: var(--muted);
}

.hot-button.active .hot-button-hint {
    color: rgba(255, 255, 255, 0.82);
}

.hot-button-count {
    margin-left: auto;
    min-width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: color-mix(in srgb, var(--brand) 12%, white);
    color: var(--brand-dark);
    font-weight: 800;
}

.hot-button.active .hot-button-count {
    background: rgba(255, 255, 255, 0.18);
    color: white;
}

.lead-actions .btn-clear,
.lead-actions .btn-filter {
    padding: 8px 12px;
    font-size: 0.82rem;
}

.lead-actions .btn-clear {
    background: color-mix(in srgb, var(--panel-strong) 92%, white);
    color: var(--ink);
    border: 1px solid var(--line);
    box-shadow: 0 10px 24px color-mix(in srgb, var(--ink) 12%, transparent);
}

.lead-actions .btn-clear:hover {
    background: color-mix(in srgb, var(--brand) 10%, var(--panel-strong));
    color: var(--brand-dark);
}

.edit-lead-modal .modal-box {
    width: min(760px, 100%);
}

.notes-modal .modal-box {
    width: min(520px, 100%);
    height: auto;
    max-height: calc(100vh - 36px);
    border-radius: 28px 0 0 28px;
}

.notes-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
}

.notes-modal-head .btn-clear {
    background: color-mix(in srgb, var(--panel-strong) 88%, white 12%);
    color: var(--ink);
    border: 1px solid color-mix(in srgb, var(--line) 92%, transparent);
    box-shadow: 0 10px 24px color-mix(in srgb, var(--ink) 10%, transparent);
}

.notes-modal-head .btn-clear:hover {
    background: color-mix(in srgb, var(--brand) 10%, var(--panel-strong));
    color: var(--brand-dark);
}

.notes-meta {
    color: var(--muted);
}

.notes-list {
    display: grid;
    gap: 12px;
    max-height: min(420px, calc(100vh - 180px));
    overflow-y: auto;
}

.note-item {
    padding: 16px 18px;
    border-radius: 18px;
    background: color-mix(in srgb, var(--panel-strong) 94%, white 6%);
    border: 1px solid color-mix(in srgb, var(--line) 90%, transparent);
    box-shadow: 0 10px 24px color-mix(in srgb, var(--ink) 10%, transparent);
}

.note-item strong {
    display: block;
    margin-bottom: 6px;
    color: var(--ink);
}

.note-item p {
    margin-bottom: 8px;
    white-space: pre-wrap;
    color: var(--ink);
}

.note-item small {
    color: var(--muted);
}

.notes-empty {
    padding: 24px;
    border-radius: 18px;
    background: color-mix(in srgb, var(--panel-strong) 88%, transparent);
    border: 1px dashed color-mix(in srgb, var(--line) 85%, transparent);
    color: var(--muted);
    text-align: center;
}

.notes-modal,
.edit-lead-modal {
    position: fixed;
    inset: 0;
    display: none;
    padding: 24px 16px;
    overflow: hidden;
}

.notes-modal .modal-box,
.edit-lead-modal .modal-box {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    margin: 0;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
}

.notes-modal {
    position: absolute;
    inset: 0;
    justify-content: flex-end;
    align-items: stretch;
    padding: 0;
    overflow: visible;
}

.notes-modal .modal-box {
    position: absolute;
    top: 18px;
    right: 0;
    left: auto;
    transform: none;
    margin: 0;
}

.edit-lead-modal {
    position: absolute;
    inset: 0;
    justify-content: center;
    align-items: flex-start;
    padding: 0;
    overflow: visible;
}

.edit-lead-modal .modal-box {
    position: absolute;
    top: 18px;
    left: 50%;
    transform: translateX(-50%);
}

@media (max-width: 860px) {
    .leads-filter-grid,
    .leads-filter-sections {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div
    id="leads-state-sync"
    data-bloqueado="<?= $bloqueoLeads ? '1' : '0' ?>"
    data-mensaje="<?= htmlspecialchars((string) $mensajeBloqueoLeads, ENT_QUOTES) ?>"
    hidden
></div>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">

<h1><?= htmlspecialchars(t("leads.title")) ?></h1>

<?php if ($bloqueoLeads): ?>
<div class="leads-lock-card">
    <h3><?= htmlspecialchars((string) $mensajeBloqueoLeads) ?></h3>
    <p><?= htmlspecialchars(t("leads.lock.message")) ?></p>
    <a href="<?= htmlspecialchars(routeUrl('times')) ?>" class="btn-primary"><?= htmlspecialchars(t("leads.lock.go_times")) ?></a>
</div>
<?php else: ?>

<div class="top-actions">
    <div class="actions-left">
        <button onclick="openModal()" class="btn-filter"><?= htmlspecialchars(t("leads.filters")) ?></button>
        <?php if (!empty($_GET)): ?>
            <a href="<?= htmlspecialchars(routeUrl('leads')) ?>" class="btn-clear"><?= htmlspecialchars(t("leads.clear_filters")) ?></a>
        <?php endif; ?>
    </div>

    <form method="GET" class="search-box">
        <span class="search-label"><?= htmlspecialchars(t("leads.search_global")) ?></span>
        <?php foreach ($preservedSearchParams as $paramName => $paramValue) { renderHiddenQueryInputs($paramValue, (string) $paramName); } ?>
        <input type="text" name="search" placeholder="Ej: juan, mexico, gmail..." value="<?= htmlspecialchars((string) ($_GET['search'] ?? '')) ?>">
        <input type="hidden" name="page" value="1">
        <input type="number" name="limit" value="<?= $limit ?>">
        <button><?= htmlspecialchars(t("common.apply")) ?></button>
    </form>
</div>

<?php if (!empty($hotButtons)): ?>
<div class="leads-hot-buttons">
    <?php foreach ($hotButtons as $hotButton): ?>
        <a href="<?= htmlspecialchars($hotButton['url']) ?>" class="hot-button <?= $hotButton['active'] ? 'active' : '' ?>">
            <span class="hot-button-copy">
                <span class="hot-button-title"><?= htmlspecialchars($hotButton['label']) ?></span>
                <span class="hot-button-hint">Leads</span>
            </span>
            <span class="hot-button-count"><?= (int) $hotButton['count'] ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="leads-toolbar">
    <div class="leads-toolbar-copy">
        <span class="toolbar-kicker"><?= htmlspecialchars(t("leads.active_view")) ?></span>
        <strong><?= number_format($total) ?> leads</strong>
        <small><?= htmlspecialchars(t("leads.active_help")) ?></small>
    </div>

    <div class="active-filters">
        <?php if (!empty($activeFilterTags)): ?>
            <?php foreach ($activeFilterTags as $tag): ?>
                <span class="active-filter-chip"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="active-filter-chip muted"><?= htmlspecialchars(t("leads.no_advanced_filters")) ?></span>
        <?php endif; ?>
    </div>
</div>

<div id="filterModal" class="modal leads-filter-modal">
<div class="modal-box">
<h3><?= htmlspecialchars(t("leads.filters")) ?></h3>
<form method="GET" class="leads-filter-form">
<input type="hidden" name="limit" value="<?= $limit ?>">

<div class="leads-filter-grid">
<div class="leads-filter-card">
<label>TP</label>
<input type="text" name="tp" placeholder="TP" value="<?= htmlspecialchars((string) ($_GET['tp'] ?? '')) ?>">
</div>
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.name")) ?></label>
<input type="text" name="nombre" placeholder="Nombre" value="<?= htmlspecialchars((string) ($_GET['nombre'] ?? '')) ?>">
</div>
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.lastname")) ?></label>
<input type="text" name="apellido" placeholder="Apellido" value="<?= htmlspecialchars((string) ($_GET['apellido'] ?? '')) ?>">
</div>
</div>

<div class="leads-filter-sections">
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.from")) ?></label>
<input type="date" name="fecha_desde" value="<?= htmlspecialchars((string) ($_GET['fecha_desde'] ?? '')) ?>">
</div>
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.to")) ?></label>
<input type="date" name="fecha_hasta" value="<?= htmlspecialchars((string) ($_GET['fecha_hasta'] ?? '')) ?>">
</div>
</div>

<div class="leads-filter-sections">
<div class="leads-filter-section">
<h4><?= htmlspecialchars(t("common.country")) ?></h4>
<div class="leads-filter-pills">
<?php foreach ($paises as $p): ?>
<label>
<input type="checkbox" name="pais[]" value="<?= htmlspecialchars($p) ?>" <?= (isset($_GET['pais']) && in_array($p, $_GET['pais'], true)) ? 'checked' : '' ?>>
<?= htmlspecialchars($p) ?>
</label>
<?php endforeach; ?>
</div>
</div>

<div class="leads-filter-section">
<h4><?= htmlspecialchars(t("common.management")) ?></h4>
<div class="leads-filter-pills">
<?php foreach ($gestiones as $g): ?>
<label>
<input type="checkbox" name="gestion[]" value="<?= htmlspecialchars($g) ?>" <?= (isset($_GET['gestion']) && in_array($g, $_GET['gestion'], true)) ? 'checked' : '' ?>>
<?= htmlspecialchars($g) ?>
</label>
<?php endforeach; ?>
</div>
</div>
</div>

<?php if (!empty($usuarios)): ?>
<div class="leads-filter-section">
<h4><?= htmlspecialchars(t("common.assigned")) ?></h4>
<div class="leads-filter-pills">
<?php foreach ($usuarios as $u): ?>
<label>
<input type="checkbox" name="asignado[]" value="<?= htmlspecialchars($u) ?>" <?= (isset($_GET['asignado']) && in_array($u, $_GET['asignado'], true)) ? 'checked' : '' ?>>
<?= htmlspecialchars($u) ?>
</label>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<div class="modal-actions">
<button type="submit"><?= htmlspecialchars(t("common.apply")) ?></button>
<button type="button" onclick="closeModal()"><?= htmlspecialchars(t("common.close")) ?></button>
</div>

</form>
</div>
</div>

<?php if ($puedeVerNotasLead): ?>
<div id="notesLeadModal" class="modal notes-modal">
<div class="modal-box">
<div class="notes-modal-head">
    <div>
        <h3><?= htmlspecialchars(t("leads.notes_title")) ?></h3>
        <div class="notes-meta" id="notesLeadMeta"><?= htmlspecialchars(t("common.loading")) ?></div>
    </div>
    <button type="button" class="btn-clear" onclick="closeNotesLeadModal()"><?= htmlspecialchars(t("common.close")) ?></button>
</div>
<div id="notesLeadContent" class="notes-list"></div>
</div>
</div>
<?php endif; ?>

<?php if ($esAdmin): ?>
<div id="editLeadModal" class="modal edit-lead-modal">
<div class="modal-box">
<h3><?= htmlspecialchars(t("leads.edit_title")) ?></h3>
<form method="POST" class="leads-filter-form">
<input type="hidden" name="lead_action" value="editar_lead">
<input type="hidden" name="tp_edit" id="edit_tp">
<input type="hidden" name="edit_apellido" id="edit_apellido">
<input type="hidden" name="edit_campana" id="edit_campana">
<input type="hidden" name="edit_estado" id="edit_estado">

<div class="leads-filter-grid">
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.name")) ?></label>
<input type="text" name="edit_nombre" id="edit_nombre" required>
</div>
<div class="leads-filter-card">
<label>Correo</label>
<input type="email" name="edit_correo" id="edit_correo">
</div>
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.phone")) ?></label>
<input type="text" name="edit_numero" id="edit_numero">
</div>
</div>

<div class="leads-filter-grid">
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.auxiliary")) ?></label>
<input type="text" name="edit_auxiliar" id="edit_auxiliar">
</div>
<div class="leads-filter-card">
<label><?= htmlspecialchars(t("common.country")) ?></label>
<input type="text" name="edit_pais" id="edit_pais">
</div>
</div>

<div class="modal-actions">
<button type="submit"><?= htmlspecialchars(t("common.save_changes")) ?></button>
<button type="button" onclick="closeEditLeadModal()"><?= htmlspecialchars(t("common.close")) ?></button>
</div>
</form>
</div>
</div>
<?php endif; ?>

<div class="results-info">
    Mostrando
    <strong><?= $total > 0 ? $offset + 1 : 0 ?></strong> -
    <strong><?= min($offset + $limit, $total) ?></strong>
    de
    <strong><?= number_format($total) ?></strong> registros
</div>

<div class="table-container">
<table class="leads-table">
<thead>
<tr>
<th><?= sortLink('TP', 'TP', $sort, $order, $limit) ?></th>
<th><?= sortLink('Nombre', 'Nombre', $sort, $order, $limit) ?></th>
<th><?= sortLink('Apellido', 'Apellido', $sort, $order, $limit) ?></th>
<th><?= sortLink('Numero', 'Telefono', $sort, $order, $limit) ?></th>
<?php if ($mostrarAuxiliarEnTabla): ?>
<th><?= sortLink('Auxiliar', 'Auxiliar', $sort, $order, $limit) ?></th>
<?php endif; ?>
<th><?= sortLink('Correo', 'Email', $sort, $order, $limit) ?></th>
<th><?= sortLink('Estado', 'Estado', $sort, $order, $limit) ?></th>
<th><?= sortLink('Asignado', 'Asignado', $sort, $order, $limit) ?></th>
<th><?= sortLink('Pais', 'Pais', $sort, $order, $limit) ?></th>
<th><?= sortLink('UltimaGestion', 'Gestion', $sort, $order, $limit) ?></th>
<th><?= sortLink('FechaUltimaGestion', 'Fecha', $sort, $order, $limit) ?></th>
<?php if ($puedeVerNotasLead || $esAdmin): ?><th><?= htmlspecialchars(t("common.actions")) ?></th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($leadRows as $row): ?>
<?php $totalNotasRow = (int) ($noteCountsByTp[(string) ($row["TP"] ?? '')] ?? 0); ?>
<tr>
<td><a href="<?= htmlspecialchars(routeUrl('lead_details', ['tp' => $row["TP"]])) ?>"><?= htmlspecialchars($row["TP"]) ?></a></td>
<td><?= htmlspecialchars($row["Nombre"]) ?></td>
<td><?= htmlspecialchars($row["Apellido"]) ?></td>
<td><?= $verTelefono ? htmlspecialchars($row["Numero"]) : '<span class="hidden-data">********</span>' ?></td>
<?php if ($mostrarAuxiliarEnTabla): ?>
<td><?= $verTelefono ? htmlspecialchars($row["Auxiliar"] ?? "") : '<span class="hidden-data">********</span>' ?></td>
<?php endif; ?>
<td><?= $verCorreo ? htmlspecialchars($row["Correo"]) : '<span class="hidden-data">********</span>' ?></td>
<td><?= htmlspecialchars($row["Estado"]) ?></td>
<td><?= htmlspecialchars($row["Asignado"]) ?></td>
<td><?= htmlspecialchars($row["Pais"]) ?></td>
<td><?= htmlspecialchars($row["UltimaGestion"] ?? "") ?></td>
<td><?= htmlspecialchars($row["FechaUltimaGestion"] ?? "") ?></td>
<?php if ($puedeVerNotasLead || $esAdmin): ?>
<td>
    <div class="lead-actions">
        <?php if ($puedeVerNotasLead): ?>
        <button type="button" class="btn-clear" onclick="openNotesLeadModal('<?= htmlspecialchars($row["TP"]) ?>')"><?= htmlspecialchars(t("common.notes")) ?><?php if ($totalNotasRow > 0): ?> (<?= $totalNotasRow ?>)<?php endif; ?></button>
        <?php endif; ?>
        <?php if ($esAdmin): ?>
        <button
            type="button"
            class="btn-filter"
            onclick='openEditLeadModal(<?= json_encode([
                "TP" => (string) ($row["TP"] ?? ""),
                "Nombre" => (string) ($row["Nombre"] ?? ""),
                "Apellido" => (string) ($row["Apellido"] ?? ""),
                "Correo" => (string) ($row["Correo"] ?? ""),
                "Numero" => (string) ($row["Numero"] ?? ""),
                "Auxiliar" => (string) ($row["Auxiliar"] ?? ""),
                "Pais" => (string) ($row["Pais"] ?? ""),
                "Campana" => (string) ($row["Campana"] ?? ""),
                "Estado" => (string) ($row["Estado"] ?? ""),
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
        >
            Editar
        </button>
        <?php endif; ?>
    </div>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
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
<?php endif; ?>

</div>
</div>

<script>
function openModal(){ document.getElementById("filterModal").style.display = "block"; }
function closeModal(){ document.getElementById("filterModal").style.display = "none"; }
function openNotesLeadModal(tp){
    const modal = document.getElementById("notesLeadModal");
    const meta = document.getElementById("notesLeadMeta");
    const content = document.getElementById("notesLeadContent");
    if (!modal || !meta || !content) return;

    positionNotesLeadModal();
    meta.textContent = "Cargando...";
    content.innerHTML = '<div class="notes-empty"><?= htmlspecialchars(t("leads.loading_notes")) ?></div>';
    modal.style.display = "flex";

    fetch(`${<?= json_encode(appUrl('core/notas_lead.php')) ?>}?tp=${encodeURIComponent(tp)}`, { cache: "no-store" })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || "No fue posible obtener las notas");
            const totalNotas = Number(data.total_notas || 0);
            meta.textContent = `${data.cliente.tp} - ${data.cliente.nombre} · ${totalNotas} ${totalNotas === 1 ? "nota" : "notas"}`;

            if (!Array.isArray(data.notas) || data.notas.length === 0) {
                content.innerHTML = '<div class="notes-empty"><?= htmlspecialchars(t("leads.no_notes")) ?></div>';
                return;
            }

            content.innerHTML = data.notas.map(nota => `
                <article class="note-item">
                    <strong>${escapeHtml(nota.gestion || <?= json_encode(t("leads.no_management")) ?>)}</strong>
                    <p>${escapeHtml(nota.descripcion || "").replace(/\n/g, "<br>")}</p>
                    <small>${escapeHtml(nota.fecha || "")}${nota.usuario ? " · " + escapeHtml(nota.usuario) : ""}</small>
                </article>
            `).join("");
        })
        .catch(err => {
            meta.textContent = "Error";
            content.innerHTML = `<div class="notes-empty">${escapeHtml(err.message || <?= json_encode(t("leads.notes_error")) ?>)}</div>`;
        });
}
function closeNotesLeadModal(){
    const modal = document.getElementById("notesLeadModal");
    if (modal) modal.style.display = "none";
}
function positionNotesLeadModal(){
    const modal = document.getElementById("notesLeadModal");
    if (!modal) return;

    const modalBox = modal.querySelector(".modal-box");
    if (!modalBox) return;

    const doc = document.documentElement;
    const pageHeight = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0, window.innerHeight);
    modal.style.height = `${pageHeight}px`;
    modalBox.style.top = `${window.scrollY + 18}px`;
}
function openEditLeadModal(lead){
    const modal = document.getElementById("editLeadModal");
    if (!modal || !lead) return;
    positionEditLeadModal();
    document.getElementById("edit_tp").value = lead.TP || "";
    document.getElementById("edit_nombre").value = lead.Nombre || "";
    document.getElementById("edit_apellido").value = lead.Apellido || "";
    document.getElementById("edit_correo").value = lead.Correo || "";
    document.getElementById("edit_numero").value = lead.Numero || "";
    document.getElementById("edit_auxiliar").value = lead.Auxiliar || "";
    document.getElementById("edit_pais").value = lead.Pais || "";
    document.getElementById("edit_campana").value = lead.Campana || "";
    document.getElementById("edit_estado").value = lead.Estado || "";
    modal.style.display = "flex";
}
function closeEditLeadModal(){
    const modal = document.getElementById("editLeadModal");
    if (modal) modal.style.display = "none";
}
function positionEditLeadModal(){
    const modal = document.getElementById("editLeadModal");
    if (!modal) return;

    const modalBox = modal.querySelector(".modal-box");
    if (!modalBox) return;

    const doc = document.documentElement;
    const pageHeight = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0, window.innerHeight);
    modal.style.height = `${pageHeight}px`;
    modalBox.style.top = `${window.scrollY + 18}px`;
}
function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}
window.addEventListener("scroll", function () {
    const notesModal = document.getElementById("notesLeadModal");
    const editModal = document.getElementById("editLeadModal");
    if (notesModal && notesModal.style.display === "flex") positionNotesLeadModal();
    if (editModal && editModal.style.display === "flex") positionEditLeadModal();
}, { passive: true });
window.addEventListener("resize", function () {
    const notesModal = document.getElementById("notesLeadModal");
    const editModal = document.getElementById("editLeadModal");
    if (notesModal && notesModal.style.display === "flex") positionNotesLeadModal();
    if (editModal && editModal.style.display === "flex") positionEditLeadModal();
});

(function () {
    const stateNode = document.getElementById("leads-state-sync");
    if (!stateNode) return;

    let bloqueoActual = stateNode.dataset.bloqueado === "1";
    let mensajeActual = stateNode.dataset.mensaje || "";
    let checking = false;

    function verificarEstadoJornada() {
        if (checking) return;
        checking = true;

        fetch(<?= json_encode(appUrl('core/estado_jornada.php')) ?>, { cache: "no-store" })
            .then(r => r.json())
            .then(data => {
                const nuevoBloqueo = !!data.bloqueado;
                const nuevoMensaje = data.mensaje || "";

                if (nuevoBloqueo !== bloqueoActual || nuevoMensaje !== mensajeActual) {
                    window.location.reload();
                    return;
                }

                bloqueoActual = nuevoBloqueo;
                mensajeActual = nuevoMensaje;
            })
            .catch(() => {})
            .finally(() => {
                checking = false;
            });
    }

    window.setInterval(verificarEstadoJornada, 600000);

    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "visible") {
            verificarEstadoJornada();
        }
    });
})();
</script>

</body>
</html>
