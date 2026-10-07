<?php
declare(strict_types=1);

function recordDatabase(bool $selectDatabase = true): PDO
{
    $host = getenv('PDS_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PDS_DB_PORT') ?: '3306';
    $name = getenv('PDS_DB_NAME') ?: 'pds';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name.');
    }
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    if ($selectDatabase) $dsn .= ";dbname=$name";
    return new PDO($dsn, getenv('PDS_DB_USER') ?: 'root', getenv('PDS_DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
