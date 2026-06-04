<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";

requireLogin();
requirePermission("calendario");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = $_SESSION["pertenece"] ?? "";
$fecha = $_GET["fecha"] ?? date("Y-m-d");
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    $id = (int) $_POST["delete_id"];

    $scopeConditions = [];
    if (in_array($tipo, [2, 3, 7], true)) {
        $res = $conn->query("SELECT Usuario FROM users WHERE id = $userId");
        $u = $res ? $res->fetch_assoc() : null;
        $usuario = $conn->real_escape_string($u["Usuario"] ?? "");
        $scopeConditions[] = "usuario_id = '$usuario'";
    } elseif (in_array($tipo, [4, 5, 8], true)) {
        $scopeConditions[] = "grupo = '$userId'";
    } elseif (in_array($tipo, [9, 10], true)) {
        $res = $conn->query("SELECT Usuario FROM users WHERE pertenece = '".$conn->real_escape_string($pertenece)."'");
        $usuarios = [];

        while ($r = $res->fetch_assoc()) {
            $usuarios[] = "'" . $conn->real_escape_string($r["Usuario"]) . "'";
        }

        $scopeConditions[] = !empty($usuarios) ? "usuario_id IN (" . implode(",", $usuarios) . ")" : "1=0";
    }

    $scopeSql = !empty($scopeConditions) ? " AND " . implode(" AND ", $scopeConditions) : "";
    $conn->query("DELETE FROM citas WHERE id = $id$scopeSql");
    header("Location: " . routeUrl("calendar", ["fecha" => $fecha]));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST["delete_id"])) {
    $titulo = trim($_POST["titulo"] ?? "");
    $fechaPost = trim($_POST["fecha"] ?? "");
    $hora = trim($_POST["hora"] ?? "");
    $tp = trim($_POST["tp"] ?? "");
    $descripcion = $tp;

    if ($tipo === 1 && !empty($_POST["usuario_id"])) {
        $idSeleccionado = (int) $_POST["usuario_id"];
        $resUser = $conn->query("SELECT Usuario, Grupo FROM users WHERE id = $idSeleccionado");
    } else {
        $resUser = $conn->query("SELECT Usuario, Grupo FROM users WHERE id = $userId");
    }

    $dataUser = $resUser ? $resUser->fetch_assoc() : null;

    if ($titulo !== "" && $fechaPost !== "" && $hora !== "" && $tp !== "" && $dataUser) {
        $usuarioFinal = $conn->real_escape_string($dataUser["Usuario"]);
        $grupoFinal = (int) ($dataUser["Grupo"] ?? 0);
        $tituloSafe = $conn->real_escape_string($titulo);
        $descripcionSafe = $conn->real_escape_string($descripcion);
        $fechaSafe = $conn->real_escape_string($fechaPost);
        $horaSafe = $conn->real_escape_string($hora);

        $conn->query("
            INSERT INTO citas (titulo, descripcion, fecha, hora, usuario_id, grupo, creado_en)
            VALUES ('$tituloSafe', '$descripcionSafe', '$fechaSafe', '$horaSafe', '$usuarioFinal', '$grupoFinal', '" . $conn->real_escape_string($fechaHoraBogota) . "')
        ");
    }

    header("Location: " . routeUrl("calendar", ["fecha" => $fechaPost]));
    exit;
}

$where = "WHERE c.fecha = '".$conn->real_escape_string($fecha)."'";

if (in_array($tipo, [2, 3, 7], true)) {
    $res = $conn->query("SELECT Usuario FROM users WHERE id = $userId");
    $u = $res ? $res->fetch_assoc() : null;
    $usuario = $conn->real_escape_string($u["Usuario"] ?? "");
    $where .= " AND c.usuario_id = '$usuario'";
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $where .= " AND c.grupo = '$userId'";
} elseif (in_array($tipo, [9, 10], true)) {
    $res = $conn->query("SELECT Usuario FROM users WHERE pertenece = '".$conn->real_escape_string($pertenece)."'");
    $usuarios = [];

    while ($r = $res->fetch_assoc()) {
        $usuarios[] = "'" . $conn->real_escape_string($r["Usuario"]) . "'";
    }

    $where .= !empty($usuarios) ? " AND c.usuario_id IN (" . implode(",", $usuarios) . ")" : " AND 1=0";
}

$sql = "
    SELECT c.*, u.Nombre
    FROM citas c
    LEFT JOIN users u ON c.usuario_id = u.Usuario
    $where
    ORDER BY c.hora ASC
";
$result = $conn->query($sql);

$prev = date("Y-m-d", strtotime($fecha . " -1 day"));
$next = date("Y-m-d", strtotime($fecha . " +1 day"));
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("calendar.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.agenda-shell {
    display: grid;
    gap: 22px;
}

.agenda-hero {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.agenda-title {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.agenda-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.agenda-nav {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.agenda-nav a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 999px;
    text-decoration: none;
    background: rgba(182, 70, 47, 0.08);
    color: var(--brand-dark);
    font-size: 1.15rem;
}

.agenda-date {
    font-size: clamp(1.7rem, 2.8vw, 2.35rem);
    color: #253242;
}

.agenda-subtitle {
    color: var(--muted);
}

.agenda-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.new-cita-btn {
    min-width: 150px;
}

.agenda-grid {
    display: grid;
    gap: 16px;
}

.agenda-empty {
    padding: 28px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.62);
    border: 1px dashed rgba(31, 41, 51, 0.12);
    color: var(--muted);
}

.cita {
    padding: 22px;
    border-radius: 26px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(31, 41, 51, 0.08);
    box-shadow: 0 18px 32px rgba(84, 62, 34, 0.08);
}

.cita-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 12px;
}

.cita-time {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.1);
    color: #0d5f59;
    font-weight: 700;
}

.cita h3 {
    margin: 6px 0 10px;
    font-size: 1.3rem;
}

.cita small {
    color: var(--muted);
}

.badge {
    display: inline-flex;
    align-items: center;
    margin-top: 4px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(182, 70, 47, 0.08);
    border: 1px solid rgba(182, 70, 47, 0.12);
    font-size: 0.9rem;
    font-weight: 600;
}

.badge a {
    text-decoration: none;
    color: var(--brand-dark);
}

.inline-delete {
    display: inline-flex;
}

.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    padding: 24px 16px;
    background: rgba(27, 22, 17, 0.5);
    backdrop-filter: blur(6px);
    overflow: auto;
    z-index: 999;
}

.modal-box {
    width: min(620px, 100%);
    margin: auto;
    padding: 30px;
    border-radius: 30px;
    background: linear-gradient(180deg, rgba(255, 252, 247, 0.98), rgba(249, 244, 237, 0.96));
    border: 1px solid rgba(31, 41, 51, 0.08);
    box-shadow: 0 30px 80px rgba(44, 32, 18, 0.22);
    color: var(--ink);
}

.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 22px;
}

.modal-head h2 {
    margin-bottom: 6px;
}

.modal-head p {
    color: var(--muted);
}

.modal-close {
    width: 42px;
    height: 42px;
    border-radius: 999px;
    padding: 0;
    font-size: 1.4rem;
    line-height: 1;
    background: rgba(43, 60, 78, 0.08);
    color: #334155;
    box-shadow: none;
}

.form-group {
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    font-weight: 600;
    color: #334155;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 10px;
}

@media (max-width: 760px) {
    .agenda-hero,
    .cita-top,
    .modal-head {
        flex-direction: column;
        align-items: flex-start;
    }

    .agenda-actions,
    .modal-actions {
        width: 100%;
    }

    .agenda-actions > *,
    .modal-actions > * {
        width: 100%;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .modal-box {
        padding: 22px;
        border-radius: 24px;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="agenda-shell">
    <section class="agenda-hero">
        <div class="agenda-title">
            <span class="agenda-kicker"><?= htmlspecialchars(t("calendar.kicker")) ?></span>
            <div class="agenda-nav">
                <a href="?fecha=<?= htmlspecialchars($prev) ?>" aria-label="<?= htmlspecialchars(t("calendar.prev_day")) ?>">&larr;</a>
                <h2 class="agenda-date"><?= htmlspecialchars(date("l d/m/Y", strtotime($fecha))) ?></h2>
                <a href="?fecha=<?= htmlspecialchars($next) ?>" aria-label="<?= htmlspecialchars(t("calendar.next_day")) ?>">&rarr;</a>
            </div>
            <p class="agenda-subtitle"><?= htmlspecialchars(t("calendar.subtitle")) ?></p>
        </div>

        <div class="agenda-actions">
            <button class="btn btn-primary new-cita-btn" type="button" onclick="openModal()">+ <?= htmlspecialchars(t("calendar.new")) ?></button>
        </div>
    </section>

    <section class="agenda-grid">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
            <article class="cita">
                <div class="cita-top">
                    <div>
                        <span class="cita-time"><?= htmlspecialchars(date("H:i", strtotime($row["hora"]))) ?></span>
                        <h3><?= htmlspecialchars($row["titulo"]) ?></h3>
                    </div>

                    <form method="POST" class="inline-delete" onsubmit="return confirm('Eliminar cita?');">
                        <input type="hidden" name="delete_id" value="<?= (int) $row["id"] ?>">
                        <button type="submit" class="btn btn-danger"><?= htmlspecialchars(t("common.delete")) ?></button>
                    </form>
                </div>

                <div class="badge">
                    <a href="<?= htmlspecialchars(routeUrl('lead_details', ['tp' => $row["descripcion"]])) ?>">
                        <?= htmlspecialchars($row["descripcion"]) ?>
                    </a>
                </div>

                <div style="margin-top: 14px;">
                    <small><?= htmlspecialchars(t("calendar.agent")) ?>: <?= htmlspecialchars($row["Nombre"] ?? "") ?></small>
                </div>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="agenda-empty">
                No hay citas programadas para esta fecha. Puedes crear una nueva para empezar la agenda del día.
            </div>
        <?php endif; ?>
    </section>
</div>

</div>
</div>

<div id="modal" class="modal-overlay">
<div class="modal-box">
<div class="modal-head">
    <div>
        <h2><?= htmlspecialchars(t("calendar.new")) ?></h2>
        <p><?= htmlspecialchars(t("calendar.modal_subtitle")) ?></p>
    </div>
    <button type="button" class="modal-close" onclick="closeModal()" aria-label="Cerrar modal">&times;</button>
</div>
<form method="POST">
<input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha) ?>">

<div class="form-group">
<label><?= htmlspecialchars(t("calendar.title_label")) ?> *</label>
<input type="text" name="titulo" placeholder="Ej: Llamada de seguimiento con lead caliente" required>
</div>

<div class="form-group">
<label>TP *</label>
<input type="text" name="tp" placeholder="Ingresa el TP o identificador del lead" required>
</div>

<div class="form-row">
<div class="form-group">
<label><?= htmlspecialchars(t("common.date")) ?> *</label>
<input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>" required>
</div>

<div class="form-group">
<label><?= htmlspecialchars(t("common.time")) ?> *</label>
<input type="time" name="hora" required>
</div>
</div>

<?php if ($tipo === 1): ?>
<div class="form-group">
<label><?= htmlspecialchars(t("calendar.assign_user")) ?></label>
<select name="usuario_id">
<option value=""><?= htmlspecialchars(t("calendar.select_user")) ?></option>
<?php
$resUsers = $conn->query("SELECT id, Nombre FROM users WHERE Tipo IN (2,3,7)");
while ($u = $resUsers->fetch_assoc()):
?>
<option value="<?= (int) $u["id"] ?>"><?= htmlspecialchars($u["Nombre"]) ?></option>
<?php endwhile; ?>
</select>
</div>
<?php endif; ?>

<div class="modal-actions">
<button type="button" class="btn-cancel" onclick="closeModal()"><?= htmlspecialchars(t("common.cancel")) ?></button>
<button type="submit" class="btn-save"><?= htmlspecialchars(t("calendar.save")) ?></button>
</div>
</form>
</div>
</div>

<script>
function openModal(){ document.getElementById("modal").style.display = "flex"; }
function closeModal(){ document.getElementById("modal").style.display = "none"; }
setInterval(() => {
    const modal = document.getElementById("modal");
    if (modal && modal.style.display === "flex") {
        return;
    }
    location.reload();
}, 30000);
</script>

</body>
</html>
