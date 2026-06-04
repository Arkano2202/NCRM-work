<?php

function appBasePath(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pagesMarker = '/pages/';

    if (($pos = strpos($scriptName, $pagesMarker)) !== false) {
        $basePath = substr($scriptName, 0, $pos);
    } else {
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }

    $basePathNormalizado = ltrim($basePath, '/');
    if (
        $basePath === '/var/www/html' ||
        str_starts_with($basePath, '/var/www/html/') ||
        $basePathNormalizado === 'var/www/html' ||
        str_starts_with($basePathNormalizado, 'var/www/html/')
    ) {
        $basePath = '';
    }

    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    return $basePath;
}

function appUrl(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = appBasePath();

    if ($path === '') {
        return $base !== '' ? $base : '/';
    }

    return ($base !== '' ? $base : '') . '/' . $path;
}

function routePath(string $name): string
{
    $routes = [
        'login' => '',
        'logout' => 'logout',
        'dashboard' => 'dashboard',
        'leads' => 'leads',
        'chat' => 'chat',
        'chat_images_admin' => 'chat/imagenes',
        'lead_details' => 'lead',
        'delete_leads' => 'eliminar-leads',
        'export_leads' => 'exportar',
        'history' => 'historico',
        'calendar' => 'calendario',
        'documents' => 'documentos',
        'upload_leads' => 'cargar',
        'update_leads' => 'actualizar',
        'assign_individual' => 'asignar-individual',
        'users' => 'usuarios',
        'assign_users' => 'asignar-usuarios',
        'monitor' => 'monitoreo',
        'times' => 'tiempos',
        'news' => 'novedades',
        'news_approve' => 'novedades/aprobar',
        'documents_review' => 'documentos/revision',
        'documents_hub' => 'documentos/bandeja',
    ];

    return $routes[$name] ?? $name;
}

function routeUrl(string $name, array $query = []): string
{
    $url = appUrl(routePath($name));
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function redirectToRoute(string $name, array $query = []): void
{
    header('Location: ' . routeUrl($name, $query));
    exit;
}
