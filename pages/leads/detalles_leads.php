<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("leads");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$nombre = $_SESSION["nombre"] ?? "";
$grupoId = (int) ($_SESSION["grupo_id"] ?? 0);
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = $_SESSION["pertenece"] ?? "";
$telefonia = (int) ($_SESSION["telefonia"] ?? 1);

if ($userId > 0) {
    $stmtTelefonia = $conn->prepare("SELECT telefonia FROM users WHERE id = ? LIMIT 1");
    if ($stmtTelefonia instanceof mysqli_stmt) {
        $stmtTelefonia->bind_param("i", $userId);
        $stmtTelefonia->execute();
        $telefoniaRow = $stmtTelefonia->get_result()->fetch_assoc() ?: null;
        $stmtTelefonia->close();

        if ($telefoniaRow && isset($telefoniaRow["telefonia"])) {
            $telefonia = (int) $telefoniaRow["telefonia"];
        }
    }
}

$verTelefono = (($_SESSION["phone"] ?? 0) == 1);
$verCorreo = (($_SESSION["mail"] ?? 0) == 1);

$redirectDelay = 5;
$redirectMessage = null;

$tp = trim($_GET["tp"] ?? "");
if ($tp === "") {
    $redirectMessage = "No se recibi\u00f3 un TP v\u00e1lido para consultar el lead.";
}

if ($redirectMessage === null) {
    $tpSafe = $conn->real_escape_string($tp);
    $where = "TP = '$tpSafe'";

    if (in_array($tipo, [9, 10], true)) {
        $where .= " AND pertenece = '" . $conn->real_escape_string($pertenece) . "'";
    } elseif (in_array($tipo, [4, 5, 8], true)) {
        $where .= " AND EXISTS (
            SELECT 1 FROM users u
            WHERE LOWER(TRIM(clientes.Asignado)) = LOWER(TRIM(u.Nombre))
            AND u.Grupo = '" . $conn->real_escape_string((string) $userId) . "'
        )";
    } elseif (!in_array($tipo, [1, 9, 10, 4, 5, 8], true)) {
        $where .= " AND Asignado = '" . $conn->real_escape_string($nombre) . "'";
    }

    $resCliente = $conn->query("
        SELECT id, TP, Nombre, Apellido, Numero, Correo, Estado, Asignado, Pais
        FROM clientes
        WHERE $where
        LIMIT 1
    ");
    if (!$resCliente || $resCliente->num_rows === 0) {
        $redirectMessage = "Cliente no encontrado o fuera de tu alcance.";
    } else {
        $cliente = $resCliente->fetch_assoc();
    }
}

if ($redirectMessage === null && $_SERVER["REQUEST_METHOD"] === "POST") {
    $gestion = trim($_POST["gestion"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $fecha = date("Y-m-d H:i:s");

    if ($gestion !== "" && $descripcion !== "") {
        $gestionSafe = $conn->real_escape_string($gestion);
        $descripcionSafe = $conn->real_escape_string($descripcion);

        $conn->query("
            INSERT INTO notas (TP, UltimaGestion, FechaUltimaGestion, Descripcion, user, grupo_id, id_cliente)
            VALUES (
                '$tpSafe',
                '$gestionSafe',
                '$fecha',
                '$descripcionSafe',
                '".$conn->real_escape_string($nombre)."',
                '$grupoId',
                '".(int) $cliente["id"]."'
            )
        ");

        $conn->query("
            UPDATE clientes
            SET UltimaGestion = '$gestionSafe', FechaUltimaGestion = '$fecha'
            WHERE TP = '$tpSafe'
        ");

        $nombreCliente = trim((string) (($cliente["Nombre"] ?? "") . " " . ($cliente["Apellido"] ?? "")));
        $asignadoActual = (string) ($cliente["Asignado"] ?? "");
        $usuarioSession = trim((string) ($_SESSION["usuario"] ?? $nombre));
        $descripcionHistorico = mb_substr($descripcion, 0, 250);
        $memoHistorico = "Cambio de gestion a ($gestion). Nota: $descripcionHistorico";

        $conn->query("
            INSERT INTO historico (tp, nombre_cliente, asignado, usuario_session, fecha_hora, accion, modulo, memo)
            VALUES (
                '$tpSafe',
                '" . $conn->real_escape_string($nombreCliente) . "',
                '" . $conn->real_escape_string($asignadoActual) . "',
                '" . $conn->real_escape_string($usuarioSession) . "',
                '$fecha',
                'GESTION',
                'LEADS',
                '" . $conn->real_escape_string($memoHistorico) . "'
            )
        ");

        header("Location: " . routeUrl("lead_details", ["tp" => $tp]));
        exit;
    }
}

$resNotas = $conn->query("
    SELECT UltimaGestion, Descripcion, FechaUltimaGestion, user
    FROM notas
    WHERE TP = '$tpSafe'
    ORDER BY FechaUltimaGestion DESC
");
$totalNotas = $resNotas instanceof mysqli_result ? $resNotas->num_rows : 0;
$gestiones = [];
$resEstados = $conn->query("SELECT Estado FROM estados WHERE grupo_id = '$grupoId'");
while ($row = $resEstados->fetch_assoc()) {
    $gestiones[] = $row["Estado"];
}

$ids = $_SESSION["leads_ids"] ?? [];
$index = array_search($tp, $ids, true);
$prev = $index !== false ? ($ids[$index - 1] ?? null) : null;
$next = $index !== false ? ($ids[$index + 1] ?? null) : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Detalle Lead</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<?php if ($telefonia === 2): ?>
<script src="https://profix-voip.com/widget/profix-cc.js"></script>
<?php endif; ?>
<style>
.notes-container{max-height:400px;overflow-y:auto;}
.btn-call{background:#22c55e;color:white;border:none;padding:10px 18px;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600;transition:0.2s;}
.btn-call:hover{background:#16a34a;}
.btn-call-profix{background:#2563eb;color:white;border:none;padding:10px 18px;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600;transition:0.2s;}
.btn-call-profix:hover{background:#1d4ed8;}
.btn-clean-extension{background:#0f766e;color:white;border:none;padding:10px 18px;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600;transition:0.2s;}
.btn-clean-extension:hover{background:#0d5f59;}
.actions-right{display:flex;align-items:center;gap:10px;}
.redirect-state {
    min-height: calc(100vh - 160px);
    display: grid;
    place-items: center;
}
.redirect-card {
    width: min(560px, 100%);
    padding: 32px;
    border-radius: 30px;
    background: linear-gradient(180deg, rgba(255, 252, 247, 0.98), rgba(249, 244, 237, 0.95));
    border: 1px solid rgba(31, 41, 51, 0.08);
    box-shadow: 0 24px 60px rgba(44, 32, 18, 0.16);
}
.redirect-kicker {
    display: inline-flex;
    margin-bottom: 12px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(182, 70, 47, 0.08);
    border: 1px solid rgba(182, 70, 47, 0.12);
    color: var(--brand-dark);
    font-size: 0.78rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.redirect-card h1 {
    margin-bottom: 10px;
}
.redirect-card p {
    margin-bottom: 14px;
    color: var(--muted);
}
.redirect-countdown {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 46px;
    height: 46px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.12);
    color: #0d5f59;
    font-weight: 700;
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<?php if ($redirectMessage !== null): ?>
<div class="redirect-state">
    <div class="redirect-card">
        <span class="redirect-kicker">Lead no disponible</span>
        <h1>No pudimos abrir este registro</h1>
        <p><?= htmlspecialchars($redirectMessage) ?></p>
        <p>En <span id="redirect-countdown" class="redirect-countdown"><?= $redirectDelay ?></span> segundos seras redirigido a Leads.</p>
        <a href="<?= htmlspecialchars(routeUrl('leads')) ?>" class="btn-back">Ir ahora</a>
    </div>
</div>
<?php else: ?>

<h1>Detalle del Lead</h1>

<div class="top-actions">
    <div class="actions-left">
        <a href="<?= htmlspecialchars(routeUrl('leads')) ?>" class="btn-back">&larr; Volver a Leads</a>
        <?php if ($prev): ?><a class="btn-filter" href="<?= htmlspecialchars(routeUrl('lead_details', ['tp' => $prev])) ?>">&larr;</a><?php endif; ?>
        <?php if ($next): ?><a class="btn-filter" href="<?= htmlspecialchars(routeUrl('lead_details', ['tp' => $next])) ?>">&rarr;</a><?php endif; ?>
    </div>

    <div class="actions-right">
        <?php if ($telefonia === 2): ?>
        <button class="btn-call-profix" onclick="llamarProfix('<?= htmlspecialchars($cliente['TP']) ?>')">Llamar ProFix</button>
        <?php else: ?>
        <button class="btn-call" onclick="llamarAmi('<?= htmlspecialchars($cliente['TP']) ?>')">Llamar</button>
        <?php endif; ?>
        <button class="btn-clean-extension" onclick="limpiarExtension()">Limpiar extension</button>
        <button class="btn-hangup" onclick="colgar()">Colgar</button>
        <div><strong><?= $index !== false ? $index + 1 : 1 ?> de <?= count($ids) ?></strong></div>
    </div>
</div>

<div class="table-container">
<h3>Informacion del Cliente</h3>
<div class="grid-2">
<div>
<p><strong>TP:</strong> <?= htmlspecialchars($cliente["TP"]) ?></p>
<p><strong>Nombre:</strong> <?= htmlspecialchars($cliente["Nombre"]) ?></p>
<p><strong>Apellido:</strong> <?= htmlspecialchars($cliente["Apellido"]) ?></p>
<p><strong>Telefono:</strong> <?= $verTelefono ? htmlspecialchars($cliente["Numero"]) : "********" ?></p>
</div>

<div>
<p><strong>Email:</strong> <?= $verCorreo ? htmlspecialchars($cliente["Correo"]) : "********" ?></p>
<p><strong>Estado:</strong> <?= htmlspecialchars($cliente["Estado"]) ?></p>
<p><strong>Asignado:</strong> <?= htmlspecialchars($cliente["Asignado"]) ?></p>
<p><strong>Pais:</strong> <?= htmlspecialchars($cliente["Pais"]) ?></p>
</div>
</div>
</div>

<div class="table-container" style="margin-top:20px;">
<h3>Nueva Gestion</h3>
<form method="POST">
<select name="gestion" required style="width:100%; padding:10px;">
<option value="">Seleccione...</option>
<?php foreach ($gestiones as $g): ?>
<option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
<?php endforeach; ?>
</select>

<br><br>
<textarea name="descripcion" required style="width:100%; height:100px;"></textarea>

<br><br>
<button>Guardar Gestion</button>
</form>
</div>

<div class="table-container notes-container" style="margin-top:20px;">
<h3>Historial (<?= (int) $totalNotas ?>)</h3>
<?php while ($n = $resNotas->fetch_assoc()): ?>
<div style="padding:10px; border-bottom:1px solid #1e293b;">
<strong><?= htmlspecialchars($n["UltimaGestion"]) ?></strong>
<p><?= nl2br(htmlspecialchars($n["Descripcion"])) ?></p>
<small>
    <?= htmlspecialchars($n["FechaUltimaGestion"]) ?>
    <?php if (!empty($n["user"])): ?>
        · <?= htmlspecialchars($n["user"]) ?>
    <?php endif; ?>
</small>
</div>
<?php endwhile; ?>
</div>
<?php endif; ?>

</div>
</div>

<script>
<?php if ($redirectMessage !== null): ?>
let countdown = <?= $redirectDelay ?>;
const countdownEl = document.getElementById("redirect-countdown");
const redirectTimer = window.setInterval(() => {
    countdown -= 1;
    if (countdownEl && countdown > 0) {
        countdownEl.textContent = countdown;
    }
    if (countdown <= 0) {
        window.clearInterval(redirectTimer);
        window.location.href = <?= json_encode(routeUrl('leads')) ?>;
    }
}, 1000);
<?php else: ?>
async function colgar() {
    try {
        const res = await fetch(<?= json_encode(appUrl('core/colgar_llamada.php')) ?>);
        const data = await res.json();
        console.log("Colgado:", data);
        return data;
    } catch (error) {
        console.log("Error al colgar");
        return null;
    }
}

async function llamarAmi(tp) {
    fetch(`${<?= json_encode(appUrl('core/llamada.php')) ?>}?tp=${encodeURIComponent(tp)}`)
        .then(async response => {
            const data = await response.json().catch(() => null);
            console.log("Debug llamada:", data);
            if (!response.ok) {
                throw new Error(data && data.message ? data.message : "Error en llamada");
            }
            return data;
        })
        .catch((error) => console.log("Error en llamada", error));
}

async function llamarProfix(tp) {
    try {
        const response = await fetch(`${<?= json_encode(appUrl('core/llamada_profix.php')) ?>}?tp=${encodeURIComponent(tp)}`);
        const data = await response.json().catch(() => null);
        console.log("Debug ProFix:", data);

        if (!response.ok || !data || !data.ok) {
            throw new Error(data && data.message ? data.message : "No fue posible preparar la llamada ProFix");
        }

        if (typeof window.ProFix === "undefined" || typeof window.ProFix.call !== "function") {
            throw new Error("El widget de ProFix no esta disponible en este navegador.");
        }

        window.ProFix.call(String(data.numero || ""));
    } catch (error) {
        console.log("Error en ProFix", error);
        alert(error && error.message ? error.message : "No fue posible iniciar la llamada con ProFix.");
    }
}

async function limpiarExtension() {
    try {
        const response = await fetch(<?= json_encode(appUrl('core/limpiar_extension.php')) ?>, {
            method: 'POST'
        });
        const data = await response.json().catch(() => null);
        console.log("Limpiar extension:", data);

        if (!response.ok) {
            throw new Error(data && data.message ? data.message : "No fue posible limpiar la extension");
        }

        alert(data && data.message ? data.message : "Extension limpiada correctamente.");
    } catch (error) {
        console.log("Error al limpiar extension", error);
        alert(error && error.message ? error.message : "No fue posible limpiar la extension.");
    }
}
<?php endif; ?>
</script>

</body>
</html>
