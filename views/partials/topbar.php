<?php
require_once __DIR__ . "/../../core/theme.php";
require_once __DIR__ . "/../../core/i18n.php";
require_once __DIR__ . "/../../core/app.php";

$currentTheme = normalizeThemeName($_SESSION["color"] ?? "clasico");
$themeOptions = availableThemes();
$currentLanguage = normalizeLanguageCode($_SESSION["idioma"] ?? "ES");
$languageOptions = availableLanguages();
$settingsRedirect = rawurlencode($_SERVER["REQUEST_URI"] ?? routeUrl("leads"));
?>
<script>
document.documentElement.dataset.theme = <?= json_encode($currentTheme) ?>;
</script>

<div class="topbar">

    <div class="topbar-left">
        <h3><?= htmlspecialchars(t("topbar.workspace")) ?></h3>
        <p class="topbar-subtitle"><?= htmlspecialchars(t("topbar.subtitle")) ?></p>
    </div>

    <div class="topbar-right">
        <span class="user-name"><?= htmlspecialchars($_SESSION["nombre"] ?? "") ?></span>
        <div class="theme-switcher">
            <button type="button" class="theme-toggle" onclick="toggleThemeMenu()" aria-label="<?= htmlspecialchars(t("settings.title")) ?>" title="<?= htmlspecialchars(t("settings.title")) ?>">&#9881;</button>
            <div id="theme-menu" class="theme-menu">
                <strong><?= htmlspecialchars(t("settings.theme")) ?></strong>
                <?php foreach ($themeOptions as $themeValue => $themeLabel): ?>
                    <a
                        class="theme-option <?= $currentTheme === $themeValue ? 'active' : '' ?>"
                        href="<?= htmlspecialchars(appUrl('core/update_theme.php') . '?theme=' . urlencode($themeValue) . '&redirect=' . $settingsRedirect) ?>"
                    >
                        <?= htmlspecialchars(t("theme." . $themeValue)) ?>
                    </a>
                <?php endforeach; ?>

                <strong class="theme-menu-section"><?= htmlspecialchars(t("settings.language")) ?></strong>
                <?php foreach ($languageOptions as $languageValue => $languageLabel): ?>
                    <a
                        class="theme-option <?= $currentLanguage === $languageValue ? 'active' : '' ?>"
                        href="<?= htmlspecialchars(appUrl('core/update_language.php') . '?idioma=' . urlencode($languageValue) . '&redirect=' . $settingsRedirect) ?>"
                    >
                        <?= htmlspecialchars($languageLabel) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <small><?= htmlspecialchars(t("topbar.role")) ?> <?= (int) ($_SESSION["tipo"] ?? 0) ?> | <?= htmlspecialchars(t("topbar.ext")) ?> <?= htmlspecialchars($_SESSION["ext"] ?? "") ?></small>

        <a href="<?= htmlspecialchars(routeUrl('logout')) ?>" class="logout-btn"><?= htmlspecialchars(t("topbar.logout")) ?></a>
    </div>

</div>

<script>
function toggleThemeMenu() {
    const menu = document.getElementById("theme-menu");
    if (!menu) return;
    menu.classList.toggle("is-open");
}

document.addEventListener("click", function (event) {
    const menu = document.getElementById("theme-menu");
    const switcher = event.target.closest(".theme-switcher");
    if (!menu || switcher) return;
    menu.classList.remove("is-open");
});
</script>