<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/database.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
date_default_timezone_set('UTC');

function app_is_production(): bool
{
    return ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'production';
}

function app_request_id(): string
{
    static $requestId = null;
    return $requestId ??= bin2hex(random_bytes(16));
}

function app_pdo(): PDO
{
    static $pdo = null;
    return $pdo ??= pds_create_pdo();
}

function app_auth(): Pds\Auth
{
    $pdo = app_pdo();
    $key = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
    return new Pds\Auth($pdo, new Pds\LoginThrottle($pdo, $key), new Pds\AuditLogger($pdo));
}

if (app_is_production()) {
    ini_set('display_errors', '0');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');
ini_set('zend.exception_ignore_args', '1');
$secureCookie = app_is_production() || ($_ENV['SESSION_SECURE_COOKIE'] ?? getenv('SESSION_SECURE_COOKIE')) === '1';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!headers_sent()) {
    header('X-Request-ID: ' . app_request_id());
}
