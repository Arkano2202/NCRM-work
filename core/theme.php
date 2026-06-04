<?php

function availableThemes(): array
{
    return [
        'clasico' => 'Clasico',
        'azul' => 'Azul',
        'rosado' => 'Rosado',
        'oscuro' => 'Oscuro',
    ];
}

function normalizeThemeName(?string $theme): string
{
    $theme = strtolower(trim((string) $theme));
    return array_key_exists($theme, availableThemes()) ? $theme : 'clasico';
}
