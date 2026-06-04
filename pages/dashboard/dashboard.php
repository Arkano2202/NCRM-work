<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";

requireLogin();
requirePermission("dashboard");
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Dashboard - CRM</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
    <?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

    <div class="content">
        <h1>Bienvenido, <?= htmlspecialchars($_SESSION["nombre"] ?? "") ?></h1>

        <div class="cards">
            <div class="card">
                <h3>Leads</h3>
                <p>Gestion de clientes</p>
            </div>

            <div class="card">
                <h3>Citas</h3>
                <p>Agenda</p>
            </div>

            <div class="card">
                <h3>Actividad</h3>
                <p>Ultimos movimientos</p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
