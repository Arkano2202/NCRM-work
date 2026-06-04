<?php

function esAgenteTipo(int $tipo): bool
{
    return in_array($tipo, [2, 3, 7], true);
}

function estadosMonitoreoDisponibles(): array
{
    return [
        'Almuerzo' => 'almuerzo',
        'Descanso' => 'descanso',
        'Formacion' => 'formacion',
        'Baño' => 'bano',
    ];
}

function monitoreoTraducir(string $fallbackKey, string $fallbackText): string
{
    return function_exists('t') ? t($fallbackKey) : $fallbackText;
}

function tiempoTextoASegundos(?string $valor): int
{
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '0') {
        return 0;
    }

    $partes = explode(':', $valor);
    if (count($partes) !== 3) {
        return 0;
    }

    return ((int) $partes[0] * 3600) + ((int) $partes[1] * 60) + (int) $partes[2];
}

function diferenciaSegundosEntreHoras(string $fecha, string $horaInicio, string $horaFin): int
{
    $tz = new DateTimeZone('America/Bogota');
    $inicio = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . $horaInicio, $tz);
    $fin = DateTime::createFromFormat('Y-m-d H:i:s', $fecha . ' ' . $horaFin, $tz);

    if (!$inicio || !$fin) {
        return 0;
    }

    return max(0, $fin->getTimestamp() - $inicio->getTimestamp());
}

function segundosATiempoTexto(int $segundos): string
{
    $segundos = max(0, $segundos);
    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);
    $resto = $segundos % 60;

    return sprintf('%02d:%02d:%02d', $horas, $minutos, $resto);
}

function cerrarJornadasPendientesAutomaticamente(mysqli $conn, ?string $fecha = null, string $horaCierre = '20:00:00'): array
{
    $fecha = $fecha ?: date('Y-m-d');
    $resultado = [
        'fecha' => $fecha,
        'hora_cierre' => $horaCierre,
        'cerrados' => 0,
        'usuarios' => [],
    ];

    $stmt = $conn->prepare("
        SELECT *
        FROM monitoreo
        WHERE fecha = ?
          AND h_entrada IS NOT NULL
          AND h_entrada <> ''
          AND h_entrada <> '0'
          AND (h_salida IS NULL OR h_salida = '' OR h_salida = '0')
        ORDER BY id ASC
    ");

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el cierre automatico de jornadas.');
    }

    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    $registros = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    if (empty($registros)) {
        return $resultado;
    }

    $estadosDisponibles = estadosMonitoreoDisponibles();
    $conn->begin_transaction();

    try {
        foreach ($registros as $registro) {
            $registroId = (int) ($registro['id'] ?? 0);
            $usuario = trim((string) ($registro['usuario'] ?? ''));
            $estadoActual = trim((string) ($registro['estado'] ?? 'Puesto'));
            $hReferencia = trim((string) ($registro['h_referencia'] ?? '0'));
            $camposUpdate = ["h_salida = ?", "h_referencia = '0'", "estado = 'Puesto'"];
            $parametros = [$horaCierre];
            $tipos = 's';

            if (
                $estadoActual !== '' &&
                $estadoActual !== '0' &&
                strcasecmp($estadoActual, 'Puesto') !== 0 &&
                isset($estadosDisponibles[$estadoActual]) &&
                $hReferencia !== '' &&
                $hReferencia !== '0'
            ) {
                $campoEstado = $estadosDisponibles[$estadoActual];
                $segundosActuales = tiempoTextoASegundos((string) ($registro[$campoEstado] ?? '0'));
                $segundosNuevos = diferenciaSegundosEntreHoras($fecha, $hReferencia, $horaCierre);
                $nuevoAcumulado = segundosATiempoTexto($segundosActuales + $segundosNuevos);

                $camposUpdate[] = "{$campoEstado} = ?";
                $parametros[] = $nuevoAcumulado;
                $tipos .= 's';
            }

            $sql = "UPDATE monitoreo SET " . implode(', ', $camposUpdate) . " WHERE id = ?";
            $parametros[] = $registroId;
            $tipos .= 'i';

            $stmtUpdate = $conn->prepare($sql);
            if (!$stmtUpdate) {
                throw new RuntimeException('No fue posible preparar la actualizacion de cierre automatico.');
            }

            $stmtUpdate->bind_param($tipos, ...$parametros);
            if (!$stmtUpdate->execute()) {
                $stmtUpdate->close();
                throw new RuntimeException('No fue posible ejecutar el cierre automatico.');
            }
            $stmtUpdate->close();

            $resultado['cerrados']++;
            if ($usuario !== '') {
                $resultado['usuarios'][] = $usuario;
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return $resultado;
}

function obtenerRegistroMonitoreoDia(mysqli $conn, string $usuario, ?string $fecha = null): ?array
{
    $fecha = $fecha ?: date('Y-m-d');
    $stmt = $conn->prepare("SELECT * FROM monitoreo WHERE usuario = ? AND fecha = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ss", $usuario, $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function estadoJornadaAgente(mysqli $conn, string $usuario, int $tipo): array
{
    if (!esAgenteTipo($tipo)) {
        return [
            'aplica' => false,
            'bloqueado' => false,
            'mensaje' => null,
            'registro' => null,
        ];
    }

    $registro = obtenerRegistroMonitoreoDia($conn, $usuario);

    if ($registro === null) {
        return [
            'aplica' => true,
            'bloqueado' => true,
            'mensaje' => monitoreoTraducir('journey.must_start', 'Debes iniciar el dia'),
            'registro' => null,
        ];
    }

    if (($registro['h_salida'] ?? '0') !== '0') {
        return [
            'aplica' => true,
            'bloqueado' => true,
            'mensaje' => monitoreoTraducir('journey.finished', 'Haz finalizado tu jornada de trabajo'),
            'registro' => $registro,
        ];
    }

    $estadoActual = trim((string) ($registro['estado'] ?? '0'));
    if ($estadoActual !== '' && $estadoActual !== '0' && $estadoActual !== 'Puesto') {
        return [
            'aplica' => true,
            'bloqueado' => true,
            'mensaje' => monitoreoTraducir('journey.state_prefix', 'Estas en estado ') . $estadoActual . monitoreoTraducir('journey.state_suffix', ', sal del estado para volver a ver los leads'),
            'registro' => $registro,
        ];
    }

    return [
        'aplica' => true,
        'bloqueado' => false,
        'mensaje' => null,
        'registro' => $registro,
    ];
}
