<?php

declare(strict_types=1);

namespace Pds\Tests\Support;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class DatabaseTestCase extends TestCase
{
    private static ?PDO $connection = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
            throw new RuntimeException('Database tests require APP_ENV=test.');
        }

        $dsn = $_ENV['TEST_DB_DSN'] ?? getenv('TEST_DB_DSN') ?: '';
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured; isolated database tests were not run.');
        }

        $productionDsn = $_ENV['DB_DSN'] ?? getenv('DB_DSN') ?: '';
        if ($dsn === $productionDsn || !preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $matches)
            || !preg_match('/(?:^|_)test(?:_|$)/i', $matches[1])) {
            throw new RuntimeException('TEST_DB_DSN must name a distinct database with a "test" segment.');
        }

        self::$connection = new PDO(
            $dsn,
            (string) ($_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER') ?: ''),
            (string) ($_ENV['TEST_DB_PASSWORD'] ?? getenv('TEST_DB_PASSWORD') ?: ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
            ],
        );
        self::$connection->exec("SET time_zone = '+00:00'");
        $version = (string) self::$connection->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            throw new RuntimeException('Could not determine the test database version.');
        }
        $minimum = stripos($version, 'MariaDB') !== false ? '10.6.0' : '8.0.0';
        if (version_compare($matches[1], $minimum, '<')) {
            self::markTestSkipped("Database {$version} is below the supported minimum {$minimum}.");
        }
        self::importSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    protected function pdo(): PDO
    {
        if (self::$connection === null) {
            throw new RuntimeException('The isolated test database is not connected.');
        }
        return self::$connection;
    }

    protected function resetDatabase(): void
    {
        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
            throw new RuntimeException('Database reset requires APP_ENV=test.');
        }

        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['audit_log', 'pds_education', 'pds_children', 'pds_records', 'login_throttles', 'staff_users'] as $table) {
                $pdo->exec('TRUNCATE TABLE ' . $table);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function createStaffUser(array $overrides = []): int
    {
        $data = array_replace([
            'username' => 'test_staff',
            'display_name' => 'Test Staff',
            'password_hash' => password_hash('Correct Horse Battery Staple', PASSWORD_DEFAULT),
            'is_active' => 1,
        ], $overrides);
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['username', 'display_name', 'password_hash', 'is_active'], true)) {
                throw new InvalidArgumentException("Unknown staff fixture field: {$key}");
            }
        }

        $statement = $this->pdo()->prepare(
            'INSERT INTO staff_users (username, display_name, password_hash, is_active)
             VALUES (:username, :display_name, :password_hash, :is_active)',
        );
        $statement->execute($data);
        return (int) $this->pdo()->lastInsertId();
    }

    private static function importSchema(): void
    {
        if (self::$connection === null) {
            throw new RuntimeException('The isolated test database is not connected.');
        }
        $path = dirname(__DIR__, 2) . '/database/schema.sql';
        $schema = file_get_contents($path);
        if ($schema === false) {
            throw new RuntimeException('Could not read database/schema.sql.');
        }
        foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
            if (trim($statement) !== '') {
                self::$connection->exec($statement);
            }
        }
    }
}
