<?php

function getRol($tipo) {
    $tipo = (int) $tipo;

    if ($tipo === 1) {
        return "admin";
    }

    if (in_array($tipo, [4, 5, 8], true)) {
        return "tl";
    }

    if (in_array($tipo, [9, 10], true)) {
        return "floor";
    }

    if (in_array($tipo, [2, 3, 7], true)) {
        return "user";
    }

    return "user";
}

function canView($modulo) {
    $tipo = (int) ($_SESSION["tipo"] ?? 0);
    $rol = getRol($tipo);

    $permisos = [
        "admin" => [
            "dashboard",
            "leads",
            "chat",
            "chat_images_admin",
            "calendario",
            "documentos",
            "novedades",
            "novedades_aprobar",
            "subir_leads",
            "cargar",
            "actualizar",
            "asignar_individual",
            "eliminar_leads",
            "usuarios",
            "asignar_usuarios",
            "historico",
            "exportar_leads",
            "monitor",
        ],
        "tl" => [
            "dashboard",
            "leads",
            "chat",
            "calendario",
            "novedades",
            "monitor",
        ],
        "floor" => [
            "dashboard",
            "leads",
            "chat",
            "calendario",
            "documentos_review",
            "novedades",
            "historico",
            "monitor",
        ],
        "user" => [
            "dashboard",
            "leads",
            "chat",
            "calendario",
            "tiempos",
            "documentos",
        ],
    ];

    if ($modulo === "documentos") {
        return in_array($tipo, [3, 7], true) && isset($permisos[$rol]) && in_array($modulo, $permisos[$rol], true);
    }

    return isset($permisos[$rol]) && in_array($modulo, $permisos[$rol], true);
}

function requirePermission($modulo) {
    if (!canView($modulo)) {
        http_response_code(403);
        exit("Acceso denegado");
    }
}
