<?php
require_once __DIR__ . "/core/session_config.php";
require_once __DIR__ . "/core/app.php";

// Limpiar variables
$_SESSION = [];

// Destruir sesión
session_destroy();

// Eliminar cookie
setcookie(session_name(), '', time() - 3600, '/');

redirectToRoute("login");
