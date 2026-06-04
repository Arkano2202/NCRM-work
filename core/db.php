<?php
// =========================
// CONFIGURACIÓN DE ERRORES (OPCIONAL DEV)
// =========================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =========================
// RUTA DEL .ENV
// =========================
$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    die("Error: No se encontró el archivo .env en: " . $envPath);
}

// =========================
// LECTURA SEGURA DEL .ENV
// =========================
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$env = [];

foreach ($lines as $line) {

    $line = trim($line);

    // Ignorar líneas vacías o comentarios
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    // Ignorar líneas inválidas
    if (!str_contains($line, '=')) {
        continue;
    }

    list($key, $value) = explode('=', $line, 2);

    $value = trim($value);

    // quitar comillas si existen
    $value = trim($value, '"');

    $env[trim($key)] = $value;
}

// =========================
// VARIABLES DE ENTORNO
// =========================
$host = $env['DB_HOST'] ?? '';
$db   = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';

// =========================
// VALIDACIÓN
// =========================
if (!$host || !$db || !$user) {
    die("Error: variables de entorno incompletas en .env");
}

// =========================
// CONEXIÓN MYSQL
// =========================
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// =========================
// CONFIG EXTRA (RECOMENDADO)
// =========================
$conn->set_charset("utf8mb4");