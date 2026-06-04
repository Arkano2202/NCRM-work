<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";
require_once dirname(__DIR__, 2) . "/core/documentos.php";
require_once dirname(__DIR__, 2) . "/core/nextcloud_chat.php";
require_once dirname(__DIR__, 2) . "/core/app.php";

requireLogin();
requirePermission("documentos");

$tipo = (int) ($_SESSION["tipo"] ?? 0);
if (!in_array($tipo, [3, 7], true)) {
    http_response_code(403);
    exit("Acceso denegado");
}

date_default_timezone_set('America/Bogota');

$userId = (int) ($_SESSION["user_id"] ?? 0);
$agenteNombre = trim((string) ($_SESSION["nombre"] ?? ''));
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ''));
$tiposDocumento = obtenerTiposDocumento($conn);
$camposDocumento = obtenerCamposDocumentoPorTipo($conn);

$tiposIndex = [];
$camposJs = [];
foreach ($tiposDocumento as $tipoDocumento) {
    $tipoId = (int) ($tipoDocumento['id'] ?? 0);
    $tiposIndex[$tipoId] = $tipoDocumento;
    $camposJs[$tipoId] = $camposDocumento[$tipoId] ?? [];
}

$msg = "";
$error = "";
$warning = "";

if (isset($_SESSION['documents_flash_success'])) {
    $msg = (string) $_SESSION['documents_flash_success'];
    unset($_SESSION['documents_flash_success']);
}

if (isset($_SESSION['documents_flash_warning'])) {
    $warning = (string) $_SESSION['documents_flash_warning'];
    unset($_SESSION['documents_flash_warning']);
}

$form = [
    'tipo_documento_id' => '',
    'observacion_documento' => '',
    'campos' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoDocumentoId = (int) ($_POST['tipo_documento_id'] ?? 0);
    $observacionDocumento = trim((string) ($_POST['observacion_documento'] ?? ''));
    $camposIngresados = $_POST['campos'] ?? [];

    $form['tipo_documento_id'] = (string) $tipoDocumentoId;
    $form['observacion_documento'] = $observacionDocumento;
    $form['campos'] = is_array($camposIngresados) ? $camposIngresados : [];

    if (!isset($tiposIndex[$tipoDocumentoId])) {
        $error = t('documents.type_invalid');
    } else {
        $camposEsperados = $camposDocumento[$tipoDocumentoId] ?? [];
        $camposNormalizados = [];

        foreach ($camposEsperados as $nombreCampo) {
            $valor = trim((string) ($camposIngresados[$nombreCampo] ?? ''));
            if ($valor === '') {
                $error = t('documents.field_required');
                break;
            }
            $camposNormalizados[$nombreCampo] = $valor;
        }

        if ($error === '') {
            $tipoDocumentoNombre = (string) ($tiposIndex[$tipoDocumentoId]['nombre'] ?? '');
            $fechaCreado = date('Y-m-d H:i:s');
            $horaEstado = date('H:i:s');
            $estado = 'Pendiente';
            $auxiliar = '';
            $causa = '';
            $observacionesAuxiliar = '';

            $conn->begin_transaction();

            try {
                $stmtDocumento = $conn->prepare("
                    INSERT INTO documentos (
                        usuario_id, tipo_doc, fecha_creado, observacion_documento,
                        estado, hora_estado, auxiliar, causa, observaciones_auxiliar
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmtDocumento) {
                    throw new RuntimeException('prepare_documento');
                }

                $stmtDocumento->bind_param(
                    'issssssss',
                    $userId,
                    $tipoDocumentoNombre,
                    $fechaCreado,
                    $observacionDocumento,
                    $estado,
                    $horaEstado,
                    $auxiliar,
                    $causa,
                    $observacionesAuxiliar
                );

                if (!$stmtDocumento->execute()) {
                    throw new RuntimeException('execute_documento');
                }

                $documentoId = (int) $stmtDocumento->insert_id;
                $stmtDocumento->close();

                $stmtCampo = $conn->prepare("
                    INSERT INTO documentos_campos (documento_id, nombre_campo, valor)
                    VALUES (?, ?, ?)
                ");

                if (!$stmtCampo) {
                    throw new RuntimeException('prepare_campos');
                }

                foreach ($camposNormalizados as $nombreCampo => $valorCampo) {
                    $stmtCampo->bind_param('iss', $documentoId, $nombreCampo, $valorCampo);
                    if (!$stmtCampo->execute()) {
                        throw new RuntimeException('execute_campos');
                    }
                }

                $stmtCampo->close();
                $conn->commit();

                $nextcloudResult = nextcloudNotifyNewDocument(
                    $pertenece,
                    $agenteNombre !== '' ? $agenteNombre : ('ID ' . $userId),
                    $tipoDocumentoNombre,
                    $fechaCreado,
                    $observacionDocumento
                );
                if (empty($nextcloudResult['ok'])) {
                    error_log('Nextcloud documentos aviso fallido: ' . json_encode($nextcloudResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $detalleWarning = '';
                    if (($nextcloudResult['reason'] ?? '') === 'city_not_mapped') {
                        $detalleWarning = 'Ciudad no mapeada para Nextcloud: ' . $pertenece;
                    } elseif (!empty($nextcloudResult['results']) && is_array($nextcloudResult['results'])) {
                        $partes = [];
                        foreach ($nextcloudResult['results'] as $resultadoNc) {
                            $partes[] = 'room ' . ($resultadoNc['room_id'] ?? '?')
                                . ' http ' . (int) ($resultadoNc['http_code'] ?? 0)
                                . (!empty($resultadoNc['error']) ? ' error ' . $resultadoNc['error'] : '');
                        }
                        $detalleWarning = implode(' | ', $partes);
                    }
                    $_SESSION['documents_flash_warning'] = 'El documento se guardo, pero no se pudo avisar a Nextcloud. ' . $detalleWarning;
                }

                $_SESSION['documents_flash_success'] = t('documents.request_saved');
                redirectToRoute('documents');
            } catch (Throwable $e) {
                $conn->rollback();
                $error = t('documents.save_error');
            }
        }
    }
}

$misSolicitudes = obtenerSolicitudesDocumentoUsuario($conn, $userId, 40);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t('documents.title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.documents-shell { display:grid; gap:18px; }
.documents-hero,
.documents-card {
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(31, 41, 51, 0.08);
}
.documents-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}
.documents-hero p,
.documents-note { color: var(--muted); }
.documents-layout {
    display:grid;
    grid-template-columns: minmax(360px, 560px) minmax(0, 1fr);
    gap: 18px;
}
.documents-form-grid {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.documents-form-grid .full { grid-column: 1 / -1; }
.documents-fields {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-top: 12px;
}
.documents-fields:empty {
    display:none;
}
.documents-help {
    margin-top: 8px;
    margin-bottom: 8px;
    color: var(--muted);
}
.documents-list-wrap {
    max-height: 320px;
    overflow-y: auto;
    border-radius: 22px;
}
.documents-request-action {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding: 8px 14px;
    border-radius: 999px;
    background: var(--panel-strong);
    color: var(--ink);
    border: 1px solid var(--line);
    cursor: pointer;
    font-weight: 700;
}
.documents-notes-overlay {
    position: fixed;
    inset: 0;
    background: rgba(12, 18, 28, 0.42);
    backdrop-filter: blur(6px);
    z-index: 1500;
    display: none;
}
.documents-notes-overlay.is-open { display: block; }
.documents-notes-panel {
    position: fixed;
    top: 32px;
    right: 24px;
    width: min(420px, calc(100vw - 48px));
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
.documents-notes-panel.is-open { display: block; }
.documents-notes-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap: 16px;
    margin-bottom: 14px;
}
.documents-notes-close {
    border: 1px solid var(--line);
    background: var(--panel-strong);
    color: var(--ink);
    border-radius: 999px;
    padding: 10px 16px;
    cursor:pointer;
    font-weight: 700;
}
.documents-notes-body {
    padding: 16px 18px;
    border-radius: 22px;
    background: var(--panel-strong);
    border: 1px solid rgba(31, 41, 51, 0.08);
    color: var(--ink);
    white-space: pre-wrap;
    word-break: break-word;
}
.documents-observation {
    min-height: 110px;
}
@media (max-width: 980px) {
    .documents-layout { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .documents-form-grid,
    .documents-fields { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="documents-shell">
    <section class="documents-hero">
        <span class="documents-kicker"><?= htmlspecialchars(t('documents.kicker')) ?></span>
        <h1><?= htmlspecialchars(t('documents.title')) ?></h1>
        <p><?= htmlspecialchars(t('documents.subtitle')) ?></p>
    </section>

    <?php if ($msg !== ''): ?>
        <div class="alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($warning !== ''): ?>
        <div class="alert error" style="background:#fff7ed;border-color:#fdba74;color:#9a3412;"><?= htmlspecialchars($warning) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="documents-layout">
        <section class="documents-card">
            <h3><?= htmlspecialchars(t('documents.new_request')) ?></h3>
            <p class="documents-note"><?= htmlspecialchars(t('documents.fields_help')) ?></p>

            <form method="POST" id="documentosForm">
                <div class="documents-form-grid">
                    <div class="full">
                        <label><?= htmlspecialchars(t('documents.type_label')) ?></label>
                        <select name="tipo_documento_id" id="tipo_documento_id" required onchange="renderDocumentFields()">
                            <option value=""><?= htmlspecialchars(t('documents.select_type')) ?></option>
                            <?php foreach ($tiposDocumento as $tipoDocumento): ?>
                            <option
                                value="<?= htmlspecialchars((string) $tipoDocumento['id']) ?>"
                                <?= $form['tipo_documento_id'] === (string) $tipoDocumento['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars((string) $tipoDocumento['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="full">
                        <div class="documents-help"><?= htmlspecialchars(t('documents.fields_help')) ?></div>
                        <div class="documents-fields" id="documentsFields"></div>
                    </div>

                    <div class="full">
                        <label><?= htmlspecialchars(t('documents.request_note')) ?></label>
                        <textarea
                            class="documents-observation"
                            name="observacion_documento"
                            id="observacion_documento"
                            placeholder="<?= htmlspecialchars(t('documents.request_note')) ?>"
                        ><?= htmlspecialchars($form['observacion_documento']) ?></textarea>
                    </div>
                </div>

                <div class="novedades-actions">
                    <button type="submit" class="btn-primary"><?= htmlspecialchars(t('documents.submit')) ?></button>
                </div>
            </form>
        </section>

        <section class="documents-card">
            <h3><?= htmlspecialchars(t('documents.my_requests')) ?></h3>
            <p class="documents-note"><?= htmlspecialchars(t('documents.my_requests_help')) ?></p>

            <div class="table-container documents-list-wrap">
                <table class="leads-table">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(t('documents.date_created')) ?></th>
                            <th><?= htmlspecialchars(t('common.type')) ?></th>
                            <th><?= htmlspecialchars(t('common.status')) ?></th>
                            <th><?= htmlspecialchars(t('documents.state_time')) ?></th>
                            <th><?= htmlspecialchars(t('documents.support')) ?></th>
                            <th><?= htmlspecialchars(t('documents.reason')) ?></th>
                            <th><?= htmlspecialchars(t('common.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($misSolicitudes)): ?>
                            <?php foreach ($misSolicitudes as $solicitud): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($solicitud['fecha_creado'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($solicitud['tipo_doc'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($solicitud['estado'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($solicitud['hora_estado'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) (($solicitud['auxiliar'] ?? '') !== '' ? $solicitud['auxiliar'] : '-')) ?></td>
                                <td><?= htmlspecialchars((string) (($solicitud['causa'] ?? '') !== '' ? $solicitud['causa'] : '-')) ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="documents-request-action"
                                        onclick="openDocumentNotes(<?= htmlspecialchars(json_encode([
                                            'tipo' => (string) ($solicitud['tipo_doc'] ?? ''),
                                            'estado' => (string) ($solicitud['estado'] ?? ''),
                                            'auxiliar' => (string) ($solicitud['auxiliar'] ?? ''),
                                            'observaciones' => (string) ($solicitud['observaciones_auxiliar'] ?? ''),
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)"
                                    >
                                        <?= htmlspecialchars(t('documents.view_notes')) ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7"><?= htmlspecialchars(t('documents.empty')) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div>
</div>

<div class="documents-notes-overlay" id="documentsNotesOverlay" onclick="closeDocumentNotes()"></div>
<aside class="documents-notes-panel" id="documentsNotesPanel" aria-hidden="true">
    <div class="documents-notes-header">
        <div>
            <h3><?= htmlspecialchars(t('documents.review_notes')) ?></h3>
            <div class="documents-note" id="documentsNotesSubtitle"></div>
        </div>
        <button type="button" class="documents-notes-close" onclick="closeDocumentNotes()"><?= htmlspecialchars(t('common.close')) ?></button>
    </div>
    <div class="documents-notes-body" id="documentsNotesBody"><?= htmlspecialchars(t('documents.review_notes_empty')) ?></div>
</aside>

<script>
const documentFieldsMap = <?= json_encode($camposJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const persistedFieldValues = <?= json_encode($form['campos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const documentsNotesEmpty = <?= json_encode(t('documents.review_notes_empty')) ?>;

function renderDocumentFields() {
    const tipoSelect = document.getElementById('tipo_documento_id');
    const fieldsContainer = document.getElementById('documentsFields');
    const typeId = tipoSelect.value;
    const fields = documentFieldsMap[typeId] || [];

    fieldsContainer.innerHTML = '';

    fields.forEach((fieldLabel) => {
        const wrapper = document.createElement('div');

        const label = document.createElement('label');
        label.textContent = fieldLabel;

        const input = document.createElement('input');
        input.type = 'text';
        input.name = `campos[${fieldLabel}]`;
        input.value = persistedFieldValues[fieldLabel] || '';
        input.required = true;

        wrapper.appendChild(label);
        wrapper.appendChild(input);
        fieldsContainer.appendChild(wrapper);
    });
}

function openDocumentNotes(payload) {
    const overlay = document.getElementById('documentsNotesOverlay');
    const panel = document.getElementById('documentsNotesPanel');
    const subtitle = document.getElementById('documentsNotesSubtitle');
    const body = document.getElementById('documentsNotesBody');

    subtitle.textContent = [payload.tipo || '', payload.estado || '', payload.auxiliar || '']
        .filter(Boolean)
        .join(' · ');
    body.textContent = (payload.observaciones || '').trim() !== '' ? payload.observaciones : documentsNotesEmpty;

    overlay.classList.add('is-open');
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
}

function closeDocumentNotes() {
    document.getElementById('documentsNotesOverlay').classList.remove('is-open');
    const panel = document.getElementById('documentsNotesPanel');
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
}

renderDocumentFields();
</script>

</body>
</html>
