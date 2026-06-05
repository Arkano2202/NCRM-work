<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";
require_once dirname(__DIR__, 2) . "/core/chat.php";
require_once dirname(__DIR__, 2) . "/core/app.php";

requireLogin();
requirePermission("chat_images_admin");

$images = chatListAdminImages($conn);
$uploadFiles = chatListAdminUploadFiles();
$lastFailuresReport = chatGetLastAdminUploadFailuresReport();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t('chat_images.title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.chat-images-shell{display:grid;gap:18px}
.chat-images-hero,.chat-images-card{padding:22px 24px;border-radius:28px;background:rgba(255,255,255,.74);border:1px solid rgba(31,41,51,.08)}
.chat-images-kicker{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.chat-images-hero p,.chat-images-note,.chat-images-empty{color:var(--muted)}
.chat-images-mode-switch{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.chat-images-mode-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:999px;border:1px solid rgba(31,41,51,.12);background:rgba(255,255,255,.75);color:var(--ink);font-weight:700;cursor:pointer}
.chat-images-mode-tab.active{background:linear-gradient(135deg,rgba(43,124,255,.14),rgba(43,124,255,.08));border-color:rgba(43,124,255,.28)}
.chat-images-mode-badge{min-width:22px;height:22px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#cb5037;color:#fff;font-size:.76rem;font-weight:800}
.chat-images-mode-panel[hidden]{display:none !important}
.chat-images-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.chat-images-upload-box{margin-top:18px;padding:16px 18px;border-radius:22px;background:linear-gradient(180deg,rgba(247,249,253,.96),rgba(239,243,249,.88));border:1px solid rgba(31,41,51,.08);display:grid;gap:12px}
.chat-images-upload-form{display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.chat-images-upload-input{max-width:100%;padding:10px 12px;border-radius:16px;border:1px solid rgba(31,41,51,.12);background:#fff;color:var(--ink)}
.chat-images-upload-note{font-size:.88rem;color:var(--muted)}
.chat-images-upload-grid{display:grid;gap:14px}
@media (min-width:920px){.chat-images-upload-grid{grid-template-columns:1fr 1fr}}
.chat-image-item{min-width:0;height:100%;padding:18px;border-radius:26px;background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(246,248,252,.9));border:1px solid rgba(31,41,51,.08);display:grid;grid-template-rows:auto auto 1fr auto auto;gap:14px;box-shadow:0 18px 34px rgba(15,23,42,.06);overflow:hidden}
.chat-image-preview{width:100%;min-width:0;aspect-ratio:4/3;border-radius:20px;overflow:hidden;background:linear-gradient(180deg,rgba(244,247,252,.96),rgba(233,238,246,.84));border:1px solid rgba(31,41,51,.08);display:flex;align-items:center;justify-content:center;padding:12px}
.chat-image-preview img{width:100%;height:100%;object-fit:contain;display:block;border-radius:14px;background:#fff}
.chat-image-preview.is-pdf{flex-direction:column;gap:10px;text-decoration:none;color:var(--ink)}
.chat-image-preview-icon{width:88px;height:88px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(215,75,75,.16),rgba(184,45,45,.08));color:#b12f2f;font-size:2.2rem;font-weight:800;box-shadow:inset 0 0 0 1px rgba(177,47,47,.12)}
.chat-image-preview-label{font-size:.92rem;font-weight:700;color:var(--muted)}
.chat-images-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;margin-bottom:16px}
.chat-image-title{min-width:0;font-weight:800;font-size:1rem;line-height:1.35;color:var(--ink);word-break:break-word;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.7em}
.chat-image-meta{min-width:0;display:grid;gap:10px;font-size:.92rem;color:var(--muted);padding:14px 16px;border-radius:18px;background:rgba(53,102,188,.05);border:1px solid rgba(53,102,188,.08);overflow:hidden}
.chat-image-meta > div{display:grid;gap:2px;min-width:0;overflow-wrap:anywhere;word-break:break-word}
.chat-image-meta strong{color:var(--ink)}
.chat-image-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;background:rgba(235,136,42,.14);color:#a85d10;font-size:.78rem;font-weight:700}
.chat-image-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:auto}
.chat-image-actions .btn-secondary,.chat-image-actions .btn-primary,.chat-image-actions .btn-danger{flex:1 1 120px;min-width:0;width:auto;text-align:center;justify-content:center}
.btn-danger{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:999px;border:none;background:linear-gradient(135deg,#d35656,#b62e2e);color:#fff;font-weight:700;cursor:pointer;box-shadow:0 14px 28px rgba(182,46,46,.24)}
.chat-image-toast{position:fixed;top:24px;right:24px;z-index:9999;min-width:260px;max-width:420px;padding:14px 18px;border-radius:18px;background:#fff;border:1px solid rgba(31,41,51,.08);box-shadow:0 18px 36px rgba(15,23,42,.12);color:var(--ink)}
@media (max-width:900px){.chat-images-grid{grid-template-columns:1fr}.chat-images-toolbar{flex-direction:column;align-items:flex-start}.chat-image-actions{flex-direction:column}.chat-image-actions .btn-secondary,.chat-image-actions .btn-primary,.chat-image-actions .btn-danger{width:100%;flex:1 1 auto}}
</style>
</head>
<body>
<div class="dashboard">
    <?php include dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
    <div class="main">
        <?php include dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>
        <div class="chat-images-shell">
            <section class="chat-images-hero">
                <div class="chat-images-kicker"><?= htmlspecialchars(t('menu.chat_images_admin')) ?></div>
                <h1><?= htmlspecialchars(t('chat_images.title')) ?></h1>
                <p><?= htmlspecialchars(t('chat_images.subtitle')) ?></p>
                <div class="chat-images-mode-switch">
                    <button type="button" class="chat-images-mode-tab active" data-mode-tab="images">
                        <span><?= htmlspecialchars(t('chat_images.tab_images')) ?></span>
                        <span class="chat-images-mode-badge"><?= count($images) ?></span>
                    </button>
                    <button type="button" class="chat-images-mode-tab" data-mode-tab="uploads">
                        <span><?= htmlspecialchars(t('chat_images.tab_uploads')) ?></span>
                        <span class="chat-images-mode-badge"><?= count($uploadFiles) ?></span>
                    </button>
                </div>
            </section>

            <section class="chat-images-card chat-images-mode-panel" data-mode-panel="images">
                <div class="chat-images-note"><?= htmlspecialchars(t('chat_images.folder_note')) ?></div>
                <div class="chat-images-upload-grid">
                    <div class="chat-images-upload-box">
                        <div class="chat-images-note"><?= htmlspecialchars(t('chat_images.upload_note')) ?></div>
                        <form id="chatImagesUploadForm" class="chat-images-upload-form" enctype="multipart/form-data">
                            <input
                                type="file"
                                id="chatImagesUploadInput"
                                class="chat-images-upload-input"
                                name="images[]"
                                accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf"
                                multiple
                            >
                            <button type="submit" class="btn-primary" id="chatImagesUploadButton"><?= htmlspecialchars(t('chat_images.upload_button')) ?></button>
                        </form>
                        <div class="chat-images-upload-note"><?= htmlspecialchars(t('chat_images.upload_help')) ?></div>
                        <?php if ($lastFailuresReport !== null): ?>
                            <a class="btn-secondary" href="<?= htmlspecialchars((string) $lastFailuresReport['url']) ?>" style="justify-self:flex-start;">
                                Descargar ultimo informe de fallidas
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="chat-images-upload-box">
                        <div class="chat-images-note"><?= htmlspecialchars(t('chat_images.zip_note')) ?></div>
                        <form id="chatImagesZipUploadForm" class="chat-images-upload-form" enctype="multipart/form-data">
                            <input
                                type="file"
                                id="chatImagesZipUploadInput"
                                class="chat-images-upload-input"
                                name="zip_file"
                                accept=".zip,application/zip,application/x-zip-compressed"
                            >
                            <button type="submit" class="btn-primary" id="chatImagesZipUploadButton"><?= htmlspecialchars(t('chat_images.zip_button')) ?></button>
                        </form>
                        <div class="chat-images-upload-note"><?= htmlspecialchars(t('chat_images.zip_help')) ?></div>
                        <?php if ($lastFailuresReport !== null): ?>
                            <a class="btn-secondary" href="<?= htmlspecialchars((string) $lastFailuresReport['url']) ?>" style="justify-self:flex-start;">
                                Descargar ultimo informe de fallidas
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (empty($images)): ?>
                    <div class="chat-images-empty" style="margin-top:16px;"><?= htmlspecialchars(t('chat_images.empty')) ?></div>
                <?php else: ?>
                    <div class="chat-images-toolbar">
                        <div class="chat-images-note"><?= count($images) ?> imagen(es) temporal(es) encontradas.</div>
                        <div class="chat-image-actions">
                            <a class="btn-primary" href="<?= htmlspecialchars(appUrl('core/chat_image_admin_download_all.php')) ?>"><?= htmlspecialchars(t('chat_images.download_all')) ?></a>
                            <button type="button" class="btn-danger" id="chatImagesDeleteAllButton"><?= htmlspecialchars(t('chat_images.delete_all')) ?></button>
                        </div>
                    </div>
                    <div class="chat-images-grid" id="chatImagesGrid">
                        <?php foreach ($images as $image): ?>
                            <?php
                            $fileName = (string) ($image['file_name'] ?? '');
                            $viewUrl = appUrl('core/chat_image_admin_file.php?file=' . rawurlencode($fileName));
                            $downloadUrl = appUrl('core/chat_image_admin_file.php?file=' . rawurlencode($fileName) . '&download=1');
                            $sizeLabel = number_format(((int) ($image['size_bytes'] ?? 0)) / 1024, 1) . ' KB';
                            $mimeType = (string) ($image['mime_type'] ?? 'application/octet-stream');
                            $isPdf = $mimeType === 'application/pdf' || str_ends_with(strtolower($fileName), '.pdf');
                            ?>
                            <article class="chat-image-item" data-file="<?= htmlspecialchars($fileName) ?>">
                                <?php if ($isPdf): ?>
                                    <a class="chat-image-preview is-pdf" href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer">
                                        <div class="chat-image-preview-icon">PDF</div>
                                        <div class="chat-image-preview-label">Abrir PDF</div>
                                    </a>
                                <?php else: ?>
                                    <a class="chat-image-preview" href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer">
                                        <img src="<?= htmlspecialchars($viewUrl) ?>" alt="<?= htmlspecialchars($image['original_name'] ?? $fileName) ?>" loading="lazy">
                                    </a>
                                <?php endif; ?>
                                <div class="chat-image-title"><?= htmlspecialchars($image['original_name'] ?? $fileName) ?></div>
                                <div class="chat-image-meta">
                                    <div><strong><?= htmlspecialchars(t('chat_images.file_name')) ?>:</strong> <?= htmlspecialchars($fileName) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.created_at')) ?>:</strong> <?= htmlspecialchars((string) ($image['created_at'] ?? '-')) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.size')) ?>:</strong> <?= htmlspecialchars($sizeLabel) ?></div>
                                    <div><strong>MIME:</strong> <?= htmlspecialchars($mimeType) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.sender')) ?>:</strong> <?= htmlspecialchars((string) (($image['sender_name'] ?? '') !== '' ? $image['sender_name'] : '-')) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.receiver')) ?>:</strong> <?= htmlspecialchars((string) (($image['receiver_name'] ?? '') !== '' ? $image['receiver_name'] : '-')) ?></div>
                                </div>
                                <?php if (!empty($image['is_orphan'])): ?>
                                    <div><span class="chat-image-chip"><?= htmlspecialchars(t('chat_images.orphan')) ?></span></div>
                                <?php endif; ?>
                                <div class="chat-image-actions">
                                    <a class="btn-secondary" href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(t('chat_images.view')) ?></a>
                                    <a class="btn-primary" href="<?= htmlspecialchars($downloadUrl) ?>"><?= htmlspecialchars(t('chat_images.download')) ?></a>
                                    <button type="button" class="btn-danger" data-delete-file="<?= htmlspecialchars($fileName) ?>"><?= htmlspecialchars(t('common.delete')) ?></button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="chat-images-card chat-images-mode-panel" data-mode-panel="uploads" hidden>
                <div class="chat-images-note"><?= htmlspecialchars(t('uploads_files.folder_note')) ?></div>
                <div class="chat-images-note" style="margin-top:6px;"><?= htmlspecialchars(t('uploads_files.subtitle')) ?></div>
                <?php if (empty($uploadFiles)): ?>
                    <div class="chat-images-empty" style="margin-top:16px;"><?= htmlspecialchars(t('uploads_files.empty')) ?></div>
                <?php else: ?>
                    <div class="chat-images-toolbar">
                        <div class="chat-images-note"><?= count($uploadFiles) ?> archivo(s) visible(s) en uploads.</div>
                        <button type="button" class="btn-danger" id="uploadsDeleteAllButton"><?= htmlspecialchars(t('uploads_files.delete_all')) ?></button>
                    </div>
                    <div class="chat-images-grid" id="uploadsFilesGrid">
                        <?php foreach ($uploadFiles as $file): ?>
                            <?php
                            $fileName = (string) ($file['file_name'] ?? '');
                            $viewUrl = appUrl('core/uploads_admin_file.php?file=' . rawurlencode($fileName));
                            $downloadUrl = appUrl('core/uploads_admin_file.php?file=' . rawurlencode($fileName) . '&download=1');
                            $sizeLabel = number_format(((int) ($file['size_bytes'] ?? 0)) / 1024, 1) . ' KB';
                            ?>
                            <article class="chat-image-item" data-upload-file="<?= htmlspecialchars($fileName) ?>">
                                <div class="chat-image-title"><?= htmlspecialchars($fileName) ?></div>
                                <div class="chat-image-meta">
                                    <div><strong><?= htmlspecialchars(t('chat_images.file_name')) ?>:</strong> <?= htmlspecialchars($fileName) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.created_at')) ?>:</strong> <?= htmlspecialchars((string) ($file['created_at'] ?? '-')) ?></div>
                                    <div><strong><?= htmlspecialchars(t('chat_images.size')) ?>:</strong> <?= htmlspecialchars($sizeLabel) ?></div>
                                    <div><strong>MIME:</strong> <?= htmlspecialchars((string) ($file['mime_type'] ?? 'application/octet-stream')) ?></div>
                                </div>
                                <div class="chat-image-actions">
                                    <a class="btn-secondary" href="<?= htmlspecialchars($viewUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(t('chat_images.view')) ?></a>
                                    <a class="btn-primary" href="<?= htmlspecialchars($downloadUrl) ?>"><?= htmlspecialchars(t('chat_images.download')) ?></a>
                                    <button type="button" class="btn-danger" data-delete-upload-file="<?= htmlspecialchars($fileName) ?>"><?= htmlspecialchars(t('common.delete')) ?></button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<div id="chatImagesToast" class="chat-image-toast" hidden></div>

<script>
(function () {
    const imageDeleteUrl = <?= json_encode(appUrl('core/chat_image_admin_delete.php')) ?>;
    const imageUploadUrl = <?= json_encode(appUrl('core/chat_image_admin_upload.php')) ?>;
    const imageZipUploadUrl = <?= json_encode(appUrl('core/chat_image_admin_upload_zip.php')) ?>;
    const uploadDeleteUrl = <?= json_encode(appUrl('core/uploads_admin_delete.php')) ?>;
    const grid = document.getElementById('chatImagesGrid');
    const uploadsGrid = document.getElementById('uploadsFilesGrid');
    const toast = document.getElementById('chatImagesToast');
    const deleteAllButton = document.getElementById('chatImagesDeleteAllButton');
    const uploadForm = document.getElementById('chatImagesUploadForm');
    const uploadInput = document.getElementById('chatImagesUploadInput');
    const uploadButton = document.getElementById('chatImagesUploadButton');
    const zipUploadForm = document.getElementById('chatImagesZipUploadForm');
    const zipUploadInput = document.getElementById('chatImagesZipUploadInput');
    const zipUploadButton = document.getElementById('chatImagesZipUploadButton');
    const uploadsDeleteAllButton = document.getElementById('uploadsDeleteAllButton');
    const emptyText = <?= json_encode(t('chat_images.empty')) ?>;
    const uploadsEmptyText = <?= json_encode(t('uploads_files.empty')) ?>;

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => {
            toast.hidden = true;
        }, 3200);
    }

    async function triggerDownload(url) {
        if (!url) {
            return false;
        }

        try {
            const response = await fetch(url, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return false;
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const fileName = decodeURIComponent((url.split('/').pop() || 'reporte.xlsx').split('?')[0]);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = fileName;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 2000);
            return true;
        } catch (error) {
            return false;
        }
    }

    function activateMode(mode) {
        document.querySelectorAll('[data-mode-tab]').forEach((tab) => {
            tab.classList.toggle('active', tab.getAttribute('data-mode-tab') === mode);
        });
        document.querySelectorAll('[data-mode-panel]').forEach((panel) => {
            panel.hidden = panel.getAttribute('data-mode-panel') !== mode;
        });
    }

    async function postDelete(url, payload, errorText) {
        const response = await fetch(url, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = { ok: false, message: errorText };
        }
        return { response, data };
    }

    async function uploadImages() {
        if (!uploadForm || !uploadInput || !uploadInput.files || !uploadInput.files.length) {
            showToast(<?= json_encode(t('chat_images.upload_error')) ?>);
            return;
        }

        const formData = new FormData();
        Array.from(uploadInput.files).forEach((file) => {
            formData.append('images[]', file);
        });

        if (uploadButton) {
            uploadButton.disabled = true;
            uploadButton.dataset.originalText = uploadButton.textContent;
            uploadButton.textContent = 'Cargando...';
        }

        try {
            const response = await fetch(imageUploadUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const rawText = await response.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (error) {
                data = {
                    ok: false,
                    message: rawText && rawText.trim() !== ''
                        ? rawText.trim().slice(0, 300)
                        : <?= json_encode(t('chat_images.upload_error')) ?>
                };
            }

            showToast(data.message || <?= json_encode(t('chat_images.upload_error')) ?>);
            if (data.report_url) {
                await triggerDownload(data.report_url);
            }
            if (response.ok && data.ok) {
                uploadForm.reset();
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (error) {
            showToast(<?= json_encode(t('chat_images.upload_error')) ?>);
        } finally {
            if (uploadButton) {
                uploadButton.disabled = false;
                uploadButton.textContent = uploadButton.dataset.originalText || <?= json_encode(t('chat_images.upload_button')) ?>;
            }
        }
    }

    async function uploadZipImages() {
        if (!zipUploadForm || !zipUploadInput || !zipUploadInput.files || !zipUploadInput.files.length) {
            showToast(<?= json_encode(t('chat_images.zip_error')) ?>);
            return;
        }

        const formData = new FormData();
        formData.append('zip_file', zipUploadInput.files[0]);

        if (zipUploadButton) {
            zipUploadButton.disabled = true;
            zipUploadButton.dataset.originalText = zipUploadButton.textContent;
            zipUploadButton.textContent = 'Procesando ZIP...';
        }

        try {
            const response = await fetch(imageZipUploadUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const rawText = await response.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (error) {
                data = {
                    ok: false,
                    message: rawText && rawText.trim() !== ''
                        ? rawText.trim().slice(0, 300)
                        : <?= json_encode(t('chat_images.zip_error')) ?>
                };
            }

            showToast(data.message || <?= json_encode(t('chat_images.zip_error')) ?>);
            if (data.report_url) {
                await triggerDownload(data.report_url);
            }
            if (response.ok && data.ok) {
                zipUploadForm.reset();
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (error) {
            showToast(<?= json_encode(t('chat_images.zip_error')) ?>);
        } finally {
            if (zipUploadButton) {
                zipUploadButton.disabled = false;
                zipUploadButton.textContent = zipUploadButton.dataset.originalText || <?= json_encode(t('chat_images.zip_button')) ?>;
            }
        }
    }

    async function deleteImage(fileName, button) {
        const formData = new FormData();
        formData.append('file', fileName);
        button.disabled = true;
        try {
            const { response, data } = await postDelete(imageDeleteUrl, formData, <?= json_encode(t('chat_images.delete_error')) ?>);
            showToast(data.message || '');
            if (response.ok && data.ok) {
                const card = document.querySelector('[data-file="' + CSS.escape(fileName) + '"]');
                if (card) {
                    card.remove();
                }
                if (grid && !grid.children.length) {
                    grid.outerHTML = '<div class="chat-images-empty" style="margin-top:16px;">' + emptyText + '</div>';
                }
            }
        } catch (error) {
            showToast(<?= json_encode(t('chat_images.delete_error')) ?>);
        } finally {
            button.disabled = false;
        }
    }

    async function deleteUploadFile(fileName, button) {
        const formData = new FormData();
        formData.append('file', fileName);
        button.disabled = true;
        try {
            const { response, data } = await postDelete(uploadDeleteUrl, formData, <?= json_encode(t('uploads_files.delete_error')) ?>);
            showToast(data.message || '');
            if (response.ok && data.ok) {
                const card = document.querySelector('[data-upload-file="' + CSS.escape(fileName) + '"]');
                if (card) {
                    card.remove();
                }
                if (uploadsGrid && !uploadsGrid.children.length) {
                    uploadsGrid.outerHTML = '<div class="chat-images-empty" style="margin-top:16px;">' + uploadsEmptyText + '</div>';
                }
            }
        } catch (error) {
            showToast(<?= json_encode(t('uploads_files.delete_error')) ?>);
        } finally {
            button.disabled = false;
        }
    }

    async function deleteAllImages() {
        if (!deleteAllButton) return;
        const formData = new FormData();
        formData.append('delete_all', '1');
        deleteAllButton.disabled = true;
        try {
            const { response, data } = await postDelete(imageDeleteUrl, formData, <?= json_encode(t('chat_images.delete_error')) ?>);
            showToast(data.message || '');
            if (response.ok && data.ok) {
                if (grid) {
                    grid.innerHTML = '';
                    grid.outerHTML = '<div class="chat-images-empty" style="margin-top:16px;">' + emptyText + '</div>';
                }
                const toolbar = deleteAllButton.closest('.chat-images-toolbar');
                if (toolbar) {
                    toolbar.remove();
                }
            }
        } catch (error) {
            showToast(<?= json_encode(t('chat_images.delete_error')) ?>);
        } finally {
            deleteAllButton.disabled = false;
        }
    }

    async function deleteAllUploads() {
        if (!uploadsDeleteAllButton) return;
        const formData = new FormData();
        formData.append('delete_all', '1');
        uploadsDeleteAllButton.disabled = true;
        try {
            const { response, data } = await postDelete(uploadDeleteUrl, formData, <?= json_encode(t('uploads_files.delete_error')) ?>);
            showToast(data.message || '');
            if (response.ok && data.ok) {
                if (uploadsGrid) {
                    uploadsGrid.innerHTML = '';
                    uploadsGrid.outerHTML = '<div class="chat-images-empty" style="margin-top:16px;">' + uploadsEmptyText + '</div>';
                }
                const toolbar = uploadsDeleteAllButton.closest('.chat-images-toolbar');
                if (toolbar) {
                    toolbar.remove();
                }
            }
        } catch (error) {
            showToast(<?= json_encode(t('uploads_files.delete_error')) ?>);
        } finally {
            uploadsDeleteAllButton.disabled = false;
        }
    }

    document.addEventListener('click', function (event) {
        const modeTab = event.target.closest('[data-mode-tab]');
        if (modeTab) {
            activateMode(modeTab.getAttribute('data-mode-tab') || 'images');
            return;
        }

        const imageDeleteButton = event.target.closest('[data-delete-file]');
        if (imageDeleteButton) {
            const fileName = imageDeleteButton.getAttribute('data-delete-file') || '';
            if (!fileName) return;
            if (!window.confirm('<?= addslashes('¿Eliminar esta imagen temporal?') ?>')) {
                return;
            }
            deleteImage(fileName, imageDeleteButton);
            return;
        }

        const uploadDeleteButton = event.target.closest('[data-delete-upload-file]');
        if (!uploadDeleteButton) return;
        const uploadFileName = uploadDeleteButton.getAttribute('data-delete-upload-file') || '';
        if (!uploadFileName) return;
        if (!window.confirm('<?= addslashes('¿Eliminar este archivo de uploads?') ?>')) {
            return;
        }
        deleteUploadFile(uploadFileName, uploadDeleteButton);
    });

    if (deleteAllButton) {
        deleteAllButton.addEventListener('click', function () {
            if (!window.confirm('<?= addslashes('¿Eliminar todas las imagenes temporales visibles?') ?>')) {
                return;
            }
            deleteAllImages();
        });
    }

    if (uploadsDeleteAllButton) {
        uploadsDeleteAllButton.addEventListener('click', function () {
            if (!window.confirm('<?= addslashes('¿Eliminar todos los archivos visibles de uploads?') ?>')) {
                return;
            }
            deleteAllUploads();
        });
    }
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            uploadImages();
        });
    }

    if (zipUploadForm) {
        zipUploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            uploadZipImages();
        });
    }
})();
</script>
</body>
</html>
