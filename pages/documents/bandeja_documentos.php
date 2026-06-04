<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";
require_once dirname(__DIR__, 2) . "/core/documentos.php";
require_once dirname(__DIR__, 2) . "/core/app.php";

requireLogin();
requirePermission("documentos_review");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));
if (!in_array($tipo, [9, 10], true)) {
    http_response_code(403);
    exit("Acceso denegado");
}

$documents = obtenerSolicitudesDocumentoFloor($conn, $pertenece);
$summary = obtenerResumenDocumentosFloor($conn, $pertenece);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t('documents.hub_title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.documents-hub-shell{display:grid;gap:18px}
.documents-hub-hero,.documents-hub-card{padding:22px 24px;border-radius:28px;background:rgba(255,255,255,.74);border:1px solid rgba(31,41,51,.08)}
.documents-hub-kicker{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.documents-hub-hero p,.documents-hub-empty,.documents-hub-note{color:var(--muted)}
.documents-hub-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.documents-hub-stat{padding:16px 18px;border-radius:22px;background:linear-gradient(180deg,rgba(255,255,255,.9),rgba(248,249,252,.76));border:1px solid rgba(31,41,51,.08)}
.documents-hub-stat-value{font-size:1.8rem;font-weight:800;color:var(--ink)}
.documents-hub-stat-label{font-size:.82rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.documents-hub-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.documents-hub-list{display:grid;gap:12px;max-height:58vh;overflow:auto;padding-right:4px}
.documents-hub-item{padding:16px 18px;border-radius:22px;background:linear-gradient(180deg,rgba(255,255,255,.92),rgba(248,249,252,.8));border:1px solid rgba(31,41,51,.08);display:grid;gap:10px}
.documents-hub-item.is-pending{border-color:rgba(235,136,42,.3);box-shadow:0 12px 22px rgba(235,136,42,.08)}
.documents-hub-item-head,.documents-hub-item-meta{display:flex;align-items:center;justify-content:space-between;gap:12px}
.documents-hub-item-title{font-weight:800;color:var(--ink)}
.documents-hub-chip{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:999px;font-size:.82rem;font-weight:700}
.documents-hub-chip.is-pending{background:rgba(235,136,42,.14);color:#a85d10}
.documents-hub-chip.is-progress{background:rgba(109,66,216,.12);color:#6d42d8}
.documents-hub-chip.is-sent{background:rgba(6,118,71,.12);color:#067647}
.documents-hub-chip.is-rejected{background:rgba(180,35,24,.12);color:#b42318}
.documents-hub-item-meta{font-size:.9rem;color:var(--muted)}
.documents-hub-actions{display:flex;justify-content:flex-end}
@media (max-width:960px){.documents-hub-stats{grid-template-columns:1fr}.documents-hub-toolbar{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div class="dashboard">
    <?php include dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
    <div class="main">
        <?php include dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>
        <div class="documents-hub-shell">
            <section class="documents-hub-hero">
                <div class="documents-hub-kicker"><?= htmlspecialchars(t('documents.review_kicker')) ?></div>
                <h1><?= htmlspecialchars(t('documents.hub_title')) ?></h1>
                <p><?= htmlspecialchars(t('documents.hub_subtitle')) ?></p>
            </section>

            <section class="documents-hub-stats">
                <article class="documents-hub-stat">
                    <div class="documents-hub-stat-label"><?= htmlspecialchars(t('documents.hub_pending')) ?></div>
                    <div class="documents-hub-stat-value" id="documentsHubPending"><?= (int) ($summary['pending_count'] ?? 0) ?></div>
                </article>
                <article class="documents-hub-stat">
                    <div class="documents-hub-stat-label"><?= htmlspecialchars(t('documents.hub_active')) ?></div>
                    <div class="documents-hub-stat-value" id="documentsHubActive"><?= (int) ($summary['active_count'] ?? 0) ?></div>
                </article>
                <article class="documents-hub-stat">
                    <div class="documents-hub-stat-label"><?= htmlspecialchars(t('documents.hub_city')) ?></div>
                    <div class="documents-hub-stat-value" style="font-size:1.2rem"><?= htmlspecialchars($pertenece !== '' ? $pertenece : '-') ?></div>
                </article>
            </section>

            <section class="documents-hub-card">
                <div class="documents-hub-toolbar">
                    <div>
                        <h3><?= htmlspecialchars(t('documents.review_today')) ?></h3>
                        <div class="documents-hub-note"><?= htmlspecialchars(t('documents.hub_note')) ?></div>
                    </div>
                    <a class="btn-primary" href="<?= htmlspecialchars(routeUrl('documents_review')) ?>"><?= htmlspecialchars(t('documents.hub_open_review')) ?></a>
                </div>
                <div class="documents-hub-list" id="documentsHubList"></div>
            </section>
        </div>
    </div>
</div>

<script>
const documentsHubState = {
    items: <?= json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    feedUrl: <?= json_encode(appUrl('core/documentos_feed.php')) ?>,
    notificationsUrl: <?= json_encode(appUrl('core/documentos_notifications.php')) ?>,
    reviewUrl: <?= json_encode(routeUrl('documents_review')) ?>,
    emptyText: <?= json_encode(t('documents.review_empty')) ?>,
    openText: <?= json_encode(t('documents.hub_open_review')) ?>,
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function itemClassByStatus(status) {
    if (status === 'Pendiente') return 'is-pending';
    if (status === 'En Proceso' || status === 'En Revision') return 'is-progress';
    if (status === 'Enviado') return 'is-sent';
    if (status === 'Rechazado') return 'is-rejected';
    return 'is-progress';
}

function renderDocumentsHub() {
    const list = document.getElementById('documentsHubList');
    if (!list) return;

    if (!Array.isArray(documentsHubState.items) || documentsHubState.items.length === 0) {
        list.innerHTML = '<div class="documents-hub-empty">' + escapeHtml(documentsHubState.emptyText) + '</div>';
        return;
    }

    list.innerHTML = documentsHubState.items.map((item) => {
        const status = item.estado || '';
        const itemClass = itemClassByStatus(status);
        return `
            <article class="documents-hub-item ${itemClass}">
                <div class="documents-hub-item-head">
                    <div class="documents-hub-item-title">${escapeHtml(item.tipo_doc || '-')}</div>
                    <span class="documents-hub-chip ${itemClass}">${escapeHtml(status || '-')}</span>
                </div>
                <div class="documents-hub-item-meta">
                    <span>${escapeHtml(item.asesor_nombre || '-')}</span>
                    <span>${escapeHtml(item.fecha_creado || '-')}</span>
                </div>
                <div class="documents-hub-note">${escapeHtml(item.observacion_documento || '')}</div>
                <div class="documents-hub-actions">
                    <a class="btn-secondary" href="${documentsHubState.reviewUrl}">${escapeHtml(documentsHubState.openText)}</a>
                </div>
            </article>
        `;
    }).join('');
}

async function refreshDocumentsHub() {
    try {
        const [feedResponse, notificationsResponse] = await Promise.all([
            fetch(documentsHubState.feedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }),
            fetch(documentsHubState.notificationsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }),
        ]);

        const feedData = await feedResponse.json();
        const notificationsData = await notificationsResponse.json();

        if (feedResponse.ok && feedData.ok) {
            documentsHubState.items = Array.isArray(feedData.documents) ? feedData.documents : [];
            renderDocumentsHub();
        }

        if (notificationsResponse.ok && notificationsData.ok && notificationsData.summary) {
            document.getElementById('documentsHubPending').textContent = Number(notificationsData.summary.pending_count || 0);
            document.getElementById('documentsHubActive').textContent = Number(notificationsData.summary.active_count || 0);
        }
    } catch (error) {
        console.log('Documents hub refresh error', error);
    }
}

renderDocumentsHub();
setInterval(refreshDocumentsHub, 15000);
</script>
</body>
</html>
