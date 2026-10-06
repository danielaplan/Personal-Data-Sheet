<?php

declare(strict_types=1);

namespace Pds\Tests\Unit;

use Pds\ApiException;
use Pds\AuditLogger;
use Pds\Auth;
use Pds\Csrf;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsStableAndRandomLooking(): void
    {
        $token = Csrf::token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, Csrf::token());
        Csrf::assertValid($token);
    }

    public function testCsrfRejectsMissingAndMismatchedTokens(): void
    {
        Csrf::token();

        foreach ([null, '', str_repeat('0', 64)] as $candidate) {
            try {
                Csrf::assertValid($candidate);
                self::fail('Expected invalid CSRF token to be rejected.');
            } catch (ApiException $exception) {
                self::assertSame(403, $exception->status());
                self::assertSame('Your security token is invalid or expired. Refresh and try again.', $exception->publicMessage());
            }
        }
    }

    public function testRotatingCsrfInvalidatesThePreviousToken(): void
    {
        $oldToken = Csrf::token();
        $newToken = Csrf::rotate();

        self::assertNotSame($oldToken, $newToken);
        Csrf::assertValid($newToken);

        $this->expectException(ApiException::class);
        Csrf::assertValid($oldToken);
    }

    public function testAuditLoggerRejectsUnknownActionsAndSensitiveMetadataKeys(): void
    {
        $logger = new AuditLogger($this->createStub(\PDO::class));
        $invalid = [['unknown_action', []]];
        foreach (['password', 'session_token', 'csrf', 'umid', 'pagibig', 'philhealth', 'philsys', 'tin', 'payload', 'username', 'source_address'] as $key) {
            $invalid[] = ['login_failure', [$key => 'secret']];
            $invalid[] = ['login_failure', ['nested' => [strtoupper($key) => 'secret']]];
        }
        $invalid[] = ['update', ['notes' => 'unapproved metadata']];
        $invalid[] = ['update', ['version' => 'secret']];
        $invalid[] = ['update', ['version' => 0]];
        $invalid[] = ['login_success', ['version' => 1]];
        foreach ($invalid as [$action, $metadata]) {
            try {
                $logger->record(null, null, $action, $metadata);
                self::fail('Unsafe audit data was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSourceAddressOnlyTrustsAnExplicitProxy(): void
    {
        $server = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 203.0.113.3'];
        self::assertSame('192.0.2.1', Auth::sourceAddress($server));
        self::assertSame('192.0.2.1', Auth::sourceAddress($server, '192.0.2.99'));
        self::assertSame('198.51.100.2', Auth::sourceAddress($server, '192.0.2.1'));
        $server['HTTP_X_FORWARDED_FOR'] = 'invalid, 198.51.100.2';
        self::assertSame('192.0.2.1', Auth::sourceAddress($server, '192.0.2.1'));
        $server['HTTP_X_FORWARDED_FOR'] = '2001:db8::1';
        self::assertSame('2001:db8::1', Auth::sourceAddress($server, '192.0.2.1'));
    }
}
