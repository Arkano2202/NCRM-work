<?php
require_once __DIR__ . "/session_config.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/theme.php";

requireLogin();

$isJsonRequest = $_SERVER["REQUEST_METHOD"] === "POST";

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "GET") {
    if ($isJsonRequest) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(405);
        echo json_encode(["ok" => false, "error" => "Metodo no permitido"], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$payload = $_SERVER["REQUEST_METHOD"] === "POST"
    ? (json_decode(file_get_contents("php://input"), true) ?: [])
    : $_GET;

$theme = normalizeThemeName($payload["theme"] ?? "clasico");
$userId = (int) ($_SESSION["user_id"] ?? 0);
$redirect = trim((string) ($payload["redirect"] ?? routeUrl("leads")));

if ($redirect === "" || preg_match('/^(?:https?:)?\/\//i', $redirect)) {
    $redirect = routeUrl("leads");
}

if ($userId <= 0) {
    if ($isJsonRequest) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "Sesion no valida"], JSON_UNESCAPED_UNICODE);
    } else {
        header("Location: " . $redirect);
    }
    exit;
}

$stmt = $conn->prepare("UPDATE users SET color = ? WHERE id = ?");
if (!$stmt) {
    if ($isJsonRequest) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "No fue posible preparar la actualizacion"], JSON_UNESCAPED_UNICODE);
    } else {
        header("Location: " . $redirect);
    }
    exit;
}

$stmt->bind_param("si", $theme, $userId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    if ($isJsonRequest) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "No fue posible guardar el tema"], JSON_UNESCAPED_UNICODE);
    } else {
        header("Location: " . $redirect);
    }
    exit;
}

$_SESSION["color"] = $theme;

if ($isJsonRequest) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "ok" => true,
        "theme" => $theme,
        "label" => availableThemes()[$theme] ?? ucfirst($theme),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header("Location: " . $redirect);
exit;
