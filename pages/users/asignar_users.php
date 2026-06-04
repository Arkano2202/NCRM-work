<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("asignar_usuarios");

$msg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $seleccionados = array_map("intval", $_POST["usuarios"] ?? []);

    if (!empty($seleccionados)) {
        $ids = implode(",", $seleccionados);

        if (isset($_POST["asignar"])) {
            $tlId = (int) ($_POST["tl"] ?? 0);

            if ($tlId > 0) {
                $conn->query("UPDATE users SET Grupo = $tlId WHERE id IN ($ids)");
                $msg = "Team Leader asignado correctamente.";
            } else {
                $msg = "Debes seleccionar un Team Leader.";
            }
        }

        if (isset($_POST["quitar"])) {
            $conn->query("UPDATE users SET Grupo = 0 WHERE id IN ($ids)");
            $msg = "Team Leader removido.";
        }
    }
}

$tl = $conn->query("
    SELECT id, Nombre
    FROM users
    WHERE Tipo IN (1,4,5,8)
    ORDER BY Nombre ASC
");

$agentes = $conn->query("
    SELECT u.id, u.Nombre, u.Usuario, u.Ext, u.Tipo, u.pertenece, tl.Nombre AS tl_nombre
    FROM users u
    LEFT JOIN users tl ON u.Grupo = tl.id
    WHERE u.Tipo IN (2,3,7,9,10)
    ORDER BY u.Nombre ASC
");

function tipoTexto($tipo) {
    if ($tipo == 1) return "Administrador";
    if ($tipo == 2) return "Agente FTD";
    if ($tipo == 3) return "Agente Rete";
    if ($tipo == 7) return "Agente Conver";
    if ($tipo == 9) return "Floor Medellin";
    if ($tipo == 10) return "Floor Cali";
    return "N/A";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignar Usuarios</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.btn{padding:6px 10px;border-radius:6px;border:none;cursor:pointer;color:white;}
.btn-assign{background:#3b82f6;}
.btn-remove{background:#ef4444;}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">

<h1>Asignar Usuarios</h1>

<?php if ($msg !== ""): ?>
<div class="alert"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<form method="POST">
<div class="actions-bar">
<div class="left-actions">
<select name="tl" class="select-tl">
<option value="">Seleccionar lider...</option>
<?php while ($t = $tl->fetch_assoc()): ?>
<option value="<?= (int) $t["id"] ?>"><?= htmlspecialchars($t["Nombre"]) ?></option>
<?php endwhile; ?>
</select>
</div>

<div class="right-actions">
<button name="asignar" class="btn btn-assign" onclick="return confirmar('asignar leader')">Asignar Leader</button>
<button name="quitar" class="btn btn-remove" onclick="return confirmar('quitar leader')">Quitar Leader</button>
</div>
</div>

<div class="table-container">
<table class="table-users">
<thead>
<tr>
<th><input type="checkbox" onclick="toggleAll(this)"></th>
<th>Nombre</th>
<th>Usuario</th>
<th>Ext</th>
<th>Tipo</th>
<th>Pertenece</th>
<th>Team Leader</th>
</tr>
</thead>
<tbody>
<?php while ($u = $agentes->fetch_assoc()): ?>
<tr>
<td><input type="checkbox" name="usuarios[]" value="<?= (int) $u["id"] ?>"></td>
<td><?= htmlspecialchars($u["Nombre"]) ?></td>
<td><?= htmlspecialchars($u["Usuario"]) ?></td>
<td><?= htmlspecialchars($u["Ext"]) ?></td>
<td><?= htmlspecialchars(tipoTexto($u["Tipo"])) ?></td>
<td><?= htmlspecialchars($u["pertenece"]) ?></td>
<td><?= htmlspecialchars($u["tl_nombre"] ?? "Sin asignar") ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</form>

</div>
</div>

<script>
function confirmar(tipo){ return confirm("Seguro que deseas " + tipo + "?"); }
function toggleAll(source){ document.querySelectorAll('input[name="usuarios[]"]').forEach(cb => cb.checked = source.checked); }
</script>

</body>
</html>
