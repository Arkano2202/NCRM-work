<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/i18n.php";

requireLogin();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    exit;
}

$language = normalizeLanguageCode($_GET["idioma"] ?? "ES");
$userId = (int) ($_SESSION["user_id"] ?? 0);
$redirect = trim((string) ($_GET["redirect"] ?? routeUrl("leads")));

if ($redirect === "" || preg_match('/^(?:https?:)?\/\//i', $redirect)) {
    $redirect = routeUrl("leads");
}

if ($userId > 0) {
    $stmt = $conn->prepare("UPDATE users SET idioma = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $language, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$_SESSION["idioma"] = $language;

header("Location: " . $redirect);
exit;
