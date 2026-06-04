<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/monitoreo_dia.php";

date_default_timezone_set('America/Bogota');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado');
}

$fecha = $argv[1] ?? date('Y-m-d');
$horaCierre = $argv[2] ?? '20:00:00';

try {
    $resultado = cerrarJornadasPendientesAutomaticamente($conn, $fecha, $horaCierre);

    echo json_encode([
        'ok' => true,
        'fecha' => $resultado['fecha'],
        'hora_cierre' => $resultado['hora_cierre'],
        'cerrados' => $resultado['cerrados'],
        'usuarios' => $resultado['usuarios'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
