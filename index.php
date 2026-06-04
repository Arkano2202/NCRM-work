<?php
require_once __DIR__ . "/core/session_config.php";
require_once __DIR__ . "/core/db.php";
require_once __DIR__ . "/core/theme.php";
require_once __DIR__ . "/core/i18n.php";
require_once __DIR__ . "/core/app.php";

$error = "";

if (!empty($_SESSION["user_id"])) {
    redirectToRoute("leads");
}

if (!isset($conn)) {
    die(t("login.error.db"));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario = trim($_POST["usuario"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($usuario !== "" && $password !== "") {
        $sql = "SELECT * FROM users WHERE Usuario = ? LIMIT 1";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("s", $usuario);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row["Contrasena"])) {
                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $row["id"];
                    $_SESSION["nombre"] = $row["Nombre"];
                    $_SESSION["usuario"] = $row["Usuario"];
                    $_SESSION["ext"] = $row["Ext"];
                    $_SESSION["tipo"] = $row["Tipo"];
                    $_SESSION["grupo_id"] = $row["grupo_id"];
                    $_SESSION["pertenece"] = $row["pertenece"];
                    $_SESSION["telefonia"] = (int) ($row["telefonia"] ?? 1);
                    $_SESSION["color"] = normalizeThemeName($row["color"] ?? "clasico");
                    $_SESSION["idioma"] = normalizeLanguageCode($row["idioma"] ?? "ES");
                    $_SESSION["login_time"] = time();
                    $_SESSION["phone"] = $row["phone"];
                    $_SESSION["mail"] = $row["mail"];

                    session_write_close();
                    redirectToRoute("leads");
                }

                $error = t("login.error.invalid_password");
            } else {
                $error = t("login.error.user_not_found");
            }

            $stmt->close();
        } else {
            $error = t("login.error.query");
        }
    } else {
        $error = t("login.error.required");
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("login.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/login.css')) ?>">
</head>
<body>

<div class="container">

    <div class="left-panel">
        <h1><?= htmlspecialchars(t("login.title")) ?></h1>
        <p><?= htmlspecialchars(t("login.tagline")) ?></p>
    </div>

    <div class="right-panel">
        <div class="login-box">
            <h2><?= htmlspecialchars(t("login.heading")) ?></h2>

            <?php if ($error !== ""): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <input type="text" name="usuario" required>
                    <label><?= htmlspecialchars(t("login.user")) ?></label>
                </div>

                <div class="input-group">
                    <input type="password" name="password" required>
                    <label><?= htmlspecialchars(t("login.password")) ?></label>
                </div>

                <button type="submit"><?= htmlspecialchars(t("login.submit")) ?></button>
            </form>
        </div>
    </div>

</div>

</body>
</html>
