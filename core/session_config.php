<?php

date_default_timezone_set('America/Bogota');

$tiempo_vida = 60 * 60 * 24 * 30;
$usaHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
);

session_set_cookie_params([
    'lifetime' => $tiempo_vida,
    'path' => '/',
    'secure' => $usaHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

ini_set('session.gc_maxlifetime', (string) $tiempo_vida);

session_start();
