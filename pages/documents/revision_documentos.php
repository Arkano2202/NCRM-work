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

function claseBotonDocumentoReview(string $estado): string
{
    $estado = trim($estado);

    if ($estado === 'Pendiente') {
        return 'is-pending';
    }

    if ($estado === 'En Proceso' || $estado === 'En Revision') {
        return 'is-progress';
    }

    if ($estado === 'Rechazado') {
        return 'is-rejected';
    }

    if ($estado === 'Enviado') {
        return 'is-sent';
    }

    return 'is-progress';
}

$solicitudes = obtenerSolicitudesDocumentoFloor($conn, $pertenece);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t('documents.review_title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.documents-review-shell { display:grid; gap:18px; }
.documents-review-hero,
.documents-review-card {
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(31, 41, 51, 0.08);
}
.documents-review-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}
.documents-review-hero p,
.documents-review-note { color: var(--muted); }
.documents-review-toolbar {
    display:flex;
    justify-content:flex-end;
    gap: 10px;
    margin-bottom: 16px;
}
.documents-review-table td:last-child,
.documents-review-table th:last-child {
    text-align: right;
}
.documents-review-list-wrap {
    max-height: 504px;
    overflow-y: auto;
    border-radius: 22px;
}
.documents-review-open {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding: 8px 14px;
    border-radius: 999px;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: 700;
}
.documents-review-open.is-pending {
    background: linear-gradient(135deg, #b88a12, #e3b341);
}
.documents-review-open.is-progress {
    background: linear-gradient(135deg, #6d42d8, #8d5cf6);
}
.documents-review-open.is-rejected {
    background: linear-gradient(135deg, #b42318, #f04438);
}
.documents-review-open.is-sent {
    background: linear-gradient(135deg, #067647, #12b76a);
}
.documents-review-panel {
    position: fixed;
    top: 32px;
    right: 24px;
    width: min(520px, calc(100vw - 48px));
    max-height: calc(100vh - 64px);
    padding: 22px;
    border-radius: 28px;
    background: var(--panel);
    border: 1px solid rgba(31, 41, 51, 0.14);
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
    z-index: 1600;
    display: none;
    overflow-y: auto;
}
.documents-review-panel.is-open { display: block; }
.documents-review-overlay {
    position: fixed;
    inset: 0;
    background: rgba(12, 18, 28, 0.42);
    backdrop-filter: blur(6px);
    z-index: 1500;
    display: none;
}
.documents-review-overlay.is-open { display:block; }
.documents-review-panel-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap: 16px;
    margin-bottom: 16px;
}
.documents-review-panel h3 {
    margin-bottom: 4px;
}
.documents-review-close {
    border: 1px solid var(--line);
    background: var(--panel-strong);
    color: var(--ink);
    border-radius: 999px;
    padding: 10px 16px;
    cursor:pointer;
    font-weight: 700;
}
.documents-review-meta {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.documents-review-block {
    padding: 14px 16px;
    border-radius: 20px;
    background: var(--panel-strong);
    border: 1px solid rgba(31, 41, 51, 0.08);
}
.documents-review-block strong {
    display:block;
    margin-bottom: 6px;
}
.documents-review-fields {
    display:grid;
    gap: 10px;
    margin-bottom: 16px;
}
.documents-review-form {
    display:grid;
    gap: 14px;
}
.documents-review-actions {
    display:flex;
    gap: 10px;
    justify-content:flex-end;
}
.documents-review-toast {
    position: fixed;
    top: 28px;
    left: 50%;
    transform: translate(-50%, -12px);
    z-index: 1700;
    width: min(520px, calc(100vw - 40px));
    padding: 22px 24px;
    border-radius: 24px;
    background: linear-gradient(135deg, #0f9d7a, #36c6a2);
    color: #fff;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.22);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.22s ease, transform 0.22s ease;
}
.documents-review-toast.is-visible {
    opacity: 1;
    transform: translate(-50%, 0);
}
.documents-review-toast-title {
    font-size: 1rem;
    font-weight: 800;
    margin-bottom: 10px;
    opacity: 0.92;
}
.documents-review-toast-meta {
    display: grid;
    gap: 10px;
    font-size: 0.98rem;
    line-height: 1.4;
}
.documents-review-toast-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.82;
    margin-bottom: 2px;
}
.documents-review-toast-value {
    font-size: 1.2rem;
    font-weight: 800;
    line-height: 1.2;
}
.documents-create-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -52%);
    width: min(520px, calc(100vw - 36px));
    padding: 22px;
    border-radius: 28px;
    background: var(--panel);
    border: 1px solid rgba(31, 41, 51, 0.14);
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.22);
    z-index: 1800;
    display: none;
}
.documents-create-modal.is-open {
    display: block;
}
.documents-create-modal textarea {
    min-height: 110px;
}
@media (max-width: 760px) {
    .documents-review-meta { grid-template-columns: 1fr; }
    .documents-review-panel {
        top: 16px;
        right: 12px;
        width: calc(100vw - 24px);
        max-height: calc(100vh - 32px);
        padding: 18px;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="documents-review-shell">
    <section class="documents-review-hero">
        <span class="documents-review-kicker"><?= htmlspecialchars(t('documents.review_kicker')) ?></span>
        <h1><?= htmlspecialchars(t('documents.review_title')) ?></h1>
        <p><?= htmlspecialchars(t('documents.review_subtitle')) ?></p>
    </section>

    <section class="documents-review-card">
        <h3><?= htmlspecialchars(t('documents.review_today')) ?></h3>
        <p class="documents-review-note"><?= htmlspecialchars($pertenece !== '' ? $pertenece : '-') ?></p>
        <div class="documents-review-toolbar">
            <button type="button" class="btn-primary" onclick="openCreateDocumentType()"><?= htmlspecialchars(t('documents.create_type')) ?></button>
        </div>

        <div class="table-container documents-review-list-wrap">
            <table class="leads-table documents-review-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(t('documents.date_created')) ?></th>
                        <th><?= htmlspecialchars(t('documents.advisor')) ?></th>
                        <th><?= htmlspecialchars(t('common.type')) ?></th>
                        <th><?= htmlspecialchars(t('common.status')) ?></th>
                        <th><?= htmlspecialchars(t('documents.state_time')) ?></th>
                        <th><?= htmlspecialchars(t('documents.support')) ?></th>
                        <th><?= htmlspecialchars(t('common.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($solicitudes)): ?>
                        <?php foreach ($solicitudes as $fila): ?>
                        <tr data-document-row="<?= htmlspecialchars((string) $fila['id']) ?>">
                            <td><?= htmlspecialchars((string) ($fila['fecha_creado'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($fila['asesor_nombre'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($fila['tipo_doc'] ?? '')) ?></td>
                            <td data-field="estado"><?= htmlspecialchars((string) ($fila['estado'] ?? '')) ?></td>
                            <td data-field="hora_estado"><?= htmlspecialchars((string) ($fila['hora_estado'] ?? '')) ?></td>
                            <td data-field="auxiliar"><?= htmlspecialchars((string) (($fila['auxiliar'] ?? '') !== '' ? $fila['auxiliar'] : '-')) ?></td>
                            <td>
                                <button type="button" class="documents-review-open <?= htmlspecialchars(claseBotonDocumentoReview((string) ($fila['estado'] ?? ''))) ?>" onclick="openDocumentReview(<?= (int) $fila['id'] ?>)">
                                    <?= htmlspecialchars(t('documents.open')) ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7"><?= htmlspecialchars(t('documents.review_empty')) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</div>
</div>

<div class="documents-review-overlay" id="documentsCreateOverlay" onclick="closeCreateDocumentType()"></div>
<section class="documents-create-modal" id="documentsCreateModal" aria-hidden="true">
    <div class="documents-review-panel-header">
        <div>
            <h3><?= htmlspecialchars(t('documents.create_type_title')) ?></h3>
            <div class="documents-review-note"><?= htmlspecialchars(t('documents.create_type_fields_help')) ?></div>
        </div>
        <button type="button" class="documents-review-close" onclick="closeCreateDocumentType()"><?= htmlspecialchars(t('common.close')) ?></button>
    </div>

    <form class="documents-review-form" id="documentsCreateForm">
        <div>
            <label><?= htmlspecialchars(t('documents.create_type_name')) ?></label>
            <input type="text" id="createTypeName" name="nombre" required>
        </div>
        <div>
            <label><?= htmlspecialchars(t('documents.create_type_fields')) ?></label>
            <textarea id="createTypeFields" name="campos" required></textarea>
        </div>
        <div class="documents-review-actions">
            <button type="button" class="btn-secondary" onclick="closeCreateDocumentType()"><?= htmlspecialchars(t('common.cancel')) ?></button>
            <button type="submit" class="btn-primary" id="documentsCreateButton"><?= htmlspecialchars(t('documents.create_type')) ?></button>
        </div>
    </form>
</section>

<div class="documents-review-toast" id="documentsReviewToast"></div>
<div class="documents-review-overlay" id="documentsReviewOverlay" onclick="closeDocumentReview()"></div>
<aside class="documents-review-panel" id="documentsReviewPanel" aria-hidden="true">
    <div class="documents-review-panel-header">
        <div>
            <h3><?= htmlspecialchars(t('documents.detail_title')) ?></h3>
            <div class="documents-review-note" id="documentsReviewSubtitle"></div>
        </div>
        <button type="button" class="documents-review-close" onclick="closeDocumentReview()"><?= htmlspecialchars(t('common.close')) ?></button>
    </div>

    <div class="documents-review-meta">
        <div class="documents-review-block">
            <strong><?= htmlspecialchars(t('documents.advisor')) ?></strong>
            <div id="reviewAdvisor">-</div>
        </div>
        <div class="documents-review-block">
            <strong><?= htmlspecialchars(t('common.status')) ?></strong>
            <div id="reviewStatus">-</div>
        </div>
    </div>

    <div class="documents-review-fields" id="reviewFields"></div>

    <div class="documents-review-block" id="reviewObservationBlock" style="display:none;">
        <strong><?= htmlspecialchars(t('documents.doc_observation')) ?></strong>
        <div id="reviewObservation">-</div>
    </div>

    <form class="documents-review-form" id="documentsReviewForm">
        <input type="hidden" name="documento_id" id="reviewDocumentId" value="">

        <div>
            <label><?= htmlspecialchars(t('common.status')) ?></label>
            <select name="estado" id="reviewEstado" required onchange="toggleReviewCause()">
                <option value="En Proceso"><?= htmlspecialchars(t('documents.status_in_progress')) ?></option>
                <option value="En Revision"><?= htmlspecialchars(t('documents.status_in_review')) ?></option>
                <option value="Rechazado"><?= htmlspecialchars(t('documents.status_rejected')) ?></option>
                <option value="Enviado"><?= htmlspecialchars(t('documents.status_sent')) ?></option>
            </select>
        </div>

        <div>
            <label><?= htmlspecialchars(t('documents.reason')) ?></label>
            <select name="causa" id="reviewCausa">
                <option value="">--</option>
                <option value="Informacion Incompleta"><?= htmlspecialchars(t('documents.cause_incomplete')) ?></option>
                <option value="Asesor no confirma documento"><?= htmlspecialchars(t('documents.cause_unconfirmed')) ?></option>
                <option value="Solicitud Cancelada"><?= htmlspecialchars(t('documents.cause_cancelled')) ?></option>
                <option value="Otros"><?= htmlspecialchars(t('documents.cause_other')) ?></option>
            </select>
        </div>

        <div>
            <label><?= htmlspecialchars(t('documents.aux_observation')) ?></label>
            <textarea name="observaciones_auxiliar" id="reviewAuxObservation" rows="4"></textarea>
        </div>

        <div class="documents-review-actions">
            <button type="button" class="btn-secondary" onclick="closeDocumentReview()"><?= htmlspecialchars(t('common.cancel')) ?></button>
            <button type="submit" class="btn-primary" id="reviewSaveButton"><?= htmlspecialchars(t('documents.save_review')) ?></button>
        </div>
    </form>
</aside>

<script>
const reviewMessages = {
    loadError: <?= json_encode(t('documents.load_error')) ?>,
    saveError: <?= json_encode(t('documents.review_save_error')) ?>,
    newAlert: <?= json_encode(t('documents.new_alert')) ?>,
    openLabel: <?= json_encode(t('documents.open')) ?>,
    advisorLabel: <?= json_encode(t('documents.advisor')) ?>,
    typeLabel: <?= json_encode(t('common.type')) ?>,
    createTypeError: <?= json_encode(t('documents.create_type_error')) ?>
};

let reviewKnownIds = <?= json_encode(array_map(static fn($fila) => (int) ($fila['id'] ?? 0), $solicitudes)) ?>;
let reviewToastTimer = null;

function reviewButtonClass(estado) {
    if (estado === 'Pendiente') return 'is-pending';
    if (estado === 'En Proceso' || estado === 'En Revision') return 'is-progress';
    if (estado === 'Rechazado') return 'is-rejected';
    if (estado === 'Enviado') return 'is-sent';
    return 'is-progress';
}

function showReviewToast(documentItem) {
    const toast = document.getElementById('documentsReviewToast');
    const advisor = documentItem?.asesor_nombre || '-';
    const docType = documentItem?.tipo_doc || '-';

    toast.innerHTML = `
        <div class="documents-review-toast-title">${escapeHtml(reviewMessages.newAlert)}</div>
        <div class="documents-review-toast-meta">
            <div>
                <div class="documents-review-toast-label">${escapeHtml(reviewMessages.advisorLabel)}</div>
                <div class="documents-review-toast-value">${escapeHtml(advisor)}</div>
            </div>
            <div>
                <div class="documents-review-toast-label">${escapeHtml(reviewMessages.typeLabel)}</div>
                <div class="documents-review-toast-value">${escapeHtml(docType)}</div>
            </div>
        </div>
    `;
    toast.classList.add('is-visible');

    if (reviewToastTimer) {
        clearTimeout(reviewToastTimer);
    }

    reviewToastTimer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, 3500);
}

function renderReviewRows(documents) {
    const tbody = document.querySelector('.documents-review-table tbody');
    if (!tbody) return;

    if (!documents || documents.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7"><?= htmlspecialchars(t('documents.review_empty')) ?></td></tr>';
        return;
    }

    tbody.innerHTML = documents.map((fila) => {
        const estado = fila.estado || '';
        const auxiliar = (fila.auxiliar || '').trim() !== '' ? fila.auxiliar : '-';
        return `
            <tr data-document-row="${escapeHtml(String(fila.id || ''))}">
                <td>${escapeHtml(String(fila.fecha_creado || ''))}</td>
                <td>${escapeHtml(String(fila.asesor_nombre || ''))}</td>
                <td>${escapeHtml(String(fila.tipo_doc || ''))}</td>
                <td data-field="estado">${escapeHtml(estado)}</td>
                <td data-field="hora_estado">${escapeHtml(String(fila.hora_estado || ''))}</td>
                <td data-field="auxiliar">${escapeHtml(auxiliar)}</td>
                <td>
                    <button type="button" class="documents-review-open ${reviewButtonClass(estado)}" onclick="openDocumentReview(${Number(fila.id || 0)})">
                        ${escapeHtml(reviewMessages.openLabel)}
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function syncDocumentsFeed(showToastOnNew = false) {
    fetch(<?= json_encode(appUrl('core/documentos_feed.php')) ?>)
        .then(response => response.json())
        .then(data => {
            if (!data.ok) {
                return;
            }

            const documents = Array.isArray(data.documents) ? data.documents : [];
            const newIds = documents.map((fila) => Number(fila.id || 0)).filter((id) => id > 0);
            const newDocs = documents.filter((fila) => !reviewKnownIds.includes(Number(fila.id || 0)));

            renderReviewRows(documents);

            if (showToastOnNew && newDocs.length > 0) {
                showReviewToast(newDocs[0]);
            }

            reviewKnownIds = newIds;
        })
        .catch(() => {});
}

function toggleReviewCause() {
    const estado = document.getElementById('reviewEstado').value;
    const causa = document.getElementById('reviewCausa');
    const enabled = estado === 'Rechazado';
    causa.disabled = !enabled;
    if (!enabled) {
        causa.value = '';
    }
}

function openDocumentReview(id) {
    const overlay = document.getElementById('documentsReviewOverlay');
    const panel = document.getElementById('documentsReviewPanel');
    const fields = document.getElementById('reviewFields');
    const observationBlock = document.getElementById('reviewObservationBlock');
    const observation = document.getElementById('reviewObservation');

    fields.innerHTML = '<div class="documents-review-block"><?= htmlspecialchars(t('common.loading')) ?></div>';
    observationBlock.style.display = 'none';
    document.getElementById('documentsReviewSubtitle').textContent = '';
    document.getElementById('reviewAdvisor').textContent = '-';
    document.getElementById('reviewStatus').textContent = '-';
    document.getElementById('reviewDocumentId').value = id;

    overlay.classList.add('is-open');
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');

    fetch(<?= json_encode(appUrl('core/documentos_detalle.php')) ?> + '?id=' + encodeURIComponent(id))
        .then(response => response.json())
        .then(data => {
            if (!data.ok) {
                throw new Error(data.message || reviewMessages.loadError);
            }

            const doc = data.document;
            document.getElementById('documentsReviewSubtitle').textContent = doc.tipo_doc || '';
            document.getElementById('reviewAdvisor').textContent = [doc.asesor_nombre || '', doc.asesor_usuario || ''].filter(Boolean).join(' · ');
            document.getElementById('reviewStatus').textContent = doc.estado || '-';
            document.getElementById('reviewEstado').value = doc.estado || 'En Proceso';
            document.getElementById('reviewCausa').value = doc.causa || '';
            document.getElementById('reviewAuxObservation').value = doc.observaciones_auxiliar || '';

            fields.innerHTML = '';
            (doc.campos || []).forEach((field) => {
                const block = document.createElement('div');
                block.className = 'documents-review-block';
                block.innerHTML = '<strong>' + escapeHtml(field.nombre_campo || '') + '</strong><div>' + formatFieldValue(field.nombre_campo || '', field.valor || '') + '</div>';
                fields.appendChild(block);
            });

            if ((doc.observacion_documento || '').trim() !== '') {
                observationBlock.style.display = 'block';
                observation.textContent = doc.observacion_documento;
            }

            toggleReviewCause();
        })
        .catch(error => {
            fields.innerHTML = '<div class="documents-review-block">' + escapeHtml(error.message || reviewMessages.loadError) + '</div>';
        });
}

function closeDocumentReview() {
    document.getElementById('documentsReviewOverlay').classList.remove('is-open');
    const panel = document.getElementById('documentsReviewPanel');
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
}

function openCreateDocumentType() {
    document.getElementById('documentsCreateOverlay').classList.add('is-open');
    const modal = document.getElementById('documentsCreateModal');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeCreateDocumentType() {
    document.getElementById('documentsCreateOverlay').classList.remove('is-open');
    const modal = document.getElementById('documentsCreateModal');
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatFieldValue(name, value) {
    const trimmed = String(value || '').trim();
    if (name.toLowerCase() === 'url' && trimmed !== '') {
        const href = /^https?:\/\//i.test(trimmed) ? trimmed : 'https://' + trimmed;
        return '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(trimmed) + '</a>';
    }
    return escapeHtml(trimmed);
}

document.getElementById('documentsReviewForm').addEventListener('submit', function (event) {
    event.preventDefault();

    const button = document.getElementById('reviewSaveButton');
    button.disabled = true;
    button.textContent = '...';

    fetch(<?= json_encode(appUrl('core/documentos_actualizar.php')) ?>, {
        method: 'POST',
        body: new FormData(event.currentTarget)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.ok) {
            throw new Error(data.message || reviewMessages.saveError);
        }

        const id = document.getElementById('reviewDocumentId').value;
        const row = document.querySelector('[data-document-row="' + CSS.escape(id) + '"]');
        if (row) {
            const estadoNuevo = document.getElementById('reviewEstado').value;
            row.querySelector('[data-field="estado"]').textContent = estadoNuevo;
            row.querySelector('[data-field="hora_estado"]').textContent = data.hora_estado || '';
            row.querySelector('[data-field="auxiliar"]').textContent = data.auxiliar || '-';
            const button = row.querySelector('.documents-review-open');
            if (button) {
                button.className = 'documents-review-open ' + reviewButtonClass(estadoNuevo);
            }
        }

        closeDocumentReview();
    })
    .catch(error => {
        alert(error.message || reviewMessages.saveError);
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = <?= json_encode(t('documents.save_review')) ?>;
    });
});

document.getElementById('documentsCreateForm').addEventListener('submit', function (event) {
    event.preventDefault();

    const button = document.getElementById('documentsCreateButton');
    button.disabled = true;
    button.textContent = '...';

    const payload = {
        nombre: document.getElementById('createTypeName').value.trim(),
        campos: document.getElementById('createTypeFields').value.trim()
    };

    fetch(<?= json_encode(appUrl('core/documentos_crear_tipo.php')) ?>, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.ok) {
            throw new Error(data.message || reviewMessages.createTypeError);
        }

        alert(data.message);
        closeCreateDocumentType();
        document.getElementById('documentsCreateForm').reset();
    })
    .catch(error => {
        alert(error.message || reviewMessages.createTypeError);
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = <?= json_encode(t('documents.create_type')) ?>;
    });
});

setInterval(() => {
    syncDocumentsFeed(true);
}, 15000);
</script>

</body>
</html>
