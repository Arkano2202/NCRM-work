<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";
require_once dirname(__DIR__, 2) . "/core/chat.php";
require_once dirname(__DIR__, 2) . "/core/app.php";

requireLogin();
requirePermission("chat");

$currentUserId = (int) ($_SESSION["user_id"] ?? 0);
$chatImagesEnabled = chatSupportsImages($conn);
$chatGroupsEnabled = chatSupportsGroupChats($conn);
$chatGroupImagesEnabled = $chatGroupsEnabled && chatSupportsGroupImages($conn);

$currentUser = chatGetCurrentUser($conn, $currentUserId);
if (!$currentUser) {
    http_response_code(404);
    exit("Usuario no encontrado");
}

if ($chatGroupsEnabled) {
    chatMarkVisibleGroupsSeen($conn, $currentUser);
}

$selectedMode = trim((string) ($_GET['mode'] ?? 'direct')) === 'group' ? 'group' : 'direct';

$directContacts = chatGetContactRows($conn, $currentUser);
$selectedContactId = (int) ($_GET['with'] ?? 0);
$selectedContact = null;
foreach ($directContacts as $contact) {
    if ((int) ($contact['id'] ?? 0) === $selectedContactId) {
        $selectedContact = $contact;
        break;
    }
}
if (!$selectedContact && !empty($directContacts)) {
    $selectedContact = $directContacts[0];
    $selectedContactId = (int) ($selectedContact['id'] ?? 0);
}

$directMessages = [];
if ($selectedContact) {
    $directMessages = chatGetConversationMessages($conn, $currentUserId, $selectedContactId, $selectedMode === 'direct');
    $directContacts = chatGetContactRows($conn, $currentUser);
    foreach ($directContacts as $contact) {
        if ((int) ($contact['id'] ?? 0) === $selectedContactId) {
            $selectedContact = $contact;
            break;
        }
    }
}

$groupRooms = chatGetVisibleGroupRooms($conn, $currentUser);
$selectedGroupId = (int) ($_GET['group'] ?? 0);
$selectedGroup = null;
foreach ($groupRooms as $room) {
    if ((int) ($room['id'] ?? 0) === $selectedGroupId) {
        $selectedGroup = $room;
        break;
    }
}
if (!$selectedGroup && !empty($groupRooms)) {
    $selectedGroup = $groupRooms[0];
    $selectedGroupId = (int) ($selectedGroup['id'] ?? 0);
}

$groupMessages = [];
if ($selectedGroup) {
    $groupMessages = chatGetGroupMessages($conn, $currentUser, $selectedGroupId);
    $groupRooms = chatGetVisibleGroupRooms($conn, $currentUser);
    foreach ($groupRooms as $room) {
        if ((int) ($room['id'] ?? 0) === $selectedGroupId) {
            $selectedGroup = $room;
            break;
        }
    }
}
if ($selectedMode === 'group' && empty($groupRooms) && !empty($directContacts)) {
    $selectedMode = 'direct';
}

$isEmbed = isset($_GET['embed']) && (string) $_GET['embed'] !== '0';

$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $requestScheme = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
}
$requestHost = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$appOrigin = $requestScheme . '://' . $requestHost;
$toAbsoluteAppUrl = static function (string $path = '') use ($appOrigin): string {
    return rtrim($appOrigin, '/') . appUrl($path);
};
$absoluteReactUrl = $toAbsoluteAppUrl('core/chat_react.php');
$absoluteMessageActionUrl = $toAbsoluteAppUrl('core/chat_message_action.php');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t('chat.title')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.chat-shell{display:grid;gap:0}
.chat-shell.embed-mode{height:100%;min-height:100%}
.chat-shell.embed-mode .chat-layout{height:calc(100vh - 12px);min-height:calc(100vh - 12px);max-height:calc(100vh - 12px)}
.chat-hero,.chat-card{padding:22px 24px;border-radius:28px;background:rgba(255,255,255,.74);border:1px solid rgba(31,41,51,.08)}
.chat-kicker{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.chat-hero{display:none}
.chat-hero p{display:none}
.chat-layout{display:grid;grid-template-columns:minmax(300px,360px) minmax(0,1fr);gap:18px;height:82vh;min-height:82vh;max-height:82vh;align-items:stretch}
.chat-card-contacts{display:flex;flex-direction:column;height:100%;min-height:100%;max-height:100%;overflow:hidden}
.chat-mode-tabs{display:flex;gap:10px;margin:14px 0 18px}
.chat-search-box{margin-bottom:14px}
.chat-search-box input{width:100%;padding:12px 14px;border-radius:16px;border:1px solid rgba(31,41,51,.12);background:rgba(255,255,255,.86)}
.chat-list-actions{display:flex;justify-content:flex-end;margin:-4px 0 12px}
.chat-clear-button{border:none;background:rgba(31,41,51,.08);color:var(--ink);padding:9px 12px;border-radius:14px;cursor:pointer;font-weight:700;font-size:.82rem}
.chat-clear-button:hover{background:rgba(53,102,188,.12)}
.chat-mode-tab{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 14px;border-radius:16px;border:1px solid rgba(31,41,51,.1);background:rgba(255,255,255,.82);color:var(--muted);font-weight:700;cursor:pointer;transition:all .18s ease;position:relative}
.chat-mode-tab.active{background:linear-gradient(135deg,rgba(53,102,188,.12),rgba(203,80,55,.08));border-color:rgba(53,102,188,.22);color:var(--ink);box-shadow:0 14px 26px rgba(53,102,188,.12)}
.chat-mode-tab.has-unread{background:linear-gradient(135deg,rgba(255,191,128,.42),rgba(255,224,194,.72));border-color:rgba(235,136,42,.34);color:#8b4513;box-shadow:0 14px 26px rgba(235,136,42,.16)}
.chat-mode-tab.active.has-unread{background:linear-gradient(135deg,rgba(255,179,102,.54),rgba(255,221,186,.88));border-color:rgba(235,136,42,.44);color:#7a3c10;box-shadow:0 16px 30px rgba(235,136,42,.22)}
.chat-mode-dot{width:10px;height:10px;border-radius:999px;background:#f28c28;box-shadow:0 0 0 6px rgba(242,140,40,.16);display:inline-block;flex:0 0 auto}
.chat-mode-badge{min-width:24px;height:24px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#cb5037;color:#fff;font-size:.76rem;font-weight:700;box-shadow:0 10px 18px rgba(203,80,55,.22)}
.chat-list{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0;overflow:auto;padding-right:4px}
.chat-contact{display:block;padding:16px 18px;border-radius:22px;background:linear-gradient(180deg,rgba(255,255,255,.9),rgba(248,249,252,.72));border:1px solid rgba(31,41,51,.08);text-decoration:none;color:inherit;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;cursor:pointer}
.chat-contact:hover{transform:translateY(-1px);box-shadow:0 14px 26px rgba(31,41,51,.08)}
.chat-contact.active{border-color:rgba(53,102,188,.34);box-shadow:0 18px 34px rgba(53,102,188,.14);background:linear-gradient(135deg,rgba(53,102,188,.08),rgba(203,80,55,.06))}
.chat-contact-head,.chat-contact-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}
.chat-contact-name{font-weight:700;color:var(--ink);font-size:1rem}
.chat-contact-role,.chat-contact-preview,.chat-contact-time,.chat-empty,.chat-header-meta,.chat-compose-note{color:var(--muted)}
.chat-contact-role{font-size:.92rem}
.chat-contact-preview{margin-top:8px;font-size:.92rem;line-height:1.35;min-height:1.2em}
.chat-contact-time{font-size:.8rem;white-space:nowrap}
.chat-badge{min-width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(203,80,55,.12);color:#cb5037;font-size:.82rem;font-weight:700}
.chat-panel{position:relative;display:grid;grid-template-rows:auto minmax(0,1fr) auto;height:100%;min-height:100%;max-height:100%;overflow:hidden}
.chat-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding-bottom:6px;border-bottom:1px solid rgba(31,41,51,.08)}
.chat-header .chat-kicker{display:none}
.chat-header h2{margin:0 0 2px;font-size:1.15rem;line-height:1.2}
.chat-header-meta{font-size:.9rem}
.chat-thread{display:flex;flex-direction:column;gap:16px;padding:12px 56px 14px 42px;overflow:auto;min-height:0}
.chat-message-wrap{position:relative;display:flex;flex-direction:column;gap:6px;max-width:min(82%,680px);overflow:visible}
.chat-message-wrap.mine{margin-left:auto;align-items:flex-end}
.chat-message-sender{font-size:.8rem;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);font-weight:700;padding:0 6px}
.chat-message{position:relative;padding:16px 18px;border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.92),rgba(248,249,252,.8));border:1px solid rgba(31,41,51,.08);box-shadow:0 10px 18px rgba(31,41,51,.04);line-height:1.45}
.chat-message.mine{background:linear-gradient(135deg,rgba(51,126,190,.16),rgba(203,80,55,.12));border-color:rgba(53,102,188,.16)}
.chat-message-body{white-space:pre-wrap;word-break:break-word}
.chat-message-body.deleted{font-style:italic;color:var(--muted)}
.chat-message-meta{margin-top:10px;font-size:.78rem;color:var(--muted)}
.chat-reply-quote{display:block;width:100%;margin-bottom:10px;padding:10px 12px;border:none;border-left:4px solid rgba(53,102,188,.4);border-radius:16px;background:rgba(53,102,188,.08);font-size:.84rem;text-align:left}
.chat-reply-quote.is-link{cursor:pointer;transition:transform .16s ease,box-shadow .16s ease}
.chat-reply-quote.is-link:hover{transform:translateY(-1px);box-shadow:0 12px 20px rgba(53,102,188,.12)}
.chat-reply-quote.mine{background:rgba(203,80,55,.08);border-left-color:rgba(203,80,55,.42)}
.chat-reply-quote-label{font-weight:800;color:var(--ink);margin-bottom:4px}
.chat-reply-quote-text{color:var(--muted)}
.chat-message-wrap.jump-highlight .chat-message{box-shadow:0 0 0 3px rgba(235,136,42,.24),0 18px 36px rgba(235,136,42,.18)}
.chat-reactions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.chat-reaction-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(31,41,51,.06);border:1px solid rgba(31,41,51,.08);font-size:.84rem;cursor:pointer}
.chat-reaction-chip.mine{background:rgba(235,136,42,.14);border-color:rgba(235,136,42,.28)}
.chat-reaction-chip.gorilla,.chat-reaction-option.gorilla,.chat-reaction-chip.italian-hand,.chat-reaction-option.italian-hand{animation:gorillaBounce 1s ease-in-out infinite}
.chat-message-actions{position:absolute;top:50%;left:calc(100% + 10px);transform:translateY(-50%);display:flex;flex-direction:column;align-items:center;gap:8px;flex-wrap:nowrap;z-index:2}
.chat-message-actions.mine{left:auto;right:calc(100% + 10px);justify-content:flex-end}
.chat-reaction-trigger-wrap{position:relative}
.chat-reaction-trigger{width:32px;height:32px;border-radius:999px;border:1px dashed rgba(31,41,51,.16);background:rgba(255,255,255,.92);cursor:pointer;color:var(--ink);font-size:1rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 16px rgba(15,23,42,.08)}
.chat-reaction-picker{position:absolute;left:50%;top:0;transform:translateX(-50%);z-index:30;display:grid;grid-template-columns:repeat(5,34px);gap:8px;min-width:max-content;padding:10px;border-radius:16px;background:#fff;border:1px solid rgba(31,41,51,.08);box-shadow:0 16px 30px rgba(15,23,42,.12);margin-top:-54px}
.chat-message-actions.mine .chat-reaction-picker{left:50%;right:auto;margin-left:0;margin-right:0}
.chat-reaction-picker[hidden]{display:none}
.chat-reaction-option{width:34px;height:34px;border:none;border-radius:10px;background:rgba(31,41,51,.04);cursor:pointer;font-size:1.05rem;display:inline-flex;align-items:center;justify-content:center}
.chat-reaction-option:hover{background:rgba(235,136,42,.14)}
.chat-reply-trigger{width:32px;height:32px;border-radius:999px;border:1px dashed rgba(31,41,51,.16);background:rgba(255,255,255,.92);cursor:pointer;color:var(--ink);font-size:.95rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 16px rgba(15,23,42,.08)}
@keyframes gorillaBounce{
    0%,100%{transform:translateY(0) scale(1)}
    25%{transform:translateY(-2px) rotate(-4deg) scale(1.04)}
    50%{transform:translateY(0) rotate(0deg) scale(1.08)}
    75%{transform:translateY(-1px) rotate(4deg) scale(1.04)}
}
.chat-form{display:grid;grid-template-columns:1fr auto auto;gap:12px;padding-top:16px;border-top:1px solid rgba(31,41,51,.08);align-items:end}
.chat-form textarea{min-height:92px;max-height:140px;resize:none;border-radius:20px;overflow:auto}
.chat-form button{min-width:120px;align-self:end}
.chat-compose-note{margin-top:8px;font-size:.86rem}
.chat-reply-banner{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-top:10px;padding:12px 14px;border-radius:18px;background:rgba(53,102,188,.08);border:1px solid rgba(53,102,188,.16)}
.chat-reply-banner[hidden]{display:none}
.chat-reply-banner-main{min-width:0}
.chat-reply-banner-title{font-weight:800;color:var(--ink);margin-bottom:4px}
.chat-reply-banner-text{font-size:.88rem;color:var(--muted);word-break:break-word}
.chat-reply-banner-close{width:32px;height:32px;border:none;border-radius:999px;background:rgba(31,41,51,.08);cursor:pointer;font-weight:800}
.chat-edit-banner{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-top:10px;padding:12px 14px;border-radius:18px;background:rgba(235,136,42,.12);border:1px solid rgba(235,136,42,.22)}
.chat-edit-banner[hidden]{display:none}
.chat-edit-banner-main{min-width:0}
.chat-edit-banner-title{font-weight:800;color:var(--ink);margin-bottom:4px}
.chat-edit-banner-text{font-size:.88rem;color:var(--muted);word-break:break-word}
.chat-edit-banner-close{width:32px;height:32px;border:none;border-radius:999px;background:rgba(31,41,51,.08);cursor:pointer;font-weight:800}
.chat-pending-indicator{position:absolute;right:24px;bottom:124px;z-index:8;display:inline-flex;align-items:center;gap:10px;padding:12px 16px;border:none;border-radius:999px;background:linear-gradient(135deg,#f28c28,#cb5037);color:#fff;font-weight:800;cursor:pointer;box-shadow:0 18px 32px rgba(203,80,55,.24)}
.chat-pending-indicator[hidden]{display:none}
.chat-pending-indicator-count{min-width:24px;height:24px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(255,255,255,.2);font-size:.8rem}
.chat-image-trigger{min-width:64px;height:52px;border-radius:18px;border:1px dashed rgba(31,41,51,.18);background:rgba(255,255,255,.8);color:var(--ink);font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:1.4rem;line-height:1}
.chat-image-input{display:none}
.chat-image-hint{margin-top:6px;font-size:.78rem;color:var(--muted)}
.chat-image-preview[hidden],.chat-compose-extras[hidden],.chat-image-modal[hidden]{display:none}
.chat-compose-extras{margin-top:10px}
.chat-image-preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:12px;margin-top:10px}
.chat-image-preview-item{position:relative;padding:10px;border-radius:18px;background:rgba(53,102,188,.08);border:1px solid rgba(53,102,188,.14)}
.chat-image-preview-thumb{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:14px;border:1px solid rgba(31,41,51,.12);background:#fff;display:block}
.chat-file-preview-thumb{width:100%;aspect-ratio:1/1;border-radius:14px;border:1px solid rgba(31,41,51,.12);background:linear-gradient(135deg,rgba(203,80,55,.12),rgba(53,102,188,.10));display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:var(--ink)}
.chat-image-preview-remove{position:absolute;top:6px;right:6px;width:28px;height:28px;border:none;border-radius:999px;background:rgba(10,16,24,.72);color:#fff;font-size:1rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;line-height:1}
.chat-image-preview-name{font-weight:700;color:var(--ink);word-break:break-word;font-size:.84rem;margin-top:8px}
.chat-image-preview-meta{font-size:.76rem;color:var(--muted);margin-top:4px}
.chat-image-card{display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:0;border:none;background:transparent}
.chat-image-card-preview{display:flex;flex-direction:column;align-items:flex-start;gap:10px;min-width:0;max-width:min(360px,100%)}
.chat-image-thumb{width:min(320px,100%);max-width:100%;max-height:320px;object-fit:cover;border-radius:20px;border:1px solid rgba(31,41,51,.12);box-shadow:0 12px 24px rgba(31,41,51,.10);background:#fff;cursor:pointer}
.chat-file-thumb{width:88px;height:88px;border-radius:16px;border:1px solid rgba(31,41,51,.12);box-shadow:0 10px 18px rgba(31,41,51,.08);background:linear-gradient(135deg,rgba(203,80,55,.12),rgba(53,102,188,.10));display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:var(--ink)}
.chat-image-name{font-weight:700;color:var(--ink);word-break:break-word}
.chat-image-button{padding:10px 14px;border:none;border-radius:14px;background:linear-gradient(135deg,#1d9f71,#3cc08a);color:#fff;font-weight:700;cursor:pointer}
.chat-image-note{font-size:.82rem;color:var(--muted);margin:0}
.chat-image-paste-note{font-size:.78rem;color:var(--muted);margin-top:4px}
.chat-image-modal{position:fixed;inset:0;z-index:12000;background:rgba(10,16,24,.62);display:flex;align-items:center;justify-content:center;padding:10px}
.chat-image-modal-panel{width:min(97vw,1280px);height:min(97vh,1040px);padding:22px 24px;border-radius:30px;background:rgba(255,255,255,.98);box-shadow:0 30px 60px rgba(10,16,24,.3);display:grid;grid-template-rows:auto 1fr auto;gap:14px}
.chat-image-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.chat-image-modal-title{font-weight:800;font-size:1.1rem;color:var(--ink)}
.chat-image-modal-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.chat-image-modal-zoom{display:flex;align-items:center;gap:8px}
.chat-image-modal-zoom button{border:none;background:rgba(31,41,51,.08);color:var(--ink);padding:10px 12px;border-radius:14px;cursor:pointer;font-weight:800;min-width:44px}
.chat-image-modal-zoom-label{font-size:.82rem;color:var(--muted);min-width:46px;text-align:center}
.chat-image-modal-download,.chat-image-modal-close{border:none;background:rgba(31,41,51,.08);color:var(--ink);padding:10px 14px;border-radius:14px;cursor:pointer;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.chat-image-modal-download{background:linear-gradient(135deg,#1d9f71,#3cc08a);color:#fff}
.chat-image-modal-body{display:flex;align-items:center;justify-content:center;overflow:auto;min-height:0;padding:4px 8px}
.chat-image-modal-body img{max-width:100%;max-height:88vh;border-radius:22px;box-shadow:0 18px 36px rgba(10,16,24,.16);transform-origin:center center;transition:transform .14s ease;cursor:zoom-in}
.chat-image-modal-foot{font-size:.86rem;color:var(--muted)}
.chat-context-menu{position:fixed;z-index:13050;min-width:170px;padding:8px;border-radius:16px;background:rgba(255,255,255,.98);border:1px solid rgba(31,41,51,.1);box-shadow:0 22px 44px rgba(15,23,42,.18)}
.chat-context-menu[hidden]{display:none}
.chat-context-menu button{width:100%;display:flex;align-items:center;justify-content:flex-start;gap:8px;border:none;background:transparent;color:var(--ink);padding:10px 12px;border-radius:12px;cursor:pointer;font-weight:700}
.chat-context-menu button:hover{background:rgba(53,102,188,.08)}
.chat-context-menu button.is-danger:hover{background:rgba(203,80,55,.12);color:#b63d27}
body.chat-embed{
    min-height:100vh;
    margin:0;
    padding:6px;
    overflow:hidden;
    background:
        radial-gradient(circle at top left, rgba(203,80,55,.08), transparent 28%),
        radial-gradient(circle at top right, rgba(53,102,188,.08), transparent 28%),
        linear-gradient(180deg, #f8f6f2, #f5f7fb);
}
body.chat-embed .chat-card{
    background:rgba(255,255,255,.9);
}
@media (max-width:960px){.chat-hero{display:none}.chat-layout{grid-template-columns:1fr;height:auto;min-height:auto;max-height:none}.chat-card-contacts,.chat-panel{min-height:auto;max-height:none;height:auto}.chat-list{min-height:220px;max-height:420px}.chat-thread{padding:12px 14px}.chat-message-wrap{max-width:100%}.chat-message-actions,.chat-message-actions.mine{position:static;transform:none;flex-direction:row;justify-content:flex-end;margin-top:10px}.chat-form{grid-template-columns:1fr}.chat-image-trigger,.chat-form button{width:100%}.chat-image-card{flex-direction:column;align-items:flex-start}.chat-image-card-preview{width:100%}.chat-mode-tabs{flex-direction:column}.chat-reaction-picker{grid-template-columns:repeat(4,34px)}.chat-pending-indicator{right:14px;left:14px;bottom:168px;justify-content:center}}
</style>
</head>
<body<?= $isEmbed ? ' class="chat-embed"' : '' ?>>
<?php if ($isEmbed): ?>
        <div class="chat-shell embed-mode">
<?php else: ?>
<div class="dashboard">
    <?php include dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>
    <div class="main">
        <?php include dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

        <div class="chat-shell">
<?php endif; ?>
            <section class="chat-layout">
                <aside class="chat-card chat-card-contacts">
                    <div class="chat-kicker"><?= htmlspecialchars(t('chat.contacts')) ?></div>
                    <div class="chat-mode-tabs" id="chatModeTabs">
                        <button type="button" class="chat-mode-tab" data-mode="direct"><?= htmlspecialchars(t('chat.direct')) ?></button>
                        <button type="button" class="chat-mode-tab" data-mode="group"><?= htmlspecialchars(t('chat.groups')) ?></button>
                    </div>
                    <div class="chat-search-box">
                        <input type="text" id="chatSearchInput" placeholder="<?= htmlspecialchars(t('chat.search_placeholder')) ?>" autocomplete="off">
                    </div>
                    <div class="chat-list-actions">
                        <button type="button" id="chatClearButton" class="chat-clear-button">Limpiar chat</button>
                    </div>
                    <div class="chat-list" id="chatContactList"></div>
                </aside>

                <section class="chat-card chat-panel">
                    <div class="chat-header">
                        <div>
                            <div class="chat-kicker" id="chatHeaderKicker"><?= htmlspecialchars($selectedMode === 'group' ? t('chat.group_chats') : t('chat.contacts')) ?></div>
                            <h2 id="chatHeaderName"><?= htmlspecialchars($selectedMode === 'group' && $selectedGroup ? $selectedGroup['name'] : ($selectedContact ? $selectedContact['name'] : t('chat.title'))) ?></h2>
                            <div class="chat-header-meta" id="chatHeaderMeta"></div>
                        </div>
                    </div>

                    <div class="chat-thread" id="chatThread"></div>
                    <button type="button" id="chatPendingIndicator" class="chat-pending-indicator" hidden>
                        <span>↓ Mensajes nuevos</span>
                        <span id="chatPendingIndicatorCount" class="chat-pending-indicator-count">0</span>
                    </button>

                    <form class="chat-form" id="chatForm">
                        <textarea id="chatMessageInput" placeholder="<?= htmlspecialchars(t('chat.placeholder')) ?>"></textarea>
                        <?php if ($chatImagesEnabled): ?>
                            <label class="chat-image-trigger" id="chatImageTrigger" for="chatImageInput" title="Adjuntar archivo">📎</label>
                            <input type="file" id="chatImageInput" class="chat-image-input" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" multiple>
                        <?php endif; ?>
                        <button type="submit" class="btn-primary" id="chatSubmitButton"><?= htmlspecialchars(t('chat.send')) ?></button>
                    </form>
                    <div id="chatReplyBanner" class="chat-reply-banner" hidden>
                        <div class="chat-reply-banner-main">
                            <div class="chat-reply-banner-title">Respondiendo a <span id="chatReplyBannerName"></span></div>
                            <div id="chatReplyBannerText" class="chat-reply-banner-text"></div>
                        </div>
                        <button type="button" id="chatReplyBannerClose" class="chat-reply-banner-close">×</button>
                    </div>
                    <div id="chatEditBanner" class="chat-edit-banner" hidden>
                        <div class="chat-edit-banner-main">
                            <div class="chat-edit-banner-title">Editando mensaje</div>
                            <div id="chatEditBannerText" class="chat-edit-banner-text"></div>
                        </div>
                        <button type="button" id="chatEditBannerClose" class="chat-edit-banner-close">×</button>
                    </div>
                    <div id="chatComposeNote" class="chat-compose-note"></div>
                    <?php if ($chatImagesEnabled): ?>
                        <div id="chatComposeExtras" class="chat-compose-extras">
                            <div id="chatImageHint" class="chat-image-hint"></div>
                            <div id="chatImagePreview" class="chat-image-preview" hidden></div>
                            <div class="chat-image-paste-note">Tambien puedes pegar una imagen con Ctrl + V o adjuntar un PDF.</div>
                        </div>
                    <?php endif; ?>
                </section>
            </section>
        </div>
<?php if ($isEmbed): ?>
<?php else: ?>
    </div>
</div>
<?php endif; ?>

<?php if ($chatImagesEnabled): ?>
<div id="chatImageModal" class="chat-image-modal" hidden>
    <div class="chat-image-modal-panel">
        <div class="chat-image-modal-head">
            <div class="chat-image-modal-title" id="chatImageModalTitle">Imagen temporal</div>
            <div class="chat-image-modal-actions">
                <div class="chat-image-modal-zoom">
                    <button type="button" id="chatImageZoomOut" aria-label="Alejar">−</button>
                    <div class="chat-image-modal-zoom-label" id="chatImageZoomLabel">100%</div>
                    <button type="button" id="chatImageZoomIn" aria-label="Acercar">+</button>
                    <button type="button" id="chatImageZoomReset" aria-label="Restablecer zoom">100%</button>
                </div>
                <a id="chatImageModalDownload" class="chat-image-modal-download" href="#" download>Descargar</a>
                <button type="button" id="chatImageModalClose" class="chat-image-modal-close">Cerrar</button>
            </div>
        </div>
        <div class="chat-image-modal-body">
            <img id="chatImageModalImg" src="" alt="Imagen temporal">
        </div>
        <div class="chat-image-modal-foot">La imagen seguira disponible hasta que la limpieza automatica la borre despues de 15 dias.</div>
    </div>
</div>
<?php endif; ?>

<div id="chatContextMenu" class="chat-context-menu" hidden>
    <button type="button" data-chat-context-action="edit">✏ Editar</button>
    <button type="button" data-chat-context-action="delete" class="is-danger">🗑 Eliminar</button>
</div>

<script>
const chatState = {
    mode: <?= json_encode($selectedMode) ?>,
    directContacts: <?= json_encode($directContacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    groupRooms: <?= json_encode($groupRooms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    directMessages: <?= json_encode($directMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    groupMessages: <?= json_encode($groupMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    selectedDirectId: <?= json_encode($selectedContactId > 0 ? $selectedContactId : null) ?>,
    selectedGroupId: <?= json_encode($selectedGroupId > 0 ? $selectedGroupId : null) ?>,
    selectedContact: <?= json_encode($selectedContact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    selectedGroup: <?= json_encode($selectedGroup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    currentUserName: <?= json_encode(trim((string) ($_SESSION["nombre"] ?? $_SESSION["usuario"] ?? 'Yo'))) ?>,
    pollUrlBase: <?= json_encode($toAbsoluteAppUrl('core/chat_poll.php')) ?>,
    sendUrl: <?= json_encode($toAbsoluteAppUrl('core/chat_send.php')) ?>,
    groupPollUrlBase: <?= json_encode($toAbsoluteAppUrl('core/chat_group_poll.php')) ?>,
    groupSendUrl: <?= json_encode($toAbsoluteAppUrl('core/chat_group_send.php')) ?>,
    notificationsUrl: <?= json_encode($toAbsoluteAppUrl('core/chat_notifications.php')) ?>,
    imageViewUrl: <?= json_encode($toAbsoluteAppUrl('core/chat_image_view.php')) ?>,
    messageActionUrl: <?= json_encode($absoluteMessageActionUrl) ?>,
    markAllReadUrl: <?= json_encode($toAbsoluteAppUrl('core/chat_mark_all_read.php')) ?>,
    routeBase: <?= json_encode($toAbsoluteAppUrl(routePath('chat'))) ?>,
    appOrigin: <?= json_encode($appOrigin) ?>,
    isEmbed: <?= json_encode($isEmbed) ?>,
    imagesEnabled: <?= json_encode($chatImagesEnabled) ?>,
    groupsEnabled: <?= json_encode($chatGroupsEnabled) ?>,
    groupImagesEnabled: <?= json_encode($chatGroupImagesEnabled) ?>,
    emptyText: <?= json_encode(t('chat.empty')) ?>,
    startText: <?= json_encode(t('chat.start')) ?>,
    noContactsText: <?= json_encode(t('chat.no_contacts')) ?>,
    noGroupsText: <?= json_encode(t('chat.no_groups')) ?>,
    noMatchesText: <?= json_encode(t('chat.no_matches')) ?>,
    unreadText: <?= json_encode(t('chat.unread')) ?>,
    reactText: <?= json_encode(t('chat.react')) ?>,
    reactionsCatalog: <?= json_encode(chatAllowedReactionCatalog($currentUser), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    directLabel: <?= json_encode(t('chat.contacts')) ?>,
    groupsLabel: <?= json_encode(t('chat.group_chats')) ?>,
    groupReadOnlyText: <?= json_encode(t('chat.group_read_only')) ?>,
    groupWriteHelp: <?= json_encode(t('chat.group_write_help')) ?>,
    groupDisabledText: <?= json_encode(t('chat.group_not_enabled')) ?>,
    directUnreadConversations: <?= json_encode(array_reduce($directContacts, static function ($carry, $contact) { return $carry + (!empty($contact['unread_count']) ? 1 : 0); }, 0)) ?>,
    groupUnreadConversations: <?= json_encode(array_reduce($groupRooms, static function ($carry, $room) { return $carry + (!empty($room['unread_count']) ? 1 : 0); }, 0)) ?>,
    repliesEnabled: <?= json_encode(chatSupportsDirectReplies($conn)) ?>,
    repliesDisabledText: <?= json_encode('Falta activar las columnas de respuesta en chat_mensajes para ver la miniatura de respuesta.') ?>,
    pendingReply: null,
    pendingEdit: null,
};

const modeTabs = document.getElementById('chatModeTabs');
const chatSearchInput = document.getElementById('chatSearchInput');
const contactList = document.getElementById('chatContactList');
const chatHeaderKicker = document.getElementById('chatHeaderKicker');
const chatHeaderName = document.getElementById('chatHeaderName');
const chatHeaderMeta = document.getElementById('chatHeaderMeta');
const chatThread = document.getElementById('chatThread');
const chatPendingIndicator = document.getElementById('chatPendingIndicator');
const chatPendingIndicatorCount = document.getElementById('chatPendingIndicatorCount');
const chatForm = document.getElementById('chatForm');
const chatMessageInput = document.getElementById('chatMessageInput');
const chatSubmitButton = document.getElementById('chatSubmitButton');
const chatClearButton = document.getElementById('chatClearButton');
const chatReplyBanner = document.getElementById('chatReplyBanner');
const chatReplyBannerName = document.getElementById('chatReplyBannerName');
const chatReplyBannerText = document.getElementById('chatReplyBannerText');
const chatReplyBannerClose = document.getElementById('chatReplyBannerClose');
const chatEditBanner = document.getElementById('chatEditBanner');
const chatEditBannerText = document.getElementById('chatEditBannerText');
const chatEditBannerClose = document.getElementById('chatEditBannerClose');
const chatComposeNote = document.getElementById('chatComposeNote');
const chatImageInput = document.getElementById('chatImageInput');
const chatImageTrigger = document.getElementById('chatImageTrigger');
const chatComposeExtras = document.getElementById('chatComposeExtras');
const chatImageHint = document.getElementById('chatImageHint');
const chatImagePreview = document.getElementById('chatImagePreview');
const chatImageModal = document.getElementById('chatImageModal');
const chatImageModalImg = document.getElementById('chatImageModalImg');
const chatImageModalTitle = document.getElementById('chatImageModalTitle');
const chatImageModalClose = document.getElementById('chatImageModalClose');
const chatImageModalDownload = document.getElementById('chatImageModalDownload');
const chatImageZoomIn = document.getElementById('chatImageZoomIn');
const chatImageZoomOut = document.getElementById('chatImageZoomOut');
const chatImageZoomReset = document.getElementById('chatImageZoomReset');
const chatImageZoomLabel = document.getElementById('chatImageZoomLabel');
const chatContextMenu = document.getElementById('chatContextMenu');
let selectedImageEntries = [];
let chatSearchTerm = '';
let openReactionPickerKey = '';
let lastThreadContextKey = '';
let lastThreadMessageCount = 0;
let lastThreadLatestKey = '';
let lastThreadRenderSignature = '';
let forceScrollToBottom = true;
let chatImageZoomLevel = 1;
let contextMenuMessageId = 0;
let renderedThreadMessages = [];
let pendingThreadMessages = null;
let pendingIncomingCount = 0;
let chatModeRefreshInFlight = false;
let chatModeRefreshQueued = false;
let chatUnreadRefreshInFlight = false;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeHtmlWithBreaks(value) {
    return escapeHtml(value).replace(/\r\n|\r|\n/g, '<br>');
}

function linkifyMessageText(value) {
    const escaped = escapeHtml(String(value || ''));
    const withLinks = escaped.replace(/(^|[\s(>])((?:https?:\/\/|www\.)[^\s<]+)/gi, function (match, prefix, rawUrl) {
        const href = rawUrl.toLowerCase().startsWith('www.') ? ('https://' + rawUrl) : rawUrl;
        return `${prefix}<a href="${href}" target="_blank" rel="noopener noreferrer">${rawUrl}</a>`;
    });
    return withLinks.replace(/\r\n|\r|\n/g, '<br>');
}

function isThreadNearBottom() {
    if (!chatThread) {
        return true;
    }
    const distanceToBottom = chatThread.scrollHeight - chatThread.scrollTop - chatThread.clientHeight;
    return distanceToBottom <= 72;
}

function getMessageUniqueKey(message) {
    return [
        String(message.kind || 'text'),
        String(message.image_id || message.id || 0),
        String(message.enviado_en || ''),
    ].join(':');
}

function countIncomingMessages(messages) {
    const seenKeys = new Set(renderedThreadMessages.map(getMessageUniqueKey));
    let count = 0;
    messages.forEach((message) => {
        const key = getMessageUniqueKey(message);
        if (!seenKeys.has(key)) {
            count++;
        }
    });
    return count;
}

function buildThreadRenderSignature(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
        return '';
    }

    return messages.map((message) => {
        const reactions = Array.isArray(message.reactions)
            ? message.reactions.map((reaction) => [
                String(reaction.emoji || ''),
                Number(reaction.count || 0),
                reaction.mine ? 1 : 0,
            ].join(':')).join('|')
            : '';

        const replyPreview = message.reply_preview || null;
        const replyKey = replyPreview
            ? [
                String(replyPreview.type || ''),
                Number(replyPreview.id || 0),
                String(replyPreview.excerpt || ''),
            ].join(':')
            : '';

        return [
            String(message.kind || 'text'),
            Number(message.image_id || message.id || 0),
            String(message.enviado_en || ''),
            String(message.mensaje || ''),
            message.deleted ? 1 : 0,
            message.edited ? 1 : 0,
            String(message.estado || ''),
            reactions,
            replyKey,
        ].join('~');
    }).join('||');
}

function renderPendingIndicator() {
    if (!chatPendingIndicator || !chatPendingIndicatorCount) {
        return;
    }
    if (pendingIncomingCount > 0) {
        chatPendingIndicator.hidden = false;
        chatPendingIndicatorCount.textContent = String(pendingIncomingCount);
    } else {
        chatPendingIndicator.hidden = true;
        chatPendingIndicatorCount.textContent = '0';
    }
}

function clearPendingThreadMessages() {
    pendingThreadMessages = null;
    pendingIncomingCount = 0;
    renderPendingIndicator();
}

function applyPendingThreadMessages() {
    if (!pendingThreadMessages) {
        return;
    }
    forceScrollToBottom = true;
    clearPendingThreadMessages();
    renderMessages();
}

function formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (value <= 0) return '';
    if (value < 1024) return value + ' B';
    if (value < 1024 * 1024) return (value / 1024).toFixed(1) + ' KB';
    return (value / (1024 * 1024)).toFixed(1) + ' MB';
}

function clearImagePreview() {
    if (chatImagePreview) {
        chatImagePreview.hidden = true;
        chatImagePreview.innerHTML = '';
    }
    selectedImageEntries.forEach((entry) => {
        if (entry.previewUrl) {
            URL.revokeObjectURL(entry.previewUrl);
        }
    });
    selectedImageEntries = [];
}

function isPdfFile(file) {
    if (!file) {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    const name = String(file.name || '').toLowerCase();
    return type === 'application/pdf' || name.endsWith('.pdf');
}

function renderImagePreview() {
    if (!chatImagePreview) {
        return;
    }

    if (selectedImageEntries.length === 0) {
        clearImagePreview();
        return;
    }

    chatImagePreview.hidden = false;
    chatImagePreview.innerHTML = selectedImageEntries.map((entry) => `
        <div class="chat-image-preview-item">
            <button type="button" class="chat-image-preview-remove" data-preview-id="${entry.id}" title="Quitar archivo">×</button>
            ${entry.isPdf
                ? '<div class="chat-file-preview-thumb">PDF</div>'
                : `<img class="chat-image-preview-thumb" src="${entry.previewUrl}" alt="${escapeHtml(entry.file.name || 'Imagen seleccionada')}">`
            }
            <div class="chat-image-preview-name">${escapeHtml(entry.file.name || (entry.isPdf ? 'PDF seleccionado' : 'Imagen seleccionada'))}</div>
            <div class="chat-image-preview-meta">${escapeHtml([entry.sourceLabel, formatBytes(entry.file.size)].filter(Boolean).join(' · '))}</div>
        </div>
    `).join('');
}

function syncImageHint() {
    if (!chatImageHint) {
        return;
    }

    if (selectedImageEntries.length === 0) {
        chatImageHint.textContent = '';
        return;
    }

    chatImageHint.textContent = 'Archivos seleccionados: ' + selectedImageEntries.length + '/5';
}

function addImageFiles(files, sourceLabel) {
    const selected = getSelectedItem();
    const canUseDirectFiles = chatState.mode === 'direct' && !!selected;
    const canUseGroupFiles = chatState.mode === 'group' && !!selected && !selected.read_only && !!chatState.groupImagesEnabled;
    if (!canUseDirectFiles && !canUseGroupFiles) {
        return;
    }

    if (!Array.isArray(files) || files.length === 0) {
        return;
    }

    const slotsLeft = Math.max(0, 5 - selectedImageEntries.length);
    if (slotsLeft <= 0) {
        alert('Solo se permiten maximo 5 archivos.');
        return;
    }

    if (files.length > slotsLeft) {
        alert('Solo se permiten maximo 5 archivos.');
    }

    const oversizedFiles = files.filter((file) => Number(file.size || 0) > 2 * 1024 * 1024);
    if (oversizedFiles.length > 0) {
        alert('El archivo no puede exceder 2 MB.');
    }

    const invalidFiles = files.filter((file) => {
        const type = String(file.type || '').toLowerCase();
        return !(type.startsWith('image/') || type === 'application/pdf' || String(file.name || '').toLowerCase().endsWith('.pdf'));
    });
    if (invalidFiles.length > 0) {
        alert('Solo se permiten imagenes o archivos PDF.');
    }

    files
        .filter((file) => {
            const type = String(file.type || '').toLowerCase();
            const allowed = type.startsWith('image/') || type === 'application/pdf' || String(file.name || '').toLowerCase().endsWith('.pdf');
            return allowed && Number(file.size || 0) <= 2 * 1024 * 1024;
        })
        .slice(0, slotsLeft)
        .forEach((file) => {
        const isPdf = isPdfFile(file);
        selectedImageEntries.push({
            id: Date.now().toString(36) + Math.random().toString(36).slice(2),
            file,
            sourceLabel,
            previewUrl: isPdf ? '' : URL.createObjectURL(file),
            isPdf,
        });
        });

    renderImagePreview();
    syncImageHint();
}

function removeImageEntry(entryId) {
    const nextEntries = [];
    selectedImageEntries.forEach((entry) => {
        if (entry.id === entryId) {
            if (entry.previewUrl) {
                URL.revokeObjectURL(entry.previewUrl);
            }
            return;
        }
        nextEntries.push(entry);
    });
    selectedImageEntries = nextEntries;
    renderImagePreview();
    syncImageHint();
}

function getActiveItems() {
    return chatState.mode === 'group' ? chatState.groupRooms : chatState.directContacts;
}

function getFilteredItems() {
    const items = getActiveItems();
    if (!Array.isArray(items) || chatSearchTerm.trim() === '') {
        return Array.isArray(items) ? items : [];
    }

    const term = chatSearchTerm.trim().toLowerCase();
    return items.filter((item) => {
        const haystack = [
            item.name || '',
            item.role || '',
            item.city || '',
            item.last_message || '',
            item.last_sender_name || '',
            item.tl_name || '',
        ].join(' ').toLowerCase();
        return haystack.includes(term);
    });
}

function renderReactionBlocks(message, replyActionHtml = '') {
    const reactionType = String(message.reaction_type || '');
    const reactionMessageId = Number(message.reaction_message_id || 0);
    const reactionKey = reactionType + ':' + reactionMessageId;
    const reactions = Array.isArray(message.reactions) ? message.reactions : [];

    const reactionsHtml = reactions.length > 0
        ? `<div class="chat-reactions">${reactions.map((reaction) => `<button type="button" class="chat-reaction-chip${reaction.mine ? ' mine' : ''}${reaction.emoji === '🦍' ? ' gorilla' : ''}${reaction.emoji === '🤌' ? ' italian-hand' : ''}" title="${escapeHtml(reaction.reacted_by || '')}" data-react-message-type="${escapeHtml(reactionType)}" data-react-message-id="${escapeHtml(String(reactionMessageId))}" data-react-emoji="${escapeHtml(reaction.emoji || '')}"><span>${escapeHtml(reaction.emoji || '')}</span><span>${Number(reaction.count || 0)}</span></button>`).join('')}</div>`
        : '';

    if (message.deleted) {
        return reactionsHtml;
    }

    const pickerHidden = openReactionPickerKey === reactionKey ? '' : ' hidden';
    const pickerHtml = `<div class="chat-reaction-trigger-wrap${message.mine ? ' mine' : ''}">
        <button type="button" class="chat-reaction-trigger" title="${escapeHtml(chatState.reactText)}" data-reaction-toggle="${escapeHtml(reactionKey)}">😊</button>
        <div class="chat-reaction-picker"${pickerHidden} data-reaction-picker="${escapeHtml(reactionKey)}">
            ${chatState.reactionsCatalog.map((emoji) => `<button type="button" class="chat-reaction-option${emoji === '🦍' ? ' gorilla' : ''}${emoji === '🤌' ? ' italian-hand' : ''}" data-react-message-type="${escapeHtml(reactionType)}" data-react-message-id="${escapeHtml(String(reactionMessageId))}" data-react-emoji="${escapeHtml(emoji)}">${escapeHtml(emoji)}</button>`).join('')}
        </div>
    </div>`;

    const actionsHtml = `<div class="chat-message-actions${message.mine ? ' mine' : ''}">${pickerHtml}${replyActionHtml}</div>`;
    return reactionsHtml + actionsHtml;
}

function normalizeReplyExcerpt(type, excerpt) {
    if (type === 'imagen') {
        const normalized = String(excerpt || '');
        const lower = normalized.toLowerCase();
        if (lower.endsWith('.pdf')) {
            return 'Respondio a un PDF: ' + (normalized || 'Documento PDF');
        }
        return 'Respondio a una imagen: ' + (normalized || 'Imagen temporal');
    }
    return excerpt || '';
}

function updateImageZoomUI() {
    if (chatImageModalImg) {
        chatImageModalImg.style.transform = 'scale(' + chatImageZoomLevel.toFixed(2) + ')';
        chatImageModalImg.style.cursor = chatImageZoomLevel > 1 ? 'grab' : 'zoom-in';
    }
    if (chatImageZoomLabel) {
        chatImageZoomLabel.textContent = Math.round(chatImageZoomLevel * 100) + '%';
    }
}

function setImageZoom(level) {
    chatImageZoomLevel = Math.max(0.5, Math.min(4, Number(level || 1)));
    updateImageZoomUI();
}

function getMessageAnchorId(message) {
    const kind = String(message.kind || 'text') === 'image' ? 'image' : 'text';
    const targetId = kind === 'image'
        ? Number(message.image_id || message.id || 0)
        : Number(message.id || 0);
    return 'chat-message-' + kind + '-' + targetId;
}

function getSelectedItem() {
    return chatState.mode === 'group' ? chatState.selectedGroup : chatState.selectedContact;
}

function getActiveMessages() {
    return chatState.mode === 'group' ? chatState.groupMessages : chatState.directMessages;
}

function updateRoute() {
    if (chatState.isEmbed) {
        return;
    }
    const params = new URLSearchParams();
    params.set('mode', chatState.mode);
    if (chatState.mode === 'group' && chatState.selectedGroupId) {
        params.set('group', String(chatState.selectedGroupId));
    }
    if (chatState.mode === 'direct' && chatState.selectedDirectId) {
        params.set('with', String(chatState.selectedDirectId));
    }
    const target = chatState.routeBase + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', target);
}

function renderModeTabs() {
    modeTabs.querySelectorAll('[data-mode]').forEach((button) => {
        button.classList.toggle('active', button.getAttribute('data-mode') === chatState.mode);
    });

    const directButton = modeTabs.querySelector('[data-mode="direct"]');
    const groupButton = modeTabs.querySelector('[data-mode="group"]');
    if (directButton) {
        directButton.classList.toggle('has-unread', chatState.directUnreadConversations > 0);
        directButton.innerHTML = `${chatState.directUnreadConversations > 0 ? '<span class="chat-mode-dot"></span>' : ''}${escapeHtml('<?= addslashes(t('chat.direct')) ?>')}${chatState.directUnreadConversations > 0 ? ' <span class="chat-mode-badge">' + chatState.directUnreadConversations + '</span>' : ''}`;
    }
    if (groupButton) {
        groupButton.classList.toggle('has-unread', chatState.groupUnreadConversations > 0);
        groupButton.innerHTML = `${chatState.groupUnreadConversations > 0 ? '<span class="chat-mode-dot"></span>' : ''}${escapeHtml('<?= addslashes(t('chat.groups')) ?>')}${chatState.groupUnreadConversations > 0 ? ' <span class="chat-mode-badge">' + chatState.groupUnreadConversations + '</span>' : ''}`;
    }
}

function renderList() {
    const baseItems = getActiveItems();
    if (!Array.isArray(baseItems) || baseItems.length === 0) {
        contactList.innerHTML = '<div class="chat-empty">' + escapeHtml(chatState.mode === 'group' ? (chatState.groupsEnabled ? chatState.noGroupsText : chatState.groupDisabledText) : chatState.noContactsText) + '</div>';
        return;
    }

    const items = getFilteredItems();
    if (items.length === 0) {
        contactList.innerHTML = '<div class="chat-empty">' + escapeHtml(chatState.noMatchesText) + '</div>';
        return;
    }

    const selectedId = chatState.mode === 'group' ? Number(chatState.selectedGroupId || 0) : Number(chatState.selectedDirectId || 0);
    contactList.innerHTML = items.map((item) => {
        const activeClass = Number(item.id) === selectedId ? ' active' : '';
        const unread = Number(item.unread_count || 0);
        const preview = chatState.mode === 'group' && item.last_sender_name
            ? item.last_sender_name + ': ' + (item.last_message || '')
            : (item.last_message || '');

        return `
            <div class="chat-contact${activeClass}" data-item-id="${item.id}">
                <div class="chat-contact-head">
                    <div class="chat-contact-name">${escapeHtml(item.name)}</div>
                    ${unread > 0 ? `<span class="chat-badge" title="${escapeHtml(chatState.unreadText)}">${unread}</span>` : ''}
                </div>
                <div class="chat-contact-meta">
                    <div class="chat-contact-role">${escapeHtml(item.role)}${item.city ? ' · ' + escapeHtml(item.city) : ''}</div>
                    <div class="chat-contact-time">${escapeHtml(item.last_message_at || '')}</div>
                </div>
                <div class="chat-contact-preview">${escapeHtml(preview)}</div>
            </div>
        `;
    }).join('');
}

function renderHeader() {
    const selected = getSelectedItem();
    chatHeaderKicker.textContent = chatState.mode === 'group' ? chatState.groupsLabel : chatState.directLabel;

    if (!selected) {
        chatHeaderName.textContent = chatState.mode === 'group' ? chatState.groupsLabel : chatState.startText;
        chatHeaderMeta.textContent = chatState.mode === 'group' && !chatState.groupsEnabled ? chatState.groupDisabledText : chatState.startText;
        return;
    }

    chatHeaderName.textContent = selected.name || '';
    if (chatState.mode === 'group') {
        const metaParts = [];
        if (selected.tl_name) {
            metaParts.push('TL: ' + selected.tl_name);
        }
        if (selected.city) {
            metaParts.push(selected.city);
        }
        if (selected.read_only) {
            metaParts.push(chatState.groupReadOnlyText);
        }
        chatHeaderMeta.textContent = metaParts.join(' · ');
        return;
    }

    chatHeaderMeta.textContent = [selected.role || '', selected.city || ''].filter(Boolean).join(' · ');
}

function renderMessages() {
    const selected = getSelectedItem();
    const messages = getActiveMessages();
    const selectedContextId = chatState.mode === 'group'
        ? Number(chatState.selectedGroupId || 0)
        : Number(chatState.selectedDirectId || 0);
    const threadContextKey = chatState.mode + ':' + selectedContextId;
    const previousScrollTop = chatThread.scrollTop;
    const wasNearBottom = isThreadNearBottom();

    if (!selected) {
        chatThread.innerHTML = '<div class="chat-empty">' + escapeHtml(chatState.mode === 'group' ? (chatState.groupsEnabled ? chatState.noGroupsText : chatState.groupDisabledText) : chatState.startText) + '</div>';
        lastThreadContextKey = threadContextKey;
        lastThreadMessageCount = 0;
        lastThreadLatestKey = '';
        lastThreadRenderSignature = '';
        renderedThreadMessages = [];
        clearPendingThreadMessages();
        forceScrollToBottom = false;
        return;
    }

    if (!Array.isArray(messages) || messages.length === 0) {
        chatThread.innerHTML = '<div class="chat-empty">' + escapeHtml(chatState.emptyText) + '</div>';
        lastThreadContextKey = threadContextKey;
        lastThreadMessageCount = 0;
        lastThreadLatestKey = '';
        lastThreadRenderSignature = '';
        renderedThreadMessages = [];
        clearPendingThreadMessages();
        forceScrollToBottom = false;
        return;
    }

    const contextChanged = threadContextKey !== lastThreadContextKey;
    const latestMessage = messages[messages.length - 1] || {};
    const latestMessageKey = [
        latestMessage.reaction_type || latestMessage.kind || 'text',
        latestMessage.reaction_message_id || latestMessage.id || 0,
        latestMessage.enviado_en || '',
    ].join(':');
    const hasNewMessage = !contextChanged && (
        messages.length > lastThreadMessageCount ||
        latestMessageKey !== lastThreadLatestKey
    );
    const renderSignature = buildThreadRenderSignature(messages);

    if (contextChanged) {
        clearPendingThreadMessages();
    } else if (hasNewMessage && !forceScrollToBottom && !wasNearBottom) {
        pendingThreadMessages = messages.slice();
        pendingIncomingCount = countIncomingMessages(messages);
        renderPendingIndicator();
        return;
    } else if (wasNearBottom && pendingThreadMessages) {
        clearPendingThreadMessages();
    }

    if (!contextChanged && !hasNewMessage && !forceScrollToBottom && !pendingThreadMessages && renderSignature === lastThreadRenderSignature) {
        return;
    }

    chatThread.innerHTML = messages.map((message) => {
        const mineClass = message.mine ? ' mine' : '';
        const senderName = message.mine
            ? chatState.currentUserName
            : (chatState.mode === 'group'
                ? (message.sender_name || selected.tl_name || selected.name || '')
                : (selected.name || ''));
        const replyAction = chatState.mode === 'direct' && !message.deleted
            ? `<button type="button" class="chat-reply-trigger" title="Responder" data-reply-kind="${escapeHtml(message.kind || 'text')}" data-reply-id="${escapeHtml(String(message.kind === 'image' ? (message.image_id || message.id || 0) : (message.id || 0)))}" data-reply-sender="${escapeHtml(senderName)}" data-reply-excerpt="${escapeHtml(message.kind === 'image' ? (message.image_name || message.mensaje || 'Imagen temporal') : (message.mensaje || ''))}">↩</button>`
            : '';
        const reactionsBlock = renderReactionBlocks(message, replyAction);
        const replyPreview = message.reply_preview || null;
        const replySenderName = replyPreview
            ? (replyPreview.sender_is_mine ? chatState.currentUserName : (replyPreview.sender_name || selected.name || ''))
            : '';
        const replyTargetId = replyPreview
            ? ('chat-message-' + (String(replyPreview.type || 'texto') === 'imagen' ? 'image' : 'text') + '-' + Number(replyPreview.id || 0))
            : '';
        const replyBlock = replyPreview
            ? `<button type="button" class="chat-reply-quote is-link${message.mine ? ' mine' : ''}" data-scroll-to-message="${escapeHtml(replyTargetId)}">
                    <div class="chat-reply-quote-label">${escapeHtml(replySenderName)}</div>
                    <div class="chat-reply-quote-text">${escapeHtml(normalizeReplyExcerpt(replyPreview.type || 'texto', replyPreview.excerpt || ''))}</div>
               </button>`
            : '';
        const messageAnchorId = getMessageAnchorId(message);
        const contextAttrs = chatState.mode === 'direct' && message.mine && message.kind !== 'image' && !message.deleted
            ? ` data-context-message-id="${escapeHtml(String(message.id || 0))}"`
            : '';
        const metaParts = [escapeHtml(message.enviado_en || '')];
        if (message.edited) {
            metaParts.push('editado');
        }
        if (message.mine && message.estado === 'leido') {
            metaParts.push('leido');
        }
        const metaHtml = metaParts.join(' · ');

        if (message.kind === 'image') {
            const imageUrl = chatState.imageViewUrl + '?id=' + encodeURIComponent(message.image_id);
            const imageDownloadUrl = imageUrl + '&download=1';
            if (message.is_pdf) {
                return `
                    <div class="chat-message-wrap${mineClass}" id="${escapeHtml(messageAnchorId)}"${contextAttrs}>
                        <div class="chat-message-sender">${escapeHtml(senderName)}</div>
                        <div class="chat-message${mineClass}">
                            ${replyBlock}
                            <div class="chat-image-card">
                                <div class="chat-image-card-preview">
                                    <div class="chat-file-thumb">PDF</div>
                                    <div>
                                        <div class="chat-image-name">${escapeHtml(message.image_name || message.mensaje || 'Documento PDF')}</div>
                                        <div class="chat-image-note">Haz clic en el boton para abrir o descargar el PDF.</div>
                                    </div>
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <a class="chat-image-button" href="${imageUrl}" target="_blank" rel="noopener">Ver PDF</a>
                                    <a class="chat-image-button" href="${imageDownloadUrl}">Descargar</a>
                                </div>
                            </div>
                            <div class="chat-message-meta">${metaHtml}</div>
                            ${reactionsBlock}
                        </div>
                    </div>
                `;
            }
            return `
                <div class="chat-message-wrap${mineClass}" id="${escapeHtml(messageAnchorId)}"${contextAttrs}>
                    <div class="chat-message-sender">${escapeHtml(senderName)}</div>
                    <div class="chat-message${mineClass}">
                        ${replyBlock}
                        <div class="chat-image-card">
                            <div class="chat-image-card-preview">
                                <img
                                    class="chat-image-thumb"
                                    src="${imageUrl}"
                                    alt="${escapeHtml(message.image_name || message.mensaje || 'Imagen temporal')}"
                                    data-image-url="${imageUrl}"
                                    data-image-name="${escapeHtml(message.image_name || message.mensaje || 'Imagen temporal')}"
                                >
                                <div class="chat-image-meta">
                                    <div class="chat-image-name">${escapeHtml(message.image_name || message.mensaje || 'Imagen temporal')}</div>
                                    <div class="chat-image-note">Haz clic en la miniatura para verla en grande.</div>
                                </div>
                            </div>
                        </div>
                        <div class="chat-message-meta">${metaHtml}</div>
                        ${reactionsBlock}
                    </div>
                </div>
            `;
        }

        return `
            <div class="chat-message-wrap${mineClass}" id="${escapeHtml(messageAnchorId)}"${contextAttrs}>
                <div class="chat-message-sender">${escapeHtml(senderName)}</div>
                <div class="chat-message${mineClass}">
                    ${replyBlock}
                    <div class="chat-message-body${message.deleted ? ' deleted' : ''}">${message.deleted ? escapeHtmlWithBreaks(message.mensaje || '') : linkifyMessageText(message.mensaje || '')}</div>
                    <div class="chat-message-meta">${metaHtml}</div>
                    ${reactionsBlock}
                </div>
            </div>
        `;
    }).join('');

    if (forceScrollToBottom || contextChanged || hasNewMessage) {
        chatThread.scrollTop = chatThread.scrollHeight;
    } else {
        chatThread.scrollTop = previousScrollTop;
    }

    lastThreadContextKey = threadContextKey;
    lastThreadMessageCount = messages.length;
    lastThreadLatestKey = latestMessageKey;
    lastThreadRenderSignature = renderSignature;
    renderedThreadMessages = messages.slice();
    clearPendingThreadMessages();
    forceScrollToBottom = false;
}

function renderComposer() {
    const selected = getSelectedItem();
    const directMode = chatState.mode === 'direct';
    const canSendDirect = directMode && !!selected;
    const canSendGroup = !directMode && !!selected && !selected.read_only;
    const canSend = canSendDirect || canSendGroup;

    chatMessageInput.disabled = !canSend;
    chatSubmitButton.disabled = !canSend;

    if (!selected) {
        chatComposeNote.textContent = chatState.mode === 'group'
            ? (chatState.groupsEnabled ? chatState.noGroupsText : chatState.groupDisabledText)
            : chatState.startText;
        chatState.pendingReply = null;
        chatState.pendingEdit = null;
    } else if (chatState.mode === 'group' && selected.read_only) {
        chatComposeNote.textContent = chatState.groupReadOnlyText;
        chatState.pendingReply = null;
        chatState.pendingEdit = null;
    } else if (chatState.mode === 'group') {
        chatComposeNote.textContent = chatState.groupWriteHelp;
        chatState.pendingReply = null;
        chatState.pendingEdit = null;
    } else {
        chatComposeNote.textContent = '';
    }

    if (chatReplyBanner && chatReplyBannerName && chatReplyBannerText) {
        if (chatState.mode === 'direct' && chatState.pendingReply) {
            chatReplyBanner.hidden = false;
            chatReplyBannerName.textContent = chatState.pendingReply.sender_name || '';
            chatReplyBannerText.textContent = normalizeReplyExcerpt(chatState.pendingReply.type || 'texto', chatState.pendingReply.excerpt || '');
        } else {
            chatReplyBanner.hidden = true;
            chatReplyBannerName.textContent = '';
            chatReplyBannerText.textContent = '';
        }
    }

    if (chatEditBanner && chatEditBannerText) {
        if (chatState.mode === 'direct' && chatState.pendingEdit) {
            chatEditBanner.hidden = false;
            chatEditBannerText.textContent = chatState.pendingEdit.message || '';
            chatSubmitButton.textContent = 'Guardar';
        } else {
            chatEditBanner.hidden = true;
            chatEditBannerText.textContent = '';
            chatSubmitButton.textContent = 'Enviar';
        }
    }

    if (chatState.imagesEnabled && chatComposeExtras) {
        const showImages = (!!selected && directMode) || (!!selected && chatState.mode === 'group' && !selected.read_only && !!chatState.groupImagesEnabled);
        chatComposeExtras.hidden = !showImages;
        if (chatImageTrigger) {
            chatImageTrigger.hidden = !showImages;
        }
        if (!showImages) {
            clearImagePreview();
            syncImageHint();
        }
    }
}

function renderAuxActions() {
    if (!chatClearButton) {
        return;
    }

    chatClearButton.disabled = chatState.mode !== 'direct';
    chatClearButton.title = chatState.mode === 'direct'
        ? 'Marca como leidas todas las conversaciones directas'
        : 'Solo aplica para conversaciones directas';
}

function getOwnEditableMessage(messageId) {
    const messages = Array.isArray(chatState.directMessages) ? chatState.directMessages : [];
    return messages.find((message) => Number(message.id || 0) === Number(messageId) && message.mine && String(message.kind || 'text') === 'text' && !message.deleted) || null;
}

function hideChatContextMenu() {
    if (!chatContextMenu) {
        return;
    }
    chatContextMenu.hidden = true;
    contextMenuMessageId = 0;
}

function showChatContextMenu(messageId, clientX, clientY) {
    if (!chatContextMenu) {
        return;
    }
    contextMenuMessageId = Number(messageId || 0);
    chatContextMenu.hidden = false;
    chatContextMenu.style.left = '0px';
    chatContextMenu.style.top = '0px';

    const menuWidth = chatContextMenu.offsetWidth || 170;
    const menuHeight = chatContextMenu.offsetHeight || 96;
    const maxLeft = Math.max(8, window.innerWidth - menuWidth - 8);
    const maxTop = Math.max(8, window.innerHeight - menuHeight - 8);
    const left = Math.min(clientX, maxLeft);
    const top = Math.min(clientY, maxTop);

    chatContextMenu.style.left = left + 'px';
    chatContextMenu.style.top = top + 'px';
}

async function handleOwnMessageAction(action, messageId, messageText = '') {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('message_id', String(messageId));
    if (action === 'edit') {
        formData.append('message', messageText);
    }

    const response = await fetch(chatState.messageActionUrl, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No fue posible procesar la accion');
    }
    return data;
}

function renderAll() {
    renderModeTabs();
    renderList();
    renderHeader();
    renderMessages();
    renderComposer();
    renderAuxActions();
    updateRoute();
}

function isChatDocumentVisible() {
    return document.visibilityState === 'visible';
}

function isDirectReadAllowed() {
    return chatState.mode === 'direct' && isChatDocumentVisible();
}

async function refreshDirect() {
    const url = new URL(chatState.pollUrlBase, chatState.appOrigin);
    if (chatState.selectedDirectId) {
        url.searchParams.set('with', String(chatState.selectedDirectId));
    }
    if (!isDirectReadAllowed()) {
        url.searchParams.set('mark_read', '0');
    }
    url.searchParams.set('_ts', String(Date.now()));

    const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No fue posible actualizar el chat');
    }

    chatState.directContacts = Array.isArray(data.contacts) ? data.contacts : [];
    chatState.selectedContact = data.selected_contact || null;
    chatState.directMessages = Array.isArray(data.messages) ? data.messages : [];
    chatState.directUnreadConversations = chatState.directContacts.reduce((carry, contact) => carry + (Number(contact.unread_count || 0) > 0 ? 1 : 0), 0);
}

async function refreshGroup(markAll = false) {
    const url = new URL(chatState.groupPollUrlBase, chatState.appOrigin);
    if (chatState.selectedGroupId) {
        url.searchParams.set('group', String(chatState.selectedGroupId));
    }
    if (markAll) {
        url.searchParams.set('mark_all', '1');
    }
    url.searchParams.set('_ts', String(Date.now()));

    const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No fue posible actualizar el chat grupal');
    }

    chatState.groupRooms = Array.isArray(data.group_rooms) ? data.group_rooms : [];
    chatState.selectedGroup = data.selected_group || null;
    chatState.groupMessages = Array.isArray(data.messages) ? data.messages : [];
    chatState.groupUnreadConversations = chatState.groupRooms.reduce((carry, room) => carry + (Number(room.unread_count || 0) > 0 ? 1 : 0), 0);
}

async function refreshUnreadModeCounts() {
    if (chatUnreadRefreshInFlight) {
        return;
    }
    chatUnreadRefreshInFlight = true;
    try {
        const url = new URL(chatState.notificationsUrl, chatState.appOrigin);
        url.searchParams.set('_ts', String(Date.now()));
        const response = await fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok || !data.summary) {
            return;
        }

        chatState.directUnreadConversations = Number(data.summary.direct_unread_conversations || 0);
        chatState.groupUnreadConversations = Number(data.summary.group_unread_conversations || 0);
    } catch (error) {
        console.log('Chat mode counts error', error);
    } finally {
        chatUnreadRefreshInFlight = false;
    }
}

async function refreshCurrentMode() {
    if (chatModeRefreshInFlight) {
        chatModeRefreshQueued = true;
        return;
    }
    chatModeRefreshInFlight = true;
    try {
        if (chatState.mode === 'group') {
            await refreshGroup();
        } else {
            await refreshDirect();
        }
        await refreshUnreadModeCounts();
        renderAll();
    } finally {
        chatModeRefreshInFlight = false;
        if (chatModeRefreshQueued) {
            chatModeRefreshQueued = false;
            refreshCurrentMode();
        }
    }
}

function updateMessageReactions(messageType, messageId, reactions) {
    const messages = getActiveMessages();
    const targetType = String(messageType || '');
    const targetId = Number(messageId || 0);
    messages.forEach((message) => {
        if (String(message.reaction_type || '') === targetType && Number(message.reaction_message_id || 0) === targetId) {
            message.reactions = Array.isArray(reactions) ? reactions : [];
        }
    });
}

async function toggleReaction(messageType, messageId, emoji) {
    const formData = new FormData();
    formData.append('message_type', messageType);
    formData.append('message_id', String(messageId));
    formData.append('emoji', emoji);

    const response = await fetch(<?= json_encode($absoluteReactUrl) ?>, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No fue posible reaccionar');
    }

    updateMessageReactions(messageType, messageId, data.reactions || []);
    openReactionPickerKey = '';
    renderMessages();
}

function findDirectContact(contactId) {
    return chatState.directContacts.find((contact) => Number(contact.id) === Number(contactId)) || null;
}

function findGroupRoom(groupId) {
    return chatState.groupRooms.find((room) => Number(room.id) === Number(groupId)) || null;
}

async function switchMode(nextMode) {
    if (nextMode !== 'direct' && nextMode !== 'group') {
        return;
    }

    if (nextMode === 'group' && !chatState.groupsEnabled) {
        chatState.mode = 'group';
        renderAll();
        return;
    }

    chatState.mode = nextMode;
    clearPendingThreadMessages();
    if (nextMode === 'direct' && !chatState.selectedContact && chatState.directContacts.length > 0) {
        chatState.selectedContact = chatState.directContacts[0];
        chatState.selectedDirectId = Number(chatState.selectedContact.id);
    }
    if (nextMode === 'group' && !chatState.selectedGroup && chatState.groupRooms.length > 0) {
        chatState.selectedGroup = chatState.groupRooms[0];
        chatState.selectedGroupId = Number(chatState.selectedGroup.id);
    }

    if (nextMode === 'group') {
        await refreshGroup(true);
        await refreshUnreadModeCounts();
        renderAll();
        return;
    }

    await refreshCurrentMode();
}

async function selectListItem(itemId) {
    chatState.pendingReply = null;
    chatState.pendingEdit = null;
    hideChatContextMenu();
    clearPendingThreadMessages();
    if (chatState.mode === 'group') {
        chatState.selectedGroupId = Number(itemId);
        chatState.selectedGroup = findGroupRoom(itemId);
    } else {
        chatState.selectedDirectId = Number(itemId);
        chatState.selectedContact = findDirectContact(itemId);
    }
    renderAll();
    await refreshCurrentMode();
}

chatForm.addEventListener('submit', async function (event) {
    event.preventDefault();

    const message = chatMessageInput.value.trim();
    const isGroupMode = chatState.mode === 'group';
    const selected = getSelectedItem();

    if (!selected) {
        return;
    }

    if (!isGroupMode && message === '' && selectedImageEntries.length === 0) {
        return;
    }

    if (isGroupMode && message === '' && selectedImageEntries.length === 0) {
        return;
    }

    if (isGroupMode && selected.read_only) {
        alert(chatState.groupReadOnlyText);
        return;
    }

    chatSubmitButton.disabled = true;

    try {
        let response;
        let data;
        if (!isGroupMode && chatState.pendingEdit && Number(chatState.pendingEdit.id || 0) > 0) {
            data = await handleOwnMessageAction('edit', Number(chatState.pendingEdit.id || 0), message);
        } else if (isGroupMode) {
            const formData = new FormData();
            formData.append('group', String(chatState.selectedGroupId));
            formData.append('message', message);
            selectedImageEntries.forEach((entry) => {
                formData.append('image[]', entry.file, entry.file.name);
            });
            response = await fetch(chatState.groupSendUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } else {
            const formData = new FormData();
            formData.append('with', String(chatState.selectedDirectId));
            formData.append('message', message);
            if (chatState.pendingReply && chatState.pendingReply.id > 0) {
                formData.append('reply_type', String(chatState.pendingReply.type || 'texto'));
                formData.append('reply_id', String(chatState.pendingReply.id));
            }
            selectedImageEntries.forEach((entry) => {
                formData.append('image[]', entry.file, entry.file.name);
            });
            response = await fetch(chatState.sendUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        }

        if (!data && response) {
            data = await response.json();
        }

        if ((response && !response.ok) || !data || !data.ok) {
            const detailedMessage = [data.message || 'No fue posible enviar el mensaje', data.reason || '']
                .filter(Boolean)
                .join(' ');
            throw new Error(detailedMessage);
        }

        chatMessageInput.value = '';
        clearImagePreview();
        syncImageHint();
        if (chatImageInput) {
            chatImageInput.value = '';
        }
        chatState.pendingReply = null;
        chatState.pendingEdit = null;
        hideChatContextMenu();
        forceScrollToBottom = true;

        await refreshCurrentMode();
    } catch (error) {
        alert(error.message || 'No fue posible enviar el mensaje.');
    } finally {
        chatSubmitButton.disabled = false;
    }
});

modeTabs.addEventListener('click', function (event) {
    const button = event.target.closest('[data-mode]');
    if (!button) {
        return;
    }

    switchMode(button.getAttribute('data-mode'));
});

if (chatSearchInput) {
    chatSearchInput.addEventListener('input', function () {
        chatSearchTerm = this.value || '';
        renderList();
    });
}

contactList.addEventListener('click', function (event) {
    const item = event.target.closest('[data-item-id]');
    if (!item) {
        return;
    }

    selectListItem(item.getAttribute('data-item-id'));
});

chatThread.addEventListener('click', async function (event) {
    const replyJump = event.target.closest('[data-scroll-to-message]');
    if (replyJump) {
        const targetId = replyJump.getAttribute('data-scroll-to-message') || '';
        const targetMessage = targetId ? document.getElementById(targetId) : null;
        if (targetMessage) {
            targetMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetMessage.classList.add('jump-highlight');
            window.setTimeout(() => targetMessage.classList.remove('jump-highlight'), 1800);
        }
        return;
    }

    const replyButton = event.target.closest('[data-reply-id]');
    if (replyButton) {
        if (!chatState.repliesEnabled) {
            alert(chatState.repliesDisabledText);
            return;
        }
        const replyKind = replyButton.getAttribute('data-reply-kind') || 'text';
        const replyId = Number(replyButton.getAttribute('data-reply-id') || 0);
        const replySender = replyButton.getAttribute('data-reply-sender') || '';
        const replyExcerpt = replyButton.getAttribute('data-reply-excerpt') || '';
        if (replyId > 0 && chatState.mode === 'direct') {
            chatState.pendingReply = {
                type: replyKind === 'image' ? 'imagen' : 'texto',
                id: replyId,
                sender_name: replySender,
                excerpt: replyExcerpt,
            };
            renderComposer();
            chatMessageInput.focus();
        }
        return;
    }

    const toggle = event.target.closest('[data-reaction-toggle]');
    if (toggle) {
        const nextKey = toggle.getAttribute('data-reaction-toggle') || '';
        openReactionPickerKey = openReactionPickerKey === nextKey ? '' : nextKey;
        renderMessages();
        return;
    }

    const reactButton = event.target.closest('[data-react-emoji]');
    if (!reactButton) {
        return;
    }

    const messageType = reactButton.getAttribute('data-react-message-type') || '';
    const messageId = Number(reactButton.getAttribute('data-react-message-id') || 0);
    const emoji = reactButton.getAttribute('data-react-emoji') || '';
    if (!messageType || !messageId || !emoji) {
        return;
    }

    try {
        await toggleReaction(messageType, messageId, emoji);
    } catch (error) {
        alert(error.message || 'No fue posible reaccionar.');
    }
});

chatThread.addEventListener('contextmenu', function (event) {
    const messageWrap = event.target.closest('[data-context-message-id]');
    if (!messageWrap || chatState.mode !== 'direct') {
        hideChatContextMenu();
        return;
    }

    const messageId = Number(messageWrap.getAttribute('data-context-message-id') || 0);
    const message = getOwnEditableMessage(messageId);
    if (!message) {
        hideChatContextMenu();
        return;
    }

    event.preventDefault();
    showChatContextMenu(messageId, event.clientX, event.clientY);
});

if (chatReplyBannerClose) {
    chatReplyBannerClose.addEventListener('click', function () {
        chatState.pendingReply = null;
        renderComposer();
    });
}

if (chatEditBannerClose) {
    chatEditBannerClose.addEventListener('click', function () {
        chatState.pendingEdit = null;
        chatMessageInput.value = '';
        renderComposer();
    });
}

if (chatClearButton) {
    chatClearButton.addEventListener('click', async function () {
        if (chatState.mode !== 'direct') {
            return;
        }

        chatClearButton.disabled = true;
        try {
            const response = await fetch(chatState.markAllReadUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'No fue posible limpiar el chat');
            }

            await refreshDirect();
            await refreshUnreadModeCounts();
        } catch (error) {
            window.alert(error.message || 'No fue posible limpiar el chat');
        } finally {
            renderAuxActions();
        }
    });
}

if (chatPendingIndicator) {
    chatPendingIndicator.addEventListener('click', function () {
        applyPendingThreadMessages();
    });
}

chatThread.addEventListener('scroll', function () {
    if (pendingThreadMessages && isThreadNearBottom()) {
        applyPendingThreadMessages();
    }
});

if (chatContextMenu) {
    chatContextMenu.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-chat-context-action]');
        if (!button || contextMenuMessageId <= 0) {
            return;
        }

        const action = button.getAttribute('data-chat-context-action') || '';
        const message = getOwnEditableMessage(contextMenuMessageId);
        hideChatContextMenu();

        if (!message) {
            return;
        }

        if (action === 'edit') {
            chatState.pendingReply = null;
            chatState.pendingEdit = {
                id: Number(message.id || 0),
                message: String(message.mensaje || ''),
            };
            chatMessageInput.value = String(message.mensaje || '');
            clearImagePreview();
            syncImageHint();
            if (chatImageInput) {
                chatImageInput.value = '';
            }
            renderComposer();
            chatMessageInput.focus();
            return;
        }

        if (action === 'delete') {
            const confirmed = window.confirm('Se eliminara este mensaje. Deseas continuar?');
            if (!confirmed) {
                return;
            }
            try {
                await handleOwnMessageAction('delete', Number(message.id || 0));
                if (chatState.pendingEdit && Number(chatState.pendingEdit.id || 0) === Number(message.id || 0)) {
                    chatState.pendingEdit = null;
                    chatMessageInput.value = '';
                }
                await refreshCurrentMode();
            } catch (error) {
                alert(error.message || 'No fue posible eliminar el mensaje.');
            }
        }
    });
}

document.addEventListener('click', function (event) {
    if (!openReactionPickerKey) {
        if (chatContextMenu && !chatContextMenu.hidden && !event.target.closest('#chatContextMenu')) {
            hideChatContextMenu();
        }
        return;
    }

    if (event.target.closest('.chat-reaction-trigger-wrap')) {
        return;
    }

    openReactionPickerKey = '';
    renderMessages();
    if (chatContextMenu && !chatContextMenu.hidden && !event.target.closest('#chatContextMenu')) {
        hideChatContextMenu();
    }
});

chatMessageInput.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    event.preventDefault();
    if (!chatSubmitButton.disabled) {
        chatForm.requestSubmit();
    }
});

if (chatState.imagesEnabled && chatImageInput) {
    chatImageInput.addEventListener('change', function () {
        const files = Array.from(chatImageInput.files || []).filter((file) => {
            if (!file) {
                return false;
            }
            const type = String(file.type || '').toLowerCase();
            const name = String(file.name || '').toLowerCase();
            return type.startsWith('image/') || type === 'application/pdf' || name.endsWith('.pdf');
        });
        addImageFiles(files, 'Adjunta');
        chatImageInput.value = '';
    });
}

if (chatState.imagesEnabled) {
    chatMessageInput.addEventListener('paste', function (event) {
        const selected = getSelectedItem();
        const allowDirectPaste = chatState.mode === 'direct' && !!selected;
        const allowGroupPaste = chatState.mode === 'group' && !!selected && !selected.read_only && !!chatState.groupImagesEnabled;
        if (!allowDirectPaste && !allowGroupPaste) {
            return;
        }

        const clipboardData = event.clipboardData || window.clipboardData || null;
        const plainText = clipboardData && typeof clipboardData.getData === 'function'
            ? String(clipboardData.getData('text/plain') || '')
            : '';
        const htmlText = clipboardData && typeof clipboardData.getData === 'function'
            ? String(clipboardData.getData('text/html') || '')
            : '';

        if (plainText.trim() !== '' || htmlText.trim() !== '') {
            return;
        }

        const clipboardItems = Array.from((event.clipboardData && event.clipboardData.items) || []);
        const imageFiles = clipboardItems
            .filter((item) => item && item.kind === 'file' && item.type && item.type.startsWith('image/'))
            .map((item) => item.getAsFile())
            .filter(Boolean);

        if (imageFiles.length === 0) {
            return;
        }

        event.preventDefault();
        addImageFiles(imageFiles, 'Pegada');
    });
}

if (chatImagePreview) {
    chatImagePreview.addEventListener('click', function (event) {
        const button = event.target.closest('[data-preview-id]');
        if (!button) {
            return;
        }
        removeImageEntry(button.getAttribute('data-preview-id'));
        syncImageHint();
    });
}

function openImageModal(imageUrl, imageName) {
    if (!chatImageModal || !chatImageModalImg || !chatImageModalTitle || !chatImageModalDownload) {
        return;
    }

    chatImageModalImg.src = imageUrl;
    chatImageModalImg.alt = imageName || 'Imagen temporal';
    chatImageModalTitle.textContent = imageName || 'Imagen temporal';
    chatImageModalDownload.href = imageUrl;
    chatImageModalDownload.setAttribute('download', imageName || 'imagen-chat');
    setImageZoom(1);
    chatImageModal.hidden = false;
}

chatThread.addEventListener('click', function (event) {
    const target = event.target.closest('[data-image-url]');
    if (!target) {
        return;
    }

    openImageModal(target.getAttribute('data-image-url'), target.getAttribute('data-image-name') || 'Imagen temporal');
});

if (chatImageModal && chatImageModalClose) {
    const closeImageModal = function () {
        chatImageModal.hidden = true;
        chatImageModalImg.src = '';
        setImageZoom(1);
    };

    chatImageModalClose.addEventListener('click', function () {
        closeImageModal();
    });

    chatImageModal.addEventListener('click', function (event) {
        if (event.target === chatImageModal) {
            closeImageModal();
        }
    });

    if (chatImageZoomIn) {
        chatImageZoomIn.addEventListener('click', function () {
            setImageZoom(chatImageZoomLevel + 0.25);
        });
    }

    if (chatImageZoomOut) {
        chatImageZoomOut.addEventListener('click', function () {
            setImageZoom(chatImageZoomLevel - 0.25);
        });
    }

    if (chatImageZoomReset) {
        chatImageZoomReset.addEventListener('click', function () {
            setImageZoom(1);
        });
    }

    if (chatImageModalImg) {
        chatImageModalImg.addEventListener('wheel', function (event) {
            if (chatImageModal.hidden) {
                return;
            }
            event.preventDefault();
            setImageZoom(chatImageZoomLevel + (event.deltaY < 0 ? 0.2 : -0.2));
        }, { passive: false });

        chatImageModalImg.addEventListener('dblclick', function () {
            setImageZoom(chatImageZoomLevel > 1 ? 1 : 2);
        });
    }
}

renderAll();
syncImageHint();
refreshUnreadModeCounts().then(renderModeTabs);
async function runChatRefreshLoop() {
    if (!isChatDocumentVisible()) {
        await refreshUnreadModeCounts();
        renderModeTabs();
        return;
    }
    await refreshCurrentMode();
}

function scheduleChatRefreshLoop() {
    window.setTimeout(async function () {
        await runChatRefreshLoop();
        scheduleChatRefreshLoop();
    }, 3000);
}

function scheduleChatUnreadLoop() {
    window.setTimeout(async function () {
        await refreshUnreadModeCounts();
        renderModeTabs();
        scheduleChatUnreadLoop();
    }, 3000);
}

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
        runChatRefreshLoop();
    }
});
window.addEventListener('focus', runChatRefreshLoop);
scheduleChatRefreshLoop();
scheduleChatUnreadLoop();
</script>
</body>
</html>
