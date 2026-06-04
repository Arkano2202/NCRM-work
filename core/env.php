<?php

function loadEnvFile($path, $required = false) {
    if (!file_exists($path)) {
        if ($required) {
            throw new Exception(".env no encontrado: " . $path);
        }
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);

        $name = trim($name);
        $value = trim(trim($value), '"\'');

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("$name=$value");
    }
}

loadEnvFile(__DIR__ . '/../.env', true);

function env($key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value !== false && $value !== null ? $value : $default;
}
