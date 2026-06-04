<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";
require_once dirname(__DIR__, 2) . "/core/app.php";

requireLogin();
requirePermission("monitor");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$userId = (int) ($_SESSION["user_id"] ?? 0);
$pertenece = $_SESSION["pertenece"] ?? "";
$today = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');

$stmtExt = $conn->prepare("SELECT Ext FROM users WHERE id = ?");
$stmtExt->bind_param("i", $userId);
$stmtExt->execute();
$userData = $stmtExt->get_result()->fetch_assoc() ?: [];
$stmtExt->close();
$miExtension = $userData["Ext"] ?? "";
$debugMode = isset($_GET["debug"]) && $_GET["debug"] === "1";

if ($tipo === 1) {
    $sql = "
        SELECT u.id, u.Nombre, u.Ext
        FROM users u
        INNER JOIN monitoreo m ON m.usuario = u.Usuario AND m.fecha = '" . $conn->real_escape_string($today) . "'
    ";
} elseif (in_array($tipo, [4, 5, 8], true)) {
    $sql = "
        SELECT u.id, u.Nombre, u.Ext
        FROM users u
        INNER JOIN monitoreo m ON m.usuario = u.Usuario AND m.fecha = '" . $conn->real_escape_string($today) . "'
        WHERE u.Grupo = '" . $conn->real_escape_string((string) $userId) . "'
    ";
} elseif (in_array($tipo, [9, 10], true)) {
    $sql = "
        SELECT u.id, u.Nombre, u.Ext
        FROM users u
        INNER JOIN monitoreo m ON m.usuario = u.Usuario AND m.fecha = '" . $conn->real_escape_string($today) . "'
        WHERE u.pertenece = '" . $conn->real_escape_string($pertenece) . "'
    ";
} else {
    $sql = "
        SELECT u.id, u.Nombre, u.Ext
        FROM users u
        INNER JOIN monitoreo m ON m.usuario = u.Usuario AND m.fecha = '" . $conn->real_escape_string($today) . "'
        WHERE u.id = '" . $conn->real_escape_string((string) $userId) . "'
    ";
}

$sql .= " ORDER BY u.Nombre ASC";

$agentes = [];
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $r['Ext'] = preg_replace('/\D+/', '', trim((string) ($r['Ext'] ?? '')));
        if ($r['Ext'] === '') {
            continue;
        }
        $agentes[] = $r;
    }
}

$informeSql = str_replace(
    "SELECT u.id, u.Nombre, u.Ext",
    "SELECT u.id, u.Nombre, u.Ext, m.h_entrada, m.h_salida, m.almuerzo, m.descanso, m.formacion, m.bano, m.estado",
    $sql
);
$informeDiario = [];
$resInforme = $conn->query($informeSql);
if ($resInforme) {
    while ($row = $resInforme->fetch_assoc()) {
        $row['Ext'] = preg_replace('/\D+/', '', trim((string) ($row['Ext'] ?? '')));
        if ($row['Ext'] === '') {
            continue;
        }
        $informeDiario[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("monitor.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.monitor-shell {
    display: grid;
    gap: 18px;
}

.monitor-hero {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.monitor-copy {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.monitor-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.monitor-copy p {
    color: var(--muted);
}

.monitor-summary {
    color: var(--muted);
}

.monitor-summary strong {
    color: var(--brand);
}

.monitor-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    font-weight: 700;
    flex-wrap: wrap;
}

.status-pill::before {
    content: "";
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: currentColor;
}

.status-pill.is-free {
    background: rgba(31, 143, 98, 0.12);
    color: #16734f;
}

.status-pill.is-busy {
    background: rgba(209, 67, 67, 0.12);
    color: #a53030;
}

.status-pill.is-away {
    background: rgba(217, 119, 6, 0.14);
    color: #9a5a06;
}

.status-pill.is-offline {
    background: rgba(71, 85, 105, 0.14);
    color: #475569;
}

.status-duration {
    display: inline-block;
    margin-left: 4px;
    font-size: 0.85em;
    font-weight: 600;
    opacity: 0.9;
}

.monitor-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.monitor-tp-link {
    color: var(--accent, #4f8cff);
    font-weight: 700;
    text-decoration: none;
}

.monitor-tp-link:hover {
    text-decoration: underline;
}

.monitor-debug {
    padding: 18px 20px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.monitor-debug h3 {
    margin-bottom: 10px;
}

.monitor-debug p {
    margin-bottom: 10px;
    color: var(--muted);
}

.monitor-debug pre {
    overflow: auto;
    padding: 16px;
    border-radius: 18px;
    background: rgba(31, 41, 51, 0.06);
    color: #334155;
    white-space: pre-wrap;
    word-break: break-word;
}

.report-modal .modal-box {
    width: min(1120px, 100%);
}

.report-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 18px;
}

.report-modal-head p {
    color: var(--muted);
}

.report-table-wrap {
    overflow-x: auto;
}

.report-table {
    min-width: 980px;
}

.report-table td,
.report-table th {
    white-space: nowrap;
}

@media (max-width: 860px) {
    .monitor-hero {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="monitor-shell">
<section class="monitor-hero">
    <div class="monitor-copy">
        <span class="monitor-kicker"><?= htmlspecialchars(t("monitor.kicker")) ?></span>
        <h1><?= htmlspecialchars(t("monitor.title")) ?></h1>
        <p><?= htmlspecialchars(t("monitor.subtitle")) ?></p>
    </div>
    <div class="monitor-header-actions">
        <div class="monitor-summary"><?= htmlspecialchars(t("monitor.visible_agents")) ?>: <strong><?= count($agentes) ?></strong></div>
        <button type="button" class="btn-filter" onclick="openReportModal()"><?= htmlspecialchars(t("monitor.daily_report")) ?></button>
    </div>
</section>

<div class="table-container">
<table class="leads-table">
<thead>
<tr>
<th><?= htmlspecialchars(t("common.name")) ?></th>
<th><?= htmlspecialchars(t("common.extension")) ?></th>
<th>TP actual</th>
<th><?= htmlspecialchars(t("common.status")) ?></th>
<th><?= htmlspecialchars(t("common.actions")) ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($agentes as $a): ?>
<tr>
<td><?= htmlspecialchars($a["Nombre"]) ?></td>
<td><?= htmlspecialchars($a["Ext"]) ?></td>
<td id="tp_actual_<?= htmlspecialchars($a["Ext"]) ?>">-</td>
<td id="estado_<?= htmlspecialchars($a["Ext"]) ?>"><span class="status-pill is-free"><?= htmlspecialchars(t("monitor.checking")) ?></span></td>
<td class="monitor-actions">
<button class="btn-call" onclick="spy('<?= htmlspecialchars($a["Ext"]) ?>')">Spy</button>
<button class="btn-call" onclick="whisper('<?= htmlspecialchars($a["Ext"]) ?>')">Whisper</button>
<button class="btn-hangup" onclick="colgar('<?= htmlspecialchars($a["Ext"]) ?>')"><?= htmlspecialchars(t("monitor.hangup")) ?></button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($debugMode): ?>
<section class="monitor-debug">
    <h3><?= htmlspecialchars(t("monitor.debug_title")) ?></h3>
    <p><?= htmlspecialchars(t("monitor.debug_help")) ?></p>
    <pre id="monitor-debug-output"><?= htmlspecialchars(t("monitor.loading_debug")) ?></pre>
</section>
<?php endif; ?>
</div>
</div>
</div>

<div id="reportModal" class="modal report-modal">
<div class="modal-box">
    <div class="report-modal-head">
        <div>
            <h3><?= htmlspecialchars(t("monitor.report_title")) ?></h3>
            <p><?= htmlspecialchars(t("monitor.report_subtitle")) ?> <?= htmlspecialchars($today) ?>.</p>
        </div>
        <button type="button" class="btn-clear" onclick="closeReportModal()"><?= htmlspecialchars(t("common.close")) ?></button>
    </div>

    <div class="report-table-wrap">
        <table class="leads-table report-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t("common.name")) ?></th>
                    <th><?= htmlspecialchars(t("common.extension")) ?></th>
                    <th><?= htmlspecialchars(t("common.entry")) ?></th>
                    <th><?= htmlspecialchars(t("common.exit")) ?></th>
                    <th><?= htmlspecialchars(t("common.lunch")) ?></th>
                    <th><?= htmlspecialchars(t("common.break")) ?></th>
                    <th><?= htmlspecialchars(t("common.training")) ?></th>
                    <th><?= htmlspecialchars(t("common.bathroom")) ?></th>
                    <th><?= htmlspecialchars(t("common.status")) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($informeDiario)): ?>
                    <?php foreach ($informeDiario as $fila): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($fila["Nombre"] ?? "")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["Ext"] ?? "")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["h_entrada"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["h_salida"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["almuerzo"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["descanso"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["formacion"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["bano"] ?? "0")) ?></td>
                        <td><?= htmlspecialchars((string) ($fila["estado"] ?? "Puesto")) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9"><?= htmlspecialchars(t("monitor.no_records")) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script>
const monitorStateTimers = {};
const monitorLeadDetailsBaseUrl = <?= json_encode(routeUrl('lead_details')) ?>;

function formatMonitorDuration(totalSeconds) {
    const seconds = Math.max(0, Number(totalSeconds || 0));
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return [hrs, mins, secs].map(value => String(value).padStart(2, "0")).join(":");
}

function postAction(url, body) {
    return fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body
    }).then(async response => {
        const text = await response.text();
        if (!response.ok) {
            throw new Error(text || "No fue posible completar la accion.");
        }
        return text;
    }).then(data => alert(data)).catch(err => alert(err.message));
}

function spy(ext) {
    postAction("monitor/spy.php", `extensionToSpy=${encodeURIComponent(ext)}&spyExtension=${encodeURIComponent("<?= htmlspecialchars($miExtension) ?>")}`);
}

function whisper(ext) {
    postAction("monitor/whisper.php", `extensionToSpy=${encodeURIComponent(ext)}&spyExtension=${encodeURIComponent("<?= htmlspecialchars($miExtension) ?>")}`);
}

function colgar(ext) {
    postAction("monitor/colgar.php", `extension=${encodeURIComponent(ext)}`);
}

function openReportModal() {
    document.getElementById("reportModal").style.display = "block";
}

function closeReportModal() {
    document.getElementById("reportModal").style.display = "none";
}

function actualizarEstados() {
    fetch(<?= json_encode(appUrl('monitor/estado_extensiones.php') . ($debugMode ? '?debug=1' : '')) ?>)
        .then(async r => {
            const raw = await r.text();
            try {
                return JSON.parse(raw);
            } catch (error) {
                console.error("Monitor JSON invalido", {
                    status: r.status,
                    url: r.url,
                    raw,
                });
                throw error;
            }
        })
        .then(data => {
            const ahora = Date.now();
            document.querySelectorAll("[id^='estado_']").forEach(el => {
                let ext = el.id.replace("estado_", "");
                let estado = data.estados && data.estados[ext] ? data.estados[ext] : null;
                let tpActual = data.tp_actual && data.tp_actual[ext] ? data.tp_actual[ext] : "";
                let label = estado && estado.label ? estado.label : <?= json_encode(t("monitor.free")) ?>;
                let tone = estado && estado.tone ? estado.tone : "free";
                let code = estado && estado.code ? estado.code : "libre";
                let cssClass = "status-pill is-free";
                let durationText = "";
                let tpCell = document.getElementById(`tp_actual_${ext}`);

                if (tone === "busy") cssClass = "status-pill is-busy";
                if (tone === "away") cssClass = "status-pill is-away";
                if (tone === "offline") cssClass = "status-pill is-offline";

                if (code === "llamada") {
                    durationText = estado && estado.duration_label ? estado.duration_label : "";
                    monitorStateTimers[ext] = {
                        code,
                        startedAt: ahora - (Number(estado && estado.duration_seconds ? estado.duration_seconds : 0) * 1000),
                    };
                } else if (code === "libre") {
                    if (!monitorStateTimers[ext] || monitorStateTimers[ext].code !== "libre") {
                        monitorStateTimers[ext] = { code: "libre", startedAt: ahora };
                    }
                    durationText = formatMonitorDuration(Math.floor((ahora - monitorStateTimers[ext].startedAt) / 1000));
                } else {
                    durationText = estado && estado.duration_label ? estado.duration_label : "";
                    monitorStateTimers[ext] = { code, startedAt: ahora };
                }

                const durationHtml = durationText ? `<span class="status-duration">${durationText}</span>` : "";
                el.innerHTML = `<span class="${cssClass}">${label}${durationHtml}</span>`;
                if (tpCell) {
                    if (tpActual) {
                        const tpEncoded = encodeURIComponent(tpActual);
                        tpCell.innerHTML = `<a class="monitor-tp-link" href="${monitorLeadDetailsBaseUrl}?tp=${tpEncoded}" target="_blank" rel="noopener noreferrer">${tpActual}</a>`;
                    } else {
                        tpCell.textContent = "-";
                    }
                }
            });

            let debugOutput = document.getElementById("monitor-debug-output");
            if (debugOutput && data.debug) {
                debugOutput.textContent = JSON.stringify(data.debug, null, 2);
            }
        })
        .catch((error) => {
            console.error("No fue posible actualizar monitoreo", error);
            document.querySelectorAll("[id^='estado_']").forEach(el => {
                el.innerHTML = '<span class="status-pill is-busy"><?= htmlspecialchars(t("monitor.no_connection")) ?></span>';
                let ext = el.id.replace("estado_", "");
                let tpCell = document.getElementById(`tp_actual_${ext}`);
                if (tpCell) {
                    tpCell.textContent = "-";
                }
            });

            let debugOutput = document.getElementById("monitor-debug-output");
            if (debugOutput) {
                debugOutput.textContent = "No fue posible obtener el diagnostico desde monitor/estado_extensiones.php";
            }
        });
}

setInterval(actualizarEstados, 3000);
actualizarEstados();
</script>

</body>
</html>
