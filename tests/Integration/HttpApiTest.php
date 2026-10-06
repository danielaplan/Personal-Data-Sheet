<?php

declare(strict_types=1);

namespace Pds\Tests\Integration;

use Pds\Tests\Support\DatabaseTestCase;
use Pds\Tests\Support\HttpClient;
use Pds\Tests\Support\HttpServer;

final class HttpApiTest extends DatabaseTestCase
{
    private static ?HttpServer $server = null;
    private static array $environment;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$environment = array_merge(getenv(), [
            'APP_ENV' => 'test',
            'APP_KEY' => 'http-integration-test-key-not-for-production',
            'DB_DSN' => (string) ($_ENV['TEST_DB_DSN'] ?? getenv('TEST_DB_DSN')),
            'DB_USER' => (string) ($_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER')),
            'DB_PASSWORD' => (string) ($_ENV['TEST_DB_PASSWORD'] ?? getenv('TEST_DB_PASSWORD')),
            'SESSION_SECURE_COOKIE' => '0',
            'TRUSTED_PROXY' => '',
        ]);
        self::$server = new HttpServer(self::$environment);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        parent::tearDownAfterClass();
    }

    public function testLoginLogoutCookiesCsrfAndOldSessionsOverHttp(): void
    {
        $this->createStaffUser();
        $client = self::$server->client();
        $page = $client->request('GET', '/login.php');
        self::assertSame(200, $page['status']);
        self::assertSame('no-store', $page['headers']['cache-control']);
        self::assertStringContainsString('HttpOnly', $page['headers']['set-cookie']);
        self::assertStringContainsString('SameSite=Strict', $page['headers']['set-cookie']);
        $token = $this->token($page);
        $anonymous = clone $client;
        $credentials = ['username' => 'TEST_STAFF', 'password' => 'Correct Horse Battery Staple'];
        $this->assertEnvelope($client->json('POST', '/api/auth/login.php', $credentials), 403);
        $this->assertEnvelope($client->json('POST', '/api/auth/login.php', $credentials, 'wrong'), 403);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $login = $client->json('POST', '/api/auth/login.php', $credentials, $token);
        $this->assertEnvelope($login, 200);
        $newToken = $login['json']['data']['csrf_token'];
        self::assertNotSame($token, $newToken);
        self::assertNotSame($page['headers']['set-cookie'], $login['headers']['set-cookie']);
        $redirect = $client->request('GET', '/login.php');
        self::assertSame(302, $redirect['status']);
        self::assertSame('index.php', $redirect['headers']['location']);
        self::assertSame(200, $anonymous->request('GET', '/login.php')['status']);
        $this->assertEnvelope($client->json('POST', '/api/auth/logout.php', [], $token), 403);
        $this->assertEnvelope($client->json('GET', '/api/auth/logout.php', [], $newToken), 405);
        $this->assertEnvelope($client->json('POST', '/api/auth/logout.php', ['extra' => true], $newToken), 400);
        $previousSession = clone $client;
        $logout = $client->json('POST', '/api/auth/logout.php', [], $newToken);
        $this->assertEnvelope($logout, 200);
        self::assertNotSame($newToken, $logout['json']['data']['csrf_token']);
        $this->assertEnvelope($previousSession->json('POST', '/api/auth/logout.php', [], $newToken), 401);
        self::assertSame(200, $client->request('GET', '/login.php')['status']);
    }

    public function testLoginRejectsInvalidBodiesAndUsesTheJsonEnvelope(): void
    {
        $client = self::$server->client();
        $token = $this->token($client->request('GET', '/login.php'));
        $this->assertEnvelope($client->request('GET', '/api/auth/login.php'), 405);
        foreach (['{', '[]', '{"username":{}}', '{"username":"staff","password":"x","extra":true}'] as $body) {
            $response = $client->request('POST', '/api/auth/login.php', $body, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token]);
            $this->assertEnvelope($response, $body === '{"username":{}}' ? 422 : 400);
        }
        $this->assertEnvelope($client->request('POST', '/api/auth/login.php', '{}', ['Content-Type' => 'text/plain', 'X-CSRF-Token' => $token]), 400);
        $this->assertEnvelope($client->request('POST', '/api/auth/login.php', str_repeat('x', 1_048_577), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token]), 400);
    }

    public function testHttpLockoutDoesNotTrustSpoofedForwardedAddresses(): void
    {
        $this->createStaffUser();
        $client = self::$server->client();
        $token = $this->token($client->request('GET', '/login.php'));
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertEnvelope($client->request('POST', '/api/auth/login.php', '{"username":"test_staff","password":"incorrect"}', [
                'Content-Type' => 'application/json', 'X-CSRF-Token' => $token, 'X-Forwarded-For' => '192.0.2.' . ($attempt + 1),
            ]), 401);
        }
        $this->assertEnvelope($client->json('POST', '/api/auth/login.php', ['username' => 'test_staff', 'password' => 'Correct Horse Battery Staple'], $token), 429);
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM login_throttles')->fetchColumn());
    }

    public function testDisabledSessionReturnsToLoginAndWebCannotRunAccountCreator(): void
    {
        $this->createStaffUser();
        $client = self::$server->client();
        $token = $this->token($client->request('GET', '/login.php'));
        $login = $client->json('POST', '/api/auth/login.php', ['username' => 'test_staff', 'password' => 'Correct Horse Battery Staple'], $token);
        $this->assertEnvelope($login, 200);
        $this->pdo()->exec('UPDATE staff_users SET is_active = 0');
        $this->assertEnvelope($client->json('POST', '/api/auth/logout.php', [], $login['json']['data']['csrf_token']), 403);
        self::assertSame(200, $client->request('GET', '/login.php')['status']);
        self::assertSame(404, $client->request('GET', '/scripts/create-staff-user.php')['status']);
    }

    public function testAccountCreatorAcceptsStdinAndEnvironmentAndRejectsDuplicatesAndPasswordArguments(): void
    {
        $arguments = ['--username', '  CLI_STAFF  ', '--display-name', 'CLI Staff', '--password-stdin'];
        $created = $this->createAccount($arguments, 'A test password from stdin' . PHP_EOL);
        self::assertSame(0, $created['code'], $created['error']);
        self::assertSame('cli_staff', $this->pdo()->query('SELECT username FROM staff_users')->fetchColumn());
        self::assertTrue(password_verify('A test password from stdin', $this->pdo()->query('SELECT password_hash FROM staff_users')->fetchColumn()));
        $duplicate = $this->createAccount($arguments, 'Another test password');
        self::assertSame(1, $duplicate['code']);
        self::assertStringContainsString('already in use', $duplicate['error']);
        $fromEnvironment = $this->createAccount(['--username=environment_staff', '--display-name=Environment Staff'], '', ['PDS_STAFF_PASSWORD' => 'A test password from environment']);
        self::assertSame(0, $fromEnvironment['code'], $fromEnvironment['error']);
        $rejected = $this->createAccount(['--username=bad_staff', '--display-name=Bad Staff', '--password=must-not-be-echoed']);
        self::assertSame(1, $rejected['code']);
        self::assertStringNotContainsString('must-not-be-echoed', $rejected['error'] . $rejected['output']);
        self::assertSame(1, $this->createAccount(['--username=bad_staff', '--display-name=Bad Staff'])['code']);
        self::assertSame(1, $this->createAccount(['--username=bad_staff', '--display-name=Bad Staff', '--password-stdin'], str_repeat('x', 73))['code']);
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn());
    }

    private function createAccount(array $arguments, string $input = '', array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/create-staff-user.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, dirname(__DIR__, 2), array_merge(self::$environment, ['PDS_STAFF_PASSWORD' => ''], $environment),
            ['bypass_shell' => true, 'create_no_window' => true],
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($process), 'output' => $output, 'error' => $error];
    }

    private function token(array $page): string
    {
        self::assertSame(1, preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $page['body'], $matches));
        return $matches[1];
    }

    private function assertEnvelope(array $response, int $status): void
    {
        self::assertSame($status, $response['status']);
        self::assertSame(['ok', 'data', 'message', 'errors'], array_keys($response['json']));
        self::assertSame($status < 400, $response['json']['ok']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['content-type']);
        self::assertSame('no-store', $response['headers']['cache-control']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response['headers']['x-request-id']);
    }
}
