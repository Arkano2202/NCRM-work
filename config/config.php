<?php
// config.php - Cargar variables de entorno
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("El archivo .env no existe");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), '"\'');

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

try {
    loadEnv(__DIR__ . '/../.env');
} catch (Exception $e) {
    die("Error cargando configuracion: " . $e->getMessage());
}

function env($key, $default = null) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}
?>
