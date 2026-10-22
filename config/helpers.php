<?php
/**
 * SMART ATTENDANCE AI - PHASE 2
 *
 * Shared helper functions used across the application.
 */

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}
