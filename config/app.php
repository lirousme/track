<?php

declare(strict_types=1);

function trackBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptName === '') {
        return '';
    }

    $basePath = str_contains($scriptName, '/api/')
        ? dirname(dirname($scriptName))
        : dirname($scriptName);

    $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

    if ($basePath === '' || $basePath === '.') {
        return '';
    }

    return $basePath;
}

function trackUrl(string $path = ''): string
{
    $normalizedPath = '/' . ltrim($path, '/');

    return trackBasePath() . $normalizedPath;
}
