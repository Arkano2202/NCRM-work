<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("historico");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = trim((string) ($_SESSION["nombre"] ?? ""));
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));
$buscar = trim($_GET['tp'] ?? "");
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$whereParts = [];
$from = "FROM historico h";
$nombresScope = [];

if (in_array($tipo, [9, 10], true)) {
    $resScope = $conn->query("SELECT Nombre FROM users WHERE pertenece = '" . $conn->real_escape_string($pertenece) . "' AND Nombre IS NOT NULL AND Nombre != ''");
    if ($resScope instanceof mysqli_result) {
        while ($rowScope = $resScope->fetch_assoc()) {
            $nombreScope = trim((string) ($rowScope['Nombre'] ?? ''));
            if ($nombreScope !== '') {
                $nombresScope[] = $nombreScope;
            }
        }
        $resScope->free();
    }
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $resScope = $conn->query("SELECT Nombre FROM users WHERE Grupo = '" . $conn->real_escape_string((string) $userId) . "' AND Nombre IS NOT NULL AND Nombre != ''");
    if ($resScope instanceof mysqli_result) {
        while ($rowScope = $resScope->fetch_assoc()) {
            $nombreScope = trim((string) ($rowScope['Nombre'] ?? ''));
            if ($nombreScope !== '') {
                $nombresScope[] = $nombreScope;
            }
        }
        $resScope->free();
    }
}

if ($tipo === 1) {
    // Admin ve todo el historico.
} elseif (in_array($tipo, [9, 10], true)) {
    if (!empty($nombresScope)) {
        $scopeSql = array_map(
            static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'",
            array_values(array_unique($nombresScope))
        );
        $whereParts[] = "h.asignado IN (" . implode(', ', $scopeSql) . ")";
    } else {
        $whereParts[] = "1 = 0";
    }
} elseif (in_array($tipo, [4, 5, 8], true)) {
    if (!empty($nombresScope)) {
        $scopeSql = array_map(
            static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'",
            array_values(array_unique($nombresScope))
        );
        $whereParts[] = "h.asignado IN (" . implode(', ', $scopeSql) . ")";
    } else {
        $whereParts[] = "1 = 0";
    }
} else {
    $whereParts[] = "LOWER(TRIM(h.asignado)) = LOWER(TRIM('" . $conn->real_escape_string($nombre) . "'))";
}

if ($buscar !== "") {
    $tp = $conn->real_escape_string($buscar);
    $whereParts[] = "h.TP LIKE '%$tp%'";
}

$where = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

$totalQuery = $conn->query("SELECT COUNT(*) as total $from $where");
$total = (int) (($totalQuery->fetch_assoc()['total']) ?? 0);
$totalPages = max(1, (int) ceil($total / $limit));

$sql = "
    SELECT h.TP, h.nombre_cliente, h.asignado, h.accion, h.usuario_session, h.fecha_hora, h.modulo, h.memo
    $from
    $where
    ORDER BY h.fecha_hora DESC
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($sql);

function buildUrl($params = []) {
    return '?' . http_build_query(array_merge($_GET, $params));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historico</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.history-shell {
    display: grid;
    gap: 18px;
}

.history-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.history-copy {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.history-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.history-copy p {
    color: var(--muted);
}

.history-search {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 18px 20px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.history-search input {
    flex: 1 1 280px;
}

.history-search .btn-clear {
    white-space: nowrap;
}

.history-summary {
    color: var(--muted);
}

.history-summary strong {
    color: var(--brand);
}

.history-table-card {
    padding: 0;
    overflow: hidden;
}

.history-table-wrap {
    overflow-x: auto;
}

.history-table {
    min-width: 1120px;
}

.history-table td {
    vertical-align: top;
}

.history-table .memo-cell {
    min-width: 300px;
    max-width: 420px;
    white-space: normal;
    line-height: 1.5;
    color: var(--ink);
}

.history-table .date-cell {
    white-space: nowrap;
}

.history-empty {
    text-align: center;
    padding: 28px;
    color: var(--muted);
}

@media (max-width: 860px) {
    .history-toolbar {
        flex-direction: column;
        align-items: flex-start;
    }

    .history-search {
        align-items: stretch;
    }

    .history-search > * {
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
<div class="history-shell">
<section class="history-toolbar">
    <div class="history-copy">
        <span class="history-kicker">Trazabilidad operativa</span>
        <h1>Historico</h1>
        <p>Consulta movimientos, cambios y acciones realizadas sobre los leads desde un solo panel.</p>
    </div>
    <div class="history-summary">
        Registros encontrados: <strong><?= number_format($total) ?></strong>
    </div>
</section>

<form method="GET" class="history-search">
<input type="text" name="tp" placeholder="Buscar por TP..." value="<?= htmlspecialchars($buscar) ?>">
<button class="btn-search">Buscar</button>
<?php if ($buscar !== ""): ?><a href="<?= htmlspecialchars(routeUrl('history')) ?>" class="btn-clear">Limpiar</a><?php endif; ?>
</form>

<div class="table-container history-table-card">
<div class="history-table-wrap">
<table class="table-users history-table">
<thead>
<tr>
<th>TP</th><th>Nombre</th><th>Asignado</th><th>Accion</th><th>Responsable</th><th>Fecha</th><th>Modulo</th><th>Memo</th>
</tr>
</thead>
<tbody>
<?php if ($result->num_rows > 0): ?>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($row['TP']) ?></td>
<td><?= htmlspecialchars($row['nombre_cliente']) ?></td>
<td><?= htmlspecialchars($row['asignado']) ?></td>
<td><?= htmlspecialchars($row['accion']) ?></td>
<td><?= htmlspecialchars($row['usuario_session']) ?></td>
<td class="date-cell"><?= htmlspecialchars($row['fecha_hora']) ?></td>
<td><?= htmlspecialchars($row['modulo']) ?></td>
<td class="memo-cell"><?= nl2br(htmlspecialchars($row['memo'] ?? '')) ?></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="8" class="history-empty">No hay resultados para esta consulta.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php
$range = 2;
$start = max(1, $page - $range);
$end = min($totalPages, $page + $range);
?>

<div class="pagination">
<?php if ($page > 1): ?><a href="<?= buildUrl(['page' => $page - 1]) ?>">&laquo;</a><?php endif; ?>
<?php if ($start > 1): ?><a href="<?= buildUrl(['page' => 1]) ?>">1</a><?php if ($start > 2): ?><span>...</span><?php endif; ?><?php endif; ?>
<?php for ($i = $start; $i <= $end; $i++): ?>
<a href="<?= buildUrl(['page' => $i]) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
<?php if ($end < $totalPages): ?><?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?><a href="<?= buildUrl(['page' => $totalPages]) ?>"><?= $totalPages ?></a><?php endif; ?>
<?php if ($page < $totalPages): ?><a href="<?= buildUrl(['page' => $page + 1]) ?>">&raquo;</a><?php endif; ?>
</div>

</div>
</div>

</div>
</div>

</body>
</html>
