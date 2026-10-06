<?php

declare(strict_types=1);

function pds_create_pdo(): PDO
{
    $dsn = $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: '';
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('DB_DSN is not configured.');
    }

    $pdo = new PDO(
        $dsn,
        (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''),
        (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
