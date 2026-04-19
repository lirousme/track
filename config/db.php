<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $env = loadEnv(__DIR__ . '/../.env');

    $host = $env['DB_HOST'] ?? 'localhost';
    $name = $env['DB_NAME'] ?? 'track_app';
    $user = $env['DB_USER'] ?? 'root';
    $pass = $env['DB_PASS'] ?? '';

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}
