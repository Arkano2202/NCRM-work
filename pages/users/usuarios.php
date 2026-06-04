<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("usuarios");

$msg = "";
$edit = false;
$data = [];
$unlockMsg = "";
$unlockError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    $deleteId = (int) $_POST["delete_id"];

    if ($deleteId === (int) ($_SESSION["user_id"] ?? 0)) {
        $msg = "No puedes eliminar tu propio usuario.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $deleteId);
        $stmt->execute();
        $stmt->close();
        header("Location: " . routeUrl("users"));
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["unlock_user"])) {
    date_default_timezone_set('America/Bogota');
    $unlockUser = trim((string) ($_POST["unlock_user"] ?? ""));
    $unlockDate = date('Y-m-d');

    if ($unlockUser === "") {
        $unlockError = "Debes indicar el usuario que deseas desbloquear.";
    } else {
        $stmtFind = $conn->prepare("
            SELECT id, h_entrada
            FROM monitoreo
            WHERE usuario = ? AND fecha = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($stmtFind instanceof mysqli_stmt) {
            $stmtFind->bind_param("ss", $unlockUser, $unlockDate);
            $stmtFind->execute();
            $registroMonitoreo = $stmtFind->get_result()->fetch_assoc() ?: null;
            $stmtFind->close();

            if (!$registroMonitoreo) {
                $unlockError = "No se encontro un registro de monitoreo para hoy con ese usuario.";
            } else {
                $registroId = (int) ($registroMonitoreo["id"] ?? 0);
                $horaEntrada = trim((string) ($registroMonitoreo["h_entrada"] ?? "0"));
                if ($registroId <= 0 || $horaEntrada === "" || $horaEntrada === "0") {
                    $unlockError = "El registro de monitoreo de hoy no es valido para desbloquear.";
                } else {
                    $stmtUnlock = $conn->prepare("
                        UPDATE monitoreo
                        SET h_salida = '0', h_referencia = '0', estado = 'Puesto'
                        WHERE id = ?
                    ");

                    if ($stmtUnlock instanceof mysqli_stmt) {
                        $stmtUnlock->bind_param("i", $registroId);
                        $stmtUnlock->execute();
                        $stmtUnlock->close();
                        $unlockMsg = "Usuario desbloqueado correctamente para continuar la jornada.";
                    } else {
                        $unlockError = "No fue posible desbloquear el usuario.";
                    }
                }
            }
        } else {
            $unlockError = "No fue posible consultar monitoreo.";
        }
    }
}

if (isset($_GET["edit"])) {
    $id = (int) $_GET["edit"];
    $edit = true;

    $stmt = $conn->prepare("SELECT id, Nombre, Ext, Tipo, grupo_id, pertenece, telefonia FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST["delete_id"])) {
    $id = isset($_POST["id"]) && $_POST["id"] !== "" ? (int) $_POST["id"] : null;
    $ext = trim($_POST["ext"] ?? "");
    $tipo = (int) ($_POST["tipo"] ?? 0);
    $grupoSelect = trim($_POST["grupo_select"] ?? "");
    $pertenece = trim($_POST["pertenece"] ?? "");
    $contrasena = $_POST["contrasena"] ?? "";
    $telefonia = (int) ($_POST["telefonia"] ?? 1);

    if (!in_array($telefonia, [1, 2], true)) {
        $telefonia = 1;
    }

    $grupoId = $grupoSelect === "FTD" ? 1 : ($grupoSelect === "Rete" ? 2 : 3);
    $phone = $tipo === 1 ? 1 : 0;
    $mail = in_array($tipo, [1, 4, 5, 8, 9, 10], true) ? 1 : 0;

    $stmtCheck = $conn->prepare("SELECT id FROM users WHERE Ext = ? AND (? IS NULL OR id != ?)");
    $stmtCheck->bind_param("sii", $ext, $id, $id);
    $stmtCheck->execute();
    $exists = $stmtCheck->get_result()->num_rows > 0;
    $stmtCheck->close();

    if ($exists) {
        $msg = "La extension ya existe.";
    } elseif ($id !== null) {
        if ($contrasena !== "") {
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                UPDATE users
                SET Ext = ?, Tipo = ?, grupo_id = ?, pertenece = ?, phone = ?, mail = ?, telefonia = ?, Contrasena = ?
                WHERE id = ?
            ");
            $stmt->bind_param("siisiiisi", $ext, $tipo, $grupoId, $pertenece, $phone, $mail, $telefonia, $hash, $id);
        } else {
            $stmt = $conn->prepare("
                UPDATE users
                SET Ext = ?, Tipo = ?, grupo_id = ?, pertenece = ?, phone = ?, mail = ?, telefonia = ?
                WHERE id = ?
            ");
            $stmt->bind_param("siisiiii", $ext, $tipo, $grupoId, $pertenece, $phone, $mail, $telefonia, $id);
        }

        $stmt->execute();
        $stmt->close();
        header("Location: " . routeUrl("users"));
        exit;
    } else {
        $nombre = trim($_POST["nombre"] ?? "");
        $usuario = trim($_POST["usuario"] ?? "");

        if ($nombre === "" || $usuario === "" || $contrasena === "" || $ext === "") {
            $msg = "Completa todos los campos obligatorios.";
        } else {
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $grupo = 0;

            $stmt = $conn->prepare("
                INSERT INTO users (Nombre, Usuario, Contrasena, Ext, Tipo, Grupo, grupo_id, pertenece, phone, mail, telefonia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssiiisiii", $nombre, $usuario, $hash, $ext, $tipo, $grupo, $grupoId, $pertenece, $phone, $mail, $telefonia);
            $stmt->execute();
            $stmt->close();
            $msg = "Usuario creado correctamente.";
        }
    }
}

$tipos = $conn->query("SELECT codigo, Grupo FROM t_user");
$ciudades = $conn->query("SELECT Nombre FROM ciudad");

$page = max(1, (int) ($_GET["page"] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

date_default_timezone_set('America/Bogota');
$unlockDate = date('Y-m-d');
$unlockCandidates = [];
$stmtUnlockCandidates = $conn->prepare("
    SELECT m.usuario, u.Nombre, m.h_salida
    FROM monitoreo m
    LEFT JOIN users u ON u.Usuario = m.usuario
    WHERE m.fecha = ? AND m.h_salida <> '0'
    ORDER BY COALESCE(u.Nombre, m.usuario) ASC
");
if ($stmtUnlockCandidates instanceof mysqli_stmt) {
    $stmtUnlockCandidates->bind_param("s", $unlockDate);
    $stmtUnlockCandidates->execute();
    $unlockCandidatesResult = $stmtUnlockCandidates->get_result();
    while ($unlockRow = $unlockCandidatesResult->fetch_assoc()) {
        $unlockCandidates[] = $unlockRow;
    }
    $stmtUnlockCandidates->close();
}

$totalUsers = (int) $conn->query("SELECT COUNT(*) total FROM users")->fetch_assoc()["total"];
$totalPages = max(1, (int) ceil($totalUsers / $limit));

$usuarios = $conn->query("
    SELECT id, Nombre, Usuario, Ext, pertenece, telefonia
    FROM users
    ORDER BY Nombre ASC
    LIMIT $limit OFFSET $offset
");
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Usuarios</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">

<style>
.user-container { display:grid; grid-template-columns:350px 1fr; gap:20px; }
.acciones { display:flex; gap:5px; }
.btn-edit { background:#3b82f6; padding:4px 8px; border-radius:6px; font-size:12px; color:white; text-decoration:none; }
.btn-delete { background:#ef4444; padding:4px 8px; border-radius:6px; font-size:12px; border:none; color:white; cursor:pointer; }
.delete-form { display:inline; }
.users-toolbar { display:flex; justify-content:flex-end; margin-bottom:16px; }
.unlock-modal {
    position: fixed;
    inset: 0;
    background: rgba(10, 16, 24, 0.46);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    z-index: 12000;
}
.unlock-modal[hidden] { display:none; }
.unlock-modal-card {
    width: min(440px, 100%);
    padding: 24px;
    border-radius: 24px;
    background: rgba(255,255,255,.98);
    border: 1px solid rgba(31,41,51,.08);
    box-shadow: 0 24px 48px rgba(15,23,42,.18);
}
.unlock-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
</style>

</head>

<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">

<h1>Usuarios</h1>

<?php if ($edit && !empty($data["Nombre"])): ?>
<div class="edit-banner">
    Modificando a: <strong><?= htmlspecialchars($data["Nombre"]) ?></strong>
</div>
<?php endif; ?>

<div class="user-container">

<div class="form-card">

<?php if ($msg !== ""): ?>
<div class="alert"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<form method="POST">

<input type="hidden" name="id" value="<?= htmlspecialchars((string) ($data["id"] ?? "")) ?>">

<?php if (!$edit): ?>
<div class="form-group">
<label>Nombre</label>
<input name="nombre" required>
</div>

<div class="form-group">
<label>Usuario</label>
<input name="usuario" required>
</div>
<?php endif; ?>

<div class="form-group">
<label>Contrasena <?= $edit ? "(opcional)" : "" ?></label>
<input name="contrasena" type="password" <?= $edit ? "" : "required" ?>>
</div>

<div class="form-group">
<label>Extension</label>
<input name="ext" value="<?= htmlspecialchars($data["Ext"] ?? "") ?>" required>
</div>

<div class="form-group">
<label>Grupo</label>
<select name="grupo_select">
<option value="FTD" <?= ($data["grupo_id"] ?? 0) == 1 ? "selected" : "" ?>>FTD</option>
<option value="Rete" <?= ($data["grupo_id"] ?? 0) == 2 ? "selected" : "" ?>>Rete</option>
<option value="Convergente" <?= ($data["grupo_id"] ?? 0) == 3 ? "selected" : "" ?>>Convergente</option>
</select>
</div>

<div class="form-group">
<label>Tipo Usuario</label>
<select name="tipo">
<?php while ($t = $tipos->fetch_assoc()): ?>
<option value="<?= (int) $t["codigo"] ?>" <?= ($data["Tipo"] ?? "") == $t["codigo"] ? "selected" : "" ?>>
<?= htmlspecialchars($t["Grupo"]) ?>
</option>
<?php endwhile; ?>
</select>
</div>

<div class="form-group">
<label>Pertenece</label>
<select name="pertenece">
<?php while ($c = $ciudades->fetch_assoc()): ?>
<option value="<?= htmlspecialchars($c["Nombre"]) ?>" <?= ($data["pertenece"] ?? "") == $c["Nombre"] ? "selected" : "" ?>>
<?= htmlspecialchars($c["Nombre"]) ?>
</option>
<?php endwhile; ?>
</select>
</div>

<div class="form-group">
<label>Telefonia</label>
<select name="telefonia">
<option value="1" <?= (int) ($data["telefonia"] ?? 1) === 1 ? "selected" : "" ?>>AMI</option>
<option value="2" <?= (int) ($data["telefonia"] ?? 1) === 2 ? "selected" : "" ?>>ProFix</option>
</select>
</div>

<button class="btn-primary"><?= $edit ? "Modificar" : "Guardar" ?></button>

</form>

</div>

<div class="table-card">

<h3>Usuarios creados</h3>

<div class="users-toolbar">
    <button type="button" class="btn-primary" id="openUnlockModal">Desbloquear</button>
</div>

<?php if ($unlockMsg !== ""): ?>
<div class="alert-success"><?= htmlspecialchars($unlockMsg) ?></div>
<?php endif; ?>

<?php if ($unlockError !== ""): ?>
<div class="alert error"><?= htmlspecialchars($unlockError) ?></div>
<?php endif; ?>

<table class="table-users">
<thead>
<tr>
<th>Nombre</th>
<th>Usuario</th>
<th>Extension</th>
<th>Ciudad</th>
<th>Telefonia</th>
<th>Acciones</th>
</tr>
</thead>

<tbody>
<?php while ($u = $usuarios->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($u["Nombre"]) ?></td>
<td><?= htmlspecialchars($u["Usuario"]) ?></td>
<td><?= htmlspecialchars($u["Ext"]) ?></td>
<td><?= htmlspecialchars($u["pertenece"] ?? "") ?></td>
<td><?= (int) ($u["telefonia"] ?? 1) === 2 ? "ProFix" : "AMI" ?></td>
<td class="acciones">
<a href="?edit=<?= (int) $u["id"] ?>" class="btn-edit">Editar</a>
<form method="POST" class="delete-form" onsubmit="return confirmarEliminar();">
    <input type="hidden" name="delete_id" value="<?= (int) $u["id"] ?>">
    <button type="submit" class="btn-delete">Eliminar</button>
</form>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>

<div class="pagination">
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<a href="?page=<?= $i ?>" class="<?= $i == $page ? "active" : "" ?>"><?= $i ?></a>
<?php endfor; ?>
</div>

</div>

</div>

</div>
</div>

<div class="unlock-modal" id="unlockModal" hidden>
    <div class="unlock-modal-card">
        <h3>Desbloquear jornada</h3>
        <p>Selecciona el usuario que deseas desbloquear para volver a dejar su salida en <strong>0</strong> en el monitoreo de hoy.</p>
        <form method="POST">
            <div class="form-group">
                <label>Usuario</label>
                <select name="unlock_user" id="unlockUserInput" required>
                    <option value="">Selecciona un usuario</option>
                    <?php foreach ($unlockCandidates as $unlockCandidate): ?>
                        <option value="<?= htmlspecialchars((string) ($unlockCandidate["usuario"] ?? "")) ?>">
                            <?= htmlspecialchars(trim((string) (($unlockCandidate["Nombre"] ?? "") !== "" ? $unlockCandidate["Nombre"] : $unlockCandidate["usuario"]))) ?>
                            <?= !empty($unlockCandidate["h_salida"]) ? ' · salida ' . htmlspecialchars((string) $unlockCandidate["h_salida"]) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (empty($unlockCandidates)): ?>
                <div class="alert">No hay usuarios con jornada cerrada hoy para desbloquear.</div>
            <?php endif; ?>
            <div class="unlock-modal-actions">
                <button type="button" class="btn-secondary" id="closeUnlockModal">Cancelar</button>
                <button type="submit" class="btn-primary" <?= empty($unlockCandidates) ? 'disabled' : '' ?>>Desbloquear</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmarEliminar() {
    return confirm("Seguro que deseas eliminar este usuario?");
}

const unlockModal = document.getElementById('unlockModal');
const openUnlockModal = document.getElementById('openUnlockModal');
const closeUnlockModal = document.getElementById('closeUnlockModal');
const unlockUserInput = document.getElementById('unlockUserInput');

function hideUnlockModal() {
    if (unlockModal) {
        unlockModal.hidden = true;
    }
}

if (openUnlockModal) {
    openUnlockModal.addEventListener('click', function () {
        unlockModal.hidden = false;
        if (unlockUserInput) {
            unlockUserInput.focus();
        }
    });
}

if (closeUnlockModal) {
    closeUnlockModal.addEventListener('click', hideUnlockModal);
}

if (unlockModal) {
    unlockModal.addEventListener('click', function (event) {
        if (event.target === unlockModal) {
            hideUnlockModal();
        }
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        hideUnlockModal();
    }
});
</script>

</body>
</html>
