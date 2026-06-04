<?php

require_once __DIR__ . "/monitoreo_dia.php";

function tiposAgenteNovedades(): array
{
    return [2, 3, 7];
}

function esTipoAgenteNovedades(int $tipo): bool
{
    return in_array($tipo, tiposAgenteNovedades(), true);
}

function opcionesTipoNovedad(): array
{
    return [
        "Ausentismo",
        "Llegada Tarde",
        "No deslogueo",
        "Permiso TL",
    ];
}

function opcionesMotivoNovedad(): array
{
    return [
        "Llamada de whatsapp",
        "No deslogueo",
        "Reunion tl",
        "Falla Sistema (CTM, CRM, Micro sip)",
        "No se cambia de estado",
        "Apoyando con llamada a un companero",
        "Otros",
    ];
}

function campoTiempoNovedadPorDescripcion(string $descripcion): string
{
    $descripcion = strtolower(trim($descripcion));
    $especiales = [
        "llamada de whatsapp",
        "apoyando con llamada a un companero",
    ];

    return in_array($descripcion, $especiales, true) ? "t_novedad_a" : "t_novedad";
}

function usuariosVisiblesParaNovedades(mysqli $conn, int $tipo, int $userId, string $pertenece): array
{
    $agentes = [];
    $sql = "";
    $params = [];
    $types = "";

    if ($tipo === 1) {
        $sql = "SELECT id, Nombre, Usuario, Grupo, pertenece FROM users WHERE Tipo IN (2,3,7) ORDER BY Nombre ASC";
    } elseif (in_array($tipo, [4, 5, 8], true)) {
        $sql = "SELECT id, Nombre, Usuario, Grupo, pertenece FROM users WHERE Tipo IN (2,3,7) AND Grupo = ? ORDER BY Nombre ASC";
        $types = "i";
        $params[] = $userId;
    } elseif (in_array($tipo, [9, 10], true)) {
        $sql = "SELECT id, Nombre, Usuario, Grupo, pertenece FROM users WHERE Tipo IN (2,3,7) AND pertenece = ? ORDER BY Nombre ASC";
        $types = "s";
        $params[] = $pertenece;
    }

    if ($sql === "") {
        return [];
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $agentes[] = $row;
    }
    $stmt->close();

    return $agentes;
}

function indiceUsuariosVisiblesPorUsuario(array $usuarios): array
{
    $index = [];
    foreach ($usuarios as $usuario) {
        $clave = trim((string) ($usuario["Usuario"] ?? ""));
        if ($clave !== "") {
            $index[$clave] = $usuario;
        }
    }
    return $index;
}

function opcionesGrupalesNovedades(mysqli $conn, int $tipo, int $userId, string $pertenece): array
{
    if (in_array($tipo, [4, 5, 8], true)) {
        $stmt = $conn->prepare("SELECT Nombre FROM users WHERE id = ? LIMIT 1");
        $label = "Grupo TL";
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row["Nombre"])) {
                $label = "Grupo de " . $row["Nombre"];
            }
        }

        return [[
            "value" => "grupo_tl:" . $userId,
            "label" => $label,
        ]];
    }

    if (in_array($tipo, [9, 10], true)) {
        return [[
            "value" => "pertenece:" . $pertenece,
            "label" => "Ciudad de " . $pertenece,
        ]];
    }

    if ($tipo === 1) {
        $opciones = [];

        $resTl = $conn->query("SELECT id, Nombre FROM users WHERE Tipo IN (4,5,8) ORDER BY Nombre ASC");
        if ($resTl) {
            while ($row = $resTl->fetch_assoc()) {
                $opciones[] = [
                    "value" => "grupo_tl:" . (int) $row["id"],
                    "label" => "Grupo de " . $row["Nombre"],
                ];
            }
        }

        $resCiudad = $conn->query("SELECT Nombre FROM ciudad ORDER BY Nombre ASC");
        if ($resCiudad) {
            while ($row = $resCiudad->fetch_assoc()) {
                $ciudad = trim((string) ($row["Nombre"] ?? ""));
                if ($ciudad === "") {
                    continue;
                }
                $opciones[] = [
                    "value" => "pertenece:" . $ciudad,
                    "label" => "Ciudad de " . $ciudad,
                ];
            }
        }

        return $opciones;
    }

    return [];
}

function resolverObjetivoGrupalNovedades(mysqli $conn, int $tipo, int $userId, string $pertenece, string $seleccion): ?string
{
    $opciones = opcionesGrupalesNovedades($conn, $tipo, $userId, $pertenece);
    foreach ($opciones as $opcion) {
        if (($opcion["value"] ?? "") === $seleccion) {
            return $seleccion;
        }
    }

    return null;
}

function etiquetaObjetivoGrupo(string $valor, ?mysqli $conn = null): string
{
    if (strpos($valor, "grupo_tl:") === 0) {
        $idTl = (int) substr($valor, strlen("grupo_tl:"));
        if ($conn) {
            $stmt = $conn->prepare("SELECT Nombre FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $idTl);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($row["Nombre"])) {
                    return "Grupo de " . $row["Nombre"];
                }
            }
        }

        return "Grupo TL #" . $idTl;
    }

    if (strpos($valor, "pertenece:") === 0) {
        return "Ciudad de " . substr($valor, strlen("pertenece:"));
    }

    return $valor;
}

function etiquetaObjetivoNovedad(array $novedad, ?mysqli $conn = null): string
{
    if (($novedad["alcance"] ?? "") === "individual") {
        return (string) ($novedad["usuario_objetivo"] ?? "");
    }

    return etiquetaObjetivoGrupo((string) ($novedad["grupo_objetivo"] ?? ""), $conn);
}

function usuariosAfectadosPorNovedad(mysqli $conn, array $novedad): array
{
    if (($novedad["alcance"] ?? "") === "individual") {
        $usuario = trim((string) ($novedad["usuario_objetivo"] ?? ""));
        return $usuario === "" ? [] : [$usuario];
    }

    $grupoObjetivo = trim((string) ($novedad["grupo_objetivo"] ?? ""));
    if ($grupoObjetivo === "") {
        return [];
    }

    $usuarios = [];

    if (strpos($grupoObjetivo, "grupo_tl:") === 0) {
        $tlId = (int) substr($grupoObjetivo, strlen("grupo_tl:"));
        $stmt = $conn->prepare("SELECT Usuario FROM users WHERE Tipo IN (2,3,7) AND Grupo = ?");
        if ($stmt) {
            $stmt->bind_param("i", $tlId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $usuario = trim((string) ($row["Usuario"] ?? ""));
                if ($usuario !== "") {
                    $usuarios[] = $usuario;
                }
            }
            $stmt->close();
        }
    }

    if (strpos($grupoObjetivo, "pertenece:") === 0) {
        $ciudad = substr($grupoObjetivo, strlen("pertenece:"));
        $stmt = $conn->prepare("SELECT Usuario FROM users WHERE Tipo IN (2,3,7) AND pertenece = ?");
        if ($stmt) {
            $stmt->bind_param("s", $ciudad);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $usuario = trim((string) ($row["Usuario"] ?? ""));
                if ($usuario !== "") {
                    $usuarios[] = $usuario;
                }
            }
            $stmt->close();
        }
    }

    return array_values(array_unique($usuarios));
}

function sumarTiempoNovedadMonitoreo(mysqli $conn, string $usuario, string $fecha, string $campoDestino, string $tiempoNovedad): bool
{
    if (!in_array($campoDestino, ["t_novedad", "t_novedad_a"], true)) {
        return false;
    }

    $registro = obtenerRegistroMonitoreoDia($conn, $usuario, $fecha);
    if ($registro === null) {
        return false;
    }

    $tiempoActual = segundosATiempoTexto(tiempoTextoASegundos((string) ($registro[$campoDestino] ?? "0")));
    $tiempoNuevo = segundosATiempoTexto(
        tiempoTextoASegundos($tiempoActual) + tiempoTextoASegundos($tiempoNovedad)
    );

    $sql = "UPDATE monitoreo SET {$campoDestino} = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $registroId = (int) ($registro["id"] ?? 0);
    $stmt->bind_param("si", $tiempoNuevo, $registroId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}
