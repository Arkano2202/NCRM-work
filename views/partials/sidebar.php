<?php
require_once __DIR__ . "/../../core/permissions.php";
require_once __DIR__ . "/../../core/i18n.php";
require_once __DIR__ . "/../../core/app.php";

$chatUnreadSummary = null;
if (!empty($_SESSION["user_id"]) && canView("chat")) {
    require_once __DIR__ . "/../../core/db.php";
    require_once __DIR__ . "/../../core/chat.php";
    $chatUnreadSummary = chatGetUnreadSummary($conn, (int) ($_SESSION["user_id"] ?? 0));
}
$documentsUnreadSummary = null;
if (!empty($_SESSION["user_id"]) && canView("documentos_review")) {
    require_once __DIR__ . "/../../core/db.php";
    require_once __DIR__ . "/../../core/documentos.php";
    $documentsUnreadSummary = obtenerResumenDocumentosFloor($conn, trim((string) ($_SESSION["pertenece"] ?? "")));
}
$documentsNotificationsEnabled = canView("documentos_review");
?>

<div class="sidebar">

    <h2><?= htmlspecialchars(t("brand.name")) ?></h2>
    <p class="sidebar-subtitle"><?= htmlspecialchars(t("brand.subtitle")) ?></p>

    <?php if (canView("leads")): ?>
        <a href="<?= htmlspecialchars(routeUrl("leads")) ?>"><?= htmlspecialchars(t("menu.leads")) ?></a>
    <?php endif; ?>

    <?php if (canView("chat")): ?>
        <a href="<?= htmlspecialchars(routeUrl("chat")) ?>" class="sidebar-chat-link sidebar-open-chat-drawer" data-chat-drawer-url="<?= htmlspecialchars(routeUrl("chat")) ?>">
            <span><?= htmlspecialchars(t("menu.chat")) ?></span>
            <span
                id="chatSidebarBadge"
                class="sidebar-chat-badge"
                <?= empty($chatUnreadSummary['unread_conversations']) ? 'hidden' : '' ?>
            ><?= (int) ($chatUnreadSummary['unread_conversations'] ?? 0) ?></span>
        </a>
    <?php endif; ?>

    <?php if (canView("chat_images_admin")): ?>
        <a href="<?= htmlspecialchars(routeUrl("chat_images_admin")) ?>"><?= htmlspecialchars(t("menu.chat_images_admin")) ?></a>
    <?php endif; ?>

    <?php if (canView("documentos_review")): ?>
        <a href="<?= htmlspecialchars(routeUrl("documents_hub")) ?>" class="sidebar-chat-link">
            <span><?= htmlspecialchars(t("menu.documents_hub")) ?></span>
            <span
                id="documentsSidebarBadge"
                class="sidebar-chat-badge"
                <?= empty($documentsUnreadSummary['pending_count']) ? 'hidden' : '' ?>
            ><?= (int) ($documentsUnreadSummary['pending_count'] ?? 0) ?></span>
        </a>
    <?php endif; ?>

    <?php if (canView("calendario")): ?>
        <a href="<?= htmlspecialchars(routeUrl("calendar")) ?>"><?= htmlspecialchars(t("menu.calendar")) ?></a>
    <?php endif; ?>

    <?php if (canView("tiempos")): ?>
        <a href="<?= htmlspecialchars(routeUrl("times")) ?>"><?= htmlspecialchars(t("menu.times")) ?></a>
    <?php endif; ?>

    <?php if (canView("documentos")): ?>
        <a href="<?= htmlspecialchars(routeUrl("documents")) ?>"><?= htmlspecialchars(t("menu.documents")) ?></a>
    <?php endif; ?>

    <?php if (canView("novedades")): ?>
        <a href="<?= htmlspecialchars(routeUrl("news")) ?>"><?= htmlspecialchars(t("menu.news")) ?></a>
    <?php endif; ?>

    <?php if (canView("subir_leads")): ?>
        <div class="menu-group">
            <span><?= htmlspecialchars(t("menu.upload_group")) ?></span>

            <?php if (canView("cargar")): ?>
                <a href="<?= htmlspecialchars(routeUrl("upload_leads")) ?>"><?= htmlspecialchars(t("menu.upload")) ?></a>
            <?php endif; ?>

            <?php if (canView("actualizar")): ?>
                <a href="<?= htmlspecialchars(routeUrl("update_leads")) ?>"><?= htmlspecialchars(t("menu.update")) ?></a>
            <?php endif; ?>

            <?php if (canView("asignar_individual")): ?>
                <a href="<?= htmlspecialchars(routeUrl("assign_individual")) ?>"><?= htmlspecialchars(t("menu.assign_individual")) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (canView("eliminar_leads")): ?>
        <a href="<?= htmlspecialchars(routeUrl("delete_leads")) ?>"><?= htmlspecialchars(t("menu.delete_leads")) ?></a>
    <?php endif; ?>

    <?php if (canView("usuarios")): ?>
        <a href="<?= htmlspecialchars(routeUrl("users")) ?>"><?= htmlspecialchars(t("menu.users")) ?></a>
    <?php endif; ?>

    <?php if (canView("asignar_usuarios")): ?>
        <a href="<?= htmlspecialchars(routeUrl("assign_users")) ?>"><?= htmlspecialchars(t("menu.assign_users")) ?></a>
    <?php endif; ?>

    <?php if (canView("historico")): ?>
        <a href="<?= htmlspecialchars(routeUrl("history")) ?>"><?= htmlspecialchars(t("menu.history")) ?></a>
    <?php endif; ?>

    <?php if (canView("exportar_leads")): ?>
        <a href="<?= htmlspecialchars(routeUrl("export_leads")) ?>"><?= htmlspecialchars(t("menu.export")) ?></a>
    <?php endif; ?>

    <?php if (canView("monitor")): ?>
        <a href="<?= htmlspecialchars(routeUrl("monitor")) ?>"><?= htmlspecialchars(t("menu.monitor")) ?></a>
    <?php endif; ?>

    <?php if (canView("novedades_aprobar")): ?>
        <a href="<?= htmlspecialchars(routeUrl("news_approve")) ?>"><?= htmlspecialchars(t("menu.approve_news")) ?></a>
    <?php endif; ?>

</div>

<?php if (canView("chat")): ?>
<div id="chatToast" class="chat-toast" hidden>
    <div class="chat-toast-title"><?= htmlspecialchars(t("menu.chat")) ?></div>
    <div id="chatToastMessage" class="chat-toast-message"></div>
</div>
<?php endif; ?>
<?php if (canView("chat")): ?>
<div id="chatDrawerBackdrop" class="chat-drawer-backdrop" hidden></div>
<aside id="chatDrawer" class="chat-drawer" hidden aria-hidden="true">
    <div class="chat-drawer-head">
        <div>
            <div class="chat-drawer-kicker"><?= htmlspecialchars(t("menu.chat")) ?></div>
            <div class="chat-drawer-title">Panel rapido</div>
        </div>
        <div class="chat-drawer-actions">
            <a id="chatDrawerOpenPage" class="chat-drawer-open-page" href="<?= htmlspecialchars(routeUrl("chat")) ?>" target="_blank" rel="noopener">Abrir completo</a>
            <button type="button" id="chatDrawerClose" class="chat-drawer-close" aria-label="Cerrar chat">×</button>
        </div>
    </div>
    <iframe
        id="chatDrawerFrame"
        class="chat-drawer-frame"
        src="about:blank"
        title="Chat"
        loading="lazy"
        referrerpolicy="same-origin"
    ></iframe>
</aside>
<?php endif; ?>
<?php if (canView("documentos_review")): ?>
<div id="documentsToast" class="chat-toast" hidden>
    <div class="chat-toast-title"><?= htmlspecialchars(t("menu.documents_hub")) ?></div>
    <div id="documentsToastMessage" class="chat-toast-message"></div>
</div>
<?php endif; ?>
<?php if (canView("chat") || canView("documentos_review")): ?>
<style>
.sidebar-chat-link{
    display:flex !important;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.sidebar-chat-badge{
    min-width:24px;
    height:24px;
    padding:0 8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:#cb5037;
    color:#fff;
    font-size:.76rem;
    font-weight:700;
    box-shadow:0 10px 18px rgba(203,80,55,.25);
}
.chat-toast{
    position:fixed;
    top:24px;
    left:50%;
    transform:translateX(-50%);
    z-index:9999;
    min-width:320px;
    max-width:460px;
    padding:18px 22px;
    border-radius:22px;
    background:linear-gradient(135deg, rgba(32,145,104,.97), rgba(53,178,122,.94));
    border:1px solid rgba(255,255,255,.28);
    box-shadow:0 22px 44px rgba(25,110,82,.28);
    animation:chatToastPulse 1.1s ease-in-out infinite alternate;
}
.chat-toast-title{
    font-size:.78rem;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:rgba(255,255,255,.82);
    margin-bottom:6px;
}
.chat-toast-message{
    color:#fff;
    font-weight:600;
}
.chat-drawer-backdrop{
    position:fixed;
    inset:0;
    background:rgba(10,16,24,.38);
    backdrop-filter:blur(2px);
    z-index:12010;
}
.chat-drawer-backdrop[hidden],
.chat-drawer[hidden]{
    display:none !important;
}
.chat-drawer{
    position:fixed;
    top:14px;
    right:14px;
    bottom:14px;
    width:min(1120px, calc(100vw - 28px));
    border-radius:30px;
    background:rgba(255,255,255,.98);
    border:1px solid rgba(31,41,51,.1);
    box-shadow:0 34px 64px rgba(10,16,24,.22);
    z-index:12020;
    display:grid;
    grid-template-rows:auto minmax(0,1fr);
    overflow:hidden;
}
.chat-drawer-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:16px 18px 12px;
    border-bottom:1px solid rgba(31,41,51,.08);
    background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(247,248,252,.94));
}
.chat-drawer-kicker{
    font-size:.72rem;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:var(--muted);
}
.chat-drawer-title{
    margin-top:2px;
    font-size:1rem;
    font-weight:800;
    color:var(--ink);
}
.chat-drawer-actions{
    display:flex;
    align-items:center;
    gap:10px;
}
.chat-drawer-open-page{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 14px;
    border-radius:14px;
    text-decoration:none;
    color:var(--ink);
    background:rgba(31,41,51,.06);
    font-weight:700;
}
.chat-drawer-close{
    width:40px;
    height:40px;
    border:none;
    border-radius:14px;
    background:rgba(31,41,51,.08);
    color:var(--ink);
    font-size:1.5rem;
    line-height:1;
    cursor:pointer;
}
.chat-drawer-frame{
    width:100%;
    height:100%;
    border:none;
    background:#fff;
}
body.chat-drawer-open{
    overflow:hidden;
}
@media (max-width:960px){
    .chat-drawer{
        top:8px;
        right:8px;
        bottom:8px;
        left:8px;
        width:auto;
        border-radius:24px;
    }
    .chat-drawer-head{
        padding:14px 14px 10px;
    }
    .chat-drawer-open-page{
        display:none;
    }
}
@keyframes chatToastPulse{
    0%{
        transform:translateX(-50%) scale(1);
        box-shadow:0 22px 44px rgba(25,110,82,.22);
        opacity:.92;
    }
    100%{
        transform:translateX(-50%) scale(1.02);
        box-shadow:0 26px 52px rgba(25,110,82,.36);
        opacity:1;
    }
}
</style>
<script>
(function () {
    const badge = document.getElementById('chatSidebarBadge');
    const documentsBadge = document.getElementById('documentsSidebarBadge');
    const chatDrawer = document.getElementById('chatDrawer');
    const chatDrawerBackdrop = document.getElementById('chatDrawerBackdrop');
    const chatDrawerFrame = document.getElementById('chatDrawerFrame');
    const chatDrawerClose = document.getElementById('chatDrawerClose');
    const chatDrawerOpenPage = document.getElementById('chatDrawerOpenPage');
    const toast = document.getElementById('chatToast');
    const toastMessage = document.getElementById('chatToastMessage');
    const documentsToast = document.getElementById('documentsToast');
    const documentsToastMessage = document.getElementById('documentsToastMessage');
    const notificationsUrl = <?= json_encode(appUrl('core/chat_notifications.php')) ?>;
    const chatUrl = <?= json_encode(routeUrl('chat')) ?>;
    const documentsNotificationsUrl = <?= json_encode(appUrl('core/documentos_notifications.php')) ?>;
    const documentsHubUrl = <?= json_encode(routeUrl('documents_hub')) ?>;
    const documentsNotificationsEnabled = <?= json_encode($documentsNotificationsEnabled) ?>;
    const currentUserId = <?= json_encode((int) ($_SESSION["user_id"] ?? 0)) ?>;
    const chatNotificationStorageKey = 'chat:lastNotificationKey:user:' + currentUserId;
    const documentsNotificationStorageKey = 'documents:lastNotificationId:user:' + currentUserId;
    let hideToastTimer = null;
    let hideDocumentsToastTimer = null;
    let lastNotificationKey = String(sessionStorage.getItem(chatNotificationStorageKey) || '');
    let lastDocumentNotificationId = Number(sessionStorage.getItem(documentsNotificationStorageKey) || 0);
    const originalTitle = document.title.replace(/^\(\d+\)\s*/, '');
    const baseChatRoute = <?= json_encode(routeUrl('chat')) ?>;
    const chatNotificationAudioSrc = <?= json_encode(appUrl('assets/mensaje.mp3')) ?>;
    const chatNotificationAudio = new Audio();
    chatNotificationAudio.src = chatNotificationAudioSrc;
    let chatAudioContext = null;
    let chatAudioEnabled = false;
    let chatAudioUnlockedOnce = false;
    let chatMp3Armed = false;
    let chatNotificationsInFlight = false;
    let documentsNotificationsInFlight = false;

    chatNotificationAudio.preload = 'auto';
    chatNotificationAudio.load();

    async function unlockChatAudio() {
        try {
            if (chatNotificationAudio && !chatMp3Armed) {
                chatNotificationAudio.muted = true;
                chatNotificationAudio.volume = 0;
                chatNotificationAudio.currentTime = 0;
                await chatNotificationAudio.play();
                chatNotificationAudio.pause();
                chatNotificationAudio.currentTime = 0;
                chatNotificationAudio.muted = false;
                chatNotificationAudio.volume = 1;
                chatMp3Armed = true;
            }
        } catch (mp3Error) {
            console.log('Chat mp3 unlock error', mp3Error);
        }

        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }
            if (!chatAudioContext) {
                chatAudioContext = new AudioContextClass();
            }
            if (chatAudioContext.state === 'suspended') {
                await chatAudioContext.resume();
            }
            chatAudioEnabled = true;
            if (!chatAudioUnlockedOnce && chatAudioContext.state === 'running') {
                const oscillator = chatAudioContext.createOscillator();
                const gainNode = chatAudioContext.createGain();
                const now = chatAudioContext.currentTime + 0.01;
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(440, now);
                gainNode.gain.setValueAtTime(0.0001, now);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, now + 0.02);
                oscillator.connect(gainNode);
                gainNode.connect(chatAudioContext.destination);
                oscillator.start(now);
                oscillator.stop(now + 0.02);
                chatAudioUnlockedOnce = true;
            }
        } catch (error) {
            console.log('Chat audio unlock error', error);
        }
    }

    async function playChatNotificationMp3() {
        const audio = new Audio(chatNotificationAudioSrc);
        audio.preload = 'auto';
        audio.volume = 1;
        await audio.play();
    }

    async function playChatNotificationSound() {
        try {
            if (chatNotificationAudioSrc) {
                await unlockChatAudio();
                await playChatNotificationMp3();
                return;
            }
        } catch (audioError) {
            console.log('Chat mp3 notification error', audioError);
        }

        try {
            await unlockChatAudio();
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass || !chatAudioEnabled) {
                return;
            }
            if (!chatAudioContext) {
                chatAudioContext = new AudioContextClass();
            }
            if (chatAudioContext.state === 'suspended') {
                await chatAudioContext.resume();
            }
            if (chatAudioContext.state !== 'running') {
                return;
            }

            const startAt = chatAudioContext.currentTime + 0.01;
            const masterGain = chatAudioContext.createGain();
            masterGain.gain.setValueAtTime(0.0001, startAt);
            masterGain.gain.linearRampToValueAtTime(0.34, startAt + 0.02);
            masterGain.gain.exponentialRampToValueAtTime(0.0001, startAt + 1.45);
            masterGain.connect(chatAudioContext.destination);

            const notes = [
                { freq: 784, at: 0.00, duration: 0.18, type: 'triangle', gain: 0.88 },
                { freq: 1175, at: 0.00, duration: 0.14, type: 'sine', gain: 0.34 },
                { freq: 1046, at: 0.22, duration: 0.20, type: 'triangle', gain: 0.96 },
                { freq: 1568, at: 0.22, duration: 0.16, type: 'sine', gain: 0.38 },
                { freq: 1318, at: 0.48, duration: 0.24, type: 'triangle', gain: 0.98 },
                { freq: 1975, at: 0.48, duration: 0.18, type: 'sine', gain: 0.42 },
                { freq: 1046, at: 0.84, duration: 0.26, type: 'triangle', gain: 0.94 },
                { freq: 1568, at: 0.84, duration: 0.20, type: 'sine', gain: 0.36 }
            ];

            notes.forEach((note) => {
                const oscillator = chatAudioContext.createOscillator();
                const gainNode = chatAudioContext.createGain();
                const toneStart = startAt + note.at;
                const toneEnd = toneStart + note.duration;

                oscillator.type = note.type;
                oscillator.frequency.setValueAtTime(note.freq, toneStart);
                gainNode.gain.setValueAtTime(0.0001, toneStart);
                gainNode.gain.linearRampToValueAtTime(note.gain, toneStart + 0.015);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, toneEnd);

                oscillator.connect(gainNode);
                gainNode.connect(masterGain);
                oscillator.start(toneStart);
                oscillator.stop(toneEnd + 0.03);
            });
        } catch (error) {
            console.log('Chat notification sound error', error);
        }
    }

    function buildChatDrawerUrl(targetType, targetId) {
        const params = new URLSearchParams();
        params.set('embed', '1');
        if (targetType === 'group' && targetId) {
            params.set('mode', 'group');
            params.set('group', String(targetId));
        } else if (targetId) {
            params.set('with', String(targetId));
        }
        return baseChatRoute + '?' + params.toString();
    }

    async function openChatDrawer(targetType, targetId) {
        if (!chatDrawer || !chatDrawerBackdrop || !chatDrawerFrame) {
            window.location.href = buildChatDrawerUrl(targetType, targetId).replace('?embed=1&', '?').replace('?embed=1', '');
            return;
        }
        const drawerUrl = buildChatDrawerUrl(targetType, targetId);
        chatDrawer.hidden = false;
        chatDrawerBackdrop.hidden = false;
        chatDrawer.style.display = 'grid';
        chatDrawerBackdrop.style.display = 'block';
        chatDrawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('chat-drawer-open');
        if (chatDrawerFrame.dataset.currentSrc !== drawerUrl) {
            try {
                const response = await fetch(drawerUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const html = await response.text();
                chatDrawerFrame.srcdoc = html;
                chatDrawerFrame.dataset.currentSrc = drawerUrl;
            } catch (error) {
                chatDrawerFrame.src = drawerUrl;
                chatDrawerFrame.dataset.currentSrc = drawerUrl;
            }
        }
        if (chatDrawerOpenPage) {
            chatDrawerOpenPage.href = drawerUrl.replace('?embed=1&', '?').replace('?embed=1', '');
        }
    }

    function closeChatDrawer() {
        if (!chatDrawer || !chatDrawerBackdrop) return;
        chatDrawer.hidden = true;
        chatDrawerBackdrop.hidden = true;
        chatDrawer.style.display = 'none';
        chatDrawerBackdrop.style.display = 'none';
        chatDrawer.setAttribute('aria-hidden', 'true');
        if (chatDrawerFrame) {
            chatDrawerFrame.src = 'about:blank';
            chatDrawerFrame.removeAttribute('srcdoc');
            delete chatDrawerFrame.dataset.currentSrc;
        }
        document.body.classList.remove('chat-drawer-open');
    }

    if (chatDrawerClose) {
        chatDrawerClose.addEventListener('click', closeChatDrawer);
    }
    if (chatDrawerBackdrop) {
        chatDrawerBackdrop.addEventListener('click', closeChatDrawer);
    }
    document.addEventListener('keydown', function (event) {
        unlockChatAudio();
        if (event.key === 'Escape' && chatDrawer && !chatDrawer.hidden) {
            closeChatDrawer();
        }
    });
    document.addEventListener('pointerdown', unlockChatAudio, { passive: true });
    document.addEventListener('touchstart', unlockChatAudio, { passive: true });
    window.addEventListener('focus', unlockChatAudio);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            unlockChatAudio();
        }
    });
    document.addEventListener('click', function (event) {
        const chatToggle = event.target.closest('.sidebar-open-chat-drawer');
        if (chatToggle) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            if (chatDrawer && !chatDrawer.hidden) {
                closeChatDrawer();
            } else {
                openChatDrawer('direct', null);
            }
            return;
        }

        const normalNav = event.target.closest('.sidebar a:not(.sidebar-open-chat-drawer), .topbar a:not(#chatDrawerOpenPage)');
        if (normalNav) {
            closeChatDrawer();
        }
    }, true);
    window.addEventListener('beforeunload', closeChatDrawer);
    window.addEventListener('pageshow', function () {
        closeChatDrawer();
    });
    window.NCRMChatDrawer = {
        open: openChatDrawer,
        close: closeChatDrawer
    };

    function updateBadge(total) {
        const unreadConversations = Number(total || 0);
        if (!badge) return;
        if (unreadConversations > 0) {
            badge.hidden = false;
            badge.textContent = String(unreadConversations);
        } else {
            badge.hidden = true;
            badge.textContent = '0';
        }
    }

    function showToast(message, targetType, targetId) {
        if (!toast || !toastMessage || !message) return;
        toastMessage.textContent = message;
        toast.hidden = false;

        if (hideToastTimer) {
            clearTimeout(hideToastTimer);
        }

        toast.onclick = function () {
            openChatDrawer(targetType, targetId);
        };

        hideToastTimer = setTimeout(() => {
            toast.hidden = true;
        }, 5000);
    }

    function updateDocumentsBadge(total) {
        const pending = Number(total || 0);
        if (!documentsBadge) return;
        if (pending > 0) {
            documentsBadge.hidden = false;
            documentsBadge.textContent = String(pending);
        } else {
            documentsBadge.hidden = true;
            documentsBadge.textContent = '0';
        }
    }

    function showDocumentsToast(message) {
        if (!documentsToast || !documentsToastMessage || !message) return;
        documentsToastMessage.textContent = message;
        documentsToast.hidden = false;

        if (hideDocumentsToastTimer) {
            clearTimeout(hideDocumentsToastTimer);
        }

        documentsToast.onclick = function () {
            window.location.href = documentsHubUrl;
        };

        hideDocumentsToastTimer = setTimeout(() => {
            documentsToast.hidden = true;
        }, 5000);
    }

    async function pollChatNotifications() {
        if (chatNotificationsInFlight) {
            return;
        }
        chatNotificationsInFlight = true;
        try {
            const requestUrl = new URL(notificationsUrl, window.location.origin);
            requestUrl.searchParams.set('_ts', String(Date.now()));
            const response = await fetch(requestUrl.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data.ok || !data.summary) return;

            const summary = data.summary;
            updateBadge(summary.direct_unread_conversations || 0);

            const latestNotificationKey = String(summary.latest_notification_key || '');
            const latestTargetType = String(summary.latest_target_type || 'direct');
            if (latestNotificationKey && latestTargetType === 'direct' && latestNotificationKey !== lastNotificationKey) {
                lastNotificationKey = latestNotificationKey;
                sessionStorage.setItem(chatNotificationStorageKey, latestNotificationKey);

                const targetType = latestTargetType;
                const senderName = summary.latest_sender_name || 'Nuevo mensaje';
                const targetName = summary.latest_target_name || '';
                const preview = summary.latest_message || '';
                const toastText = targetType === 'group'
                    ? ('Tienes un mensaje en ' + (targetName || 'tu grupo'))
                    : (senderName + ': ' + preview);
                playChatNotificationSound();
                showToast(
                    toastText,
                    targetType,
                    Number(summary.latest_target_id || 0)
                );

                if (Number(summary.direct_unread_conversations || 0) > 0) {
                    document.title = '(' + Number(summary.direct_unread_conversations || 0) + ') ' + originalTitle;
                }
            }

            if (Number(summary.direct_unread_conversations || 0) === 0) {
                lastNotificationKey = '';
                sessionStorage.removeItem(chatNotificationStorageKey);
                document.title = originalTitle;
            } else {
                document.title = '(' + Number(summary.direct_unread_conversations || 0) + ') ' + originalTitle;
            }
        } catch (error) {
            console.log('Chat notifications error', error);
        } finally {
            chatNotificationsInFlight = false;
        }
    }

    async function pollDocumentsNotifications() {
        if (!documentsNotificationsEnabled) {
            return;
        }
        if (documentsNotificationsInFlight) {
            return;
        }
        documentsNotificationsInFlight = true;
        try {
            const requestUrl = new URL(documentsNotificationsUrl, window.location.origin);
            requestUrl.searchParams.set('_ts', String(Date.now()));
            const response = await fetch(requestUrl.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (response.status === 401 || response.status === 403) {
                return;
            }

            const rawText = await response.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (error) {
                console.log('Documents notifications raw response', rawText);
                return;
            }
            if (!response.ok || !data.ok || !data.summary) return;

            const summary = data.summary;
            updateDocumentsBadge(summary.pending_count || 0);

            const latestId = Number(summary.latest_document_id || 0);
            if (latestId > 0 && latestId > lastDocumentNotificationId) {
                lastDocumentNotificationId = latestId;
                sessionStorage.setItem(documentsNotificationStorageKey, String(latestId));
                const advisor = summary.latest_advisor_name || '-';
                const docType = summary.latest_doc_type || '-';
                showDocumentsToast('Nuevo documento de ' + advisor + ' · ' + docType);
            }
        } catch (error) {
            console.log('Documents notifications error', error);
        } finally {
            documentsNotificationsInFlight = false;
        }
    }

    async function refreshSidebarNotificationsNow() {
        const tasks = [pollChatNotifications()];
        if (documentsNotificationsEnabled) {
            tasks.push(pollDocumentsNotifications());
        }
        await Promise.allSettled(tasks);
    }

    function scheduleSidebarChatNotifications() {
        window.setTimeout(async function () {
            await pollChatNotifications();
            scheduleSidebarChatNotifications();
        }, 2500);
    }

    function scheduleSidebarDocumentsNotifications() {
        window.setTimeout(async function () {
            await pollDocumentsNotifications();
            scheduleSidebarDocumentsNotifications();
        }, 12000);
    }

    updateBadge(<?= json_encode((int) ($chatUnreadSummary['unread_conversations'] ?? 0)) ?>);
    updateDocumentsBadge(<?= json_encode((int) ($documentsUnreadSummary['pending_count'] ?? 0)) ?>);
    if (<?= json_encode((string) ($chatUnreadSummary['latest_notification_key'] ?? '')) ?> && !lastNotificationKey) {
        lastNotificationKey = <?= json_encode((string) ($chatUnreadSummary['latest_notification_key'] ?? '')) ?>;
        sessionStorage.setItem(chatNotificationStorageKey, lastNotificationKey);
    }
    if (<?= json_encode((int) ($documentsUnreadSummary['latest_document_id'] ?? 0)) ?> > lastDocumentNotificationId) {
        lastDocumentNotificationId = <?= json_encode((int) ($documentsUnreadSummary['latest_document_id'] ?? 0)) ?>;
        sessionStorage.setItem(documentsNotificationStorageKey, String(lastDocumentNotificationId));
    }
    window.addEventListener('focus', refreshSidebarNotificationsNow);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshSidebarNotificationsNow();
        }
    });
    scheduleSidebarChatNotifications();
    if (documentsNotificationsEnabled) {
        scheduleSidebarDocumentsNotifications();
    }
})();
</script>
<?php endif; ?>
