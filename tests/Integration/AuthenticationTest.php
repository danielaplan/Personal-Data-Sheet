<?php

declare(strict_types=1);

namespace Pds\Tests\Integration;

use Pds\ApiException;
use Pds\AuditLogger;
use Pds\Auth;
use Pds\Csrf;
use Pds\LoginThrottle;
use Pds\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthenticationTest extends DatabaseTestCase
{
    private const KEY = 'authentication-test-key-not-for-production';
    private const ADDRESS = '192.0.2.20';
    private LoginThrottle $throttle;
    private Auth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        session_start(['use_cookies' => false, 'cache_limiter' => '']);
        $_SESSION = [];
        $this->throttle = new LoginThrottle($this->pdo(), self::KEY);
        $this->auth = new Auth($this->pdo(), $this->throttle, new AuditLogger($this->pdo()));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        parent::tearDown();
    }

    public function testSuccessfulLoginRegeneratesSessionAndAuditsWithoutSensitiveData(): void
    {
        $id = $this->createStaffUser();
        $oldSession = session_id();
        $oldToken = Csrf::token();
        $_SESSION['unrelated'] = 'must not survive login';
        $user = $this->auth->attempt('  TEST_STAFF  ', 'Correct Horse Battery Staple', self::ADDRESS);

        self::assertSame(['id' => $id, 'display_name' => 'Test Staff'], $user);
        self::assertNotSame($oldSession, session_id());
        self::assertNotSame($oldToken, Csrf::token());
        self::assertEqualsCanonicalizing(['staff', 'csrf_token'], array_keys($_SESSION));
        self::assertSame(['id', 'display_name', 'last_activity'], array_keys($_SESSION['staff']));
        self::assertGreaterThanOrEqual(time() - 2, $_SESSION['staff']['last_activity']);
        self::assertNotNull($this->pdo()->query('SELECT last_login_at FROM staff_users')->fetchColumn());
        $audit = $this->pdo()->query('SELECT * FROM audit_log')->fetch();
        self::assertSame('login_success', $audit['action']);
        self::assertSame($id, (int) $audit['staff_user_id']);
        self::assertNull($audit['metadata']);
        foreach (['password', 'token', 'csrf', 'umid', 'pagibig', 'philhealth', 'philsys', 'tin', 'payload', 'username', 'source_address'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, (string) $audit['metadata']);
        }
    }

    public function testInvalidUsernameAndInvalidPasswordReturnTheSameMessage(): void
    {
        $id = $this->createStaffUser();
        foreach (['missing_staff', 'test_staff'] as $username) {
            $this->assertApiFailure(fn () => $this->auth->attempt($username, 'incorrect', self::ADDRESS), 401, 'Invalid username or password.');
            self::assertArrayNotHasKey('staff', $_SESSION);
        }
        $rows = $this->pdo()->query('SELECT action, staff_user_id, metadata FROM audit_log ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('login_failure', $rows[0]['action']);
        self::assertNull($rows[0]['staff_user_id']);
        self::assertSame($id, (int) $rows[1]['staff_user_id']);
        self::assertNull($rows[0]['metadata']);
        self::assertNull($rows[1]['metadata']);
    }

    public function testFiveFailuresLockBothAccountAndAddressForFifteenMinutes(): void
    {
        $this->createStaffUser();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertApiFailure(fn () => $this->auth->attempt('test_staff', 'incorrect', self::ADDRESS), 401);
        }
        $rows = $this->pdo()->query('SELECT *, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until) AS remaining FROM login_throttles')->fetchAll();
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing([
            hash_hmac('sha256', 'account:test_staff', self::KEY),
            hash_hmac('sha256', 'address:' . self::ADDRESS, self::KEY),
        ], array_column($rows, 'throttle_key'));
        foreach ($rows as $row) {
            self::assertSame(5, (int) $row['failed_attempts']);
            self::assertGreaterThanOrEqual(890, (int) $row['remaining']);
            self::assertLessThanOrEqual(900, (int) $row['remaining']);
        }
        $this->assertApiFailure(fn () => $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', '192.0.2.21'), 429, 'Too many login attempts. Try again later.');
        $this->assertApiFailure(fn () => $this->auth->attempt('different_staff', 'incorrect', self::ADDRESS), 429);
        self::assertSame(5, (int) $this->pdo()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testExpiredThrottleWindowsAndLocksAllowAFreshAttempt(): void
    {
        $this->throttle->recordFailure('test_staff', self::ADDRESS);
        $this->pdo()->exec('UPDATE login_throttles SET window_started_at = UTC_TIMESTAMP() - INTERVAL 16 MINUTE, failed_attempts = 4');
        $this->throttle->recordFailure('test_staff', self::ADDRESS);
        self::assertSame([1, 1], array_map('intval', $this->pdo()->query('SELECT failed_attempts FROM login_throttles')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->pdo()->exec('UPDATE login_throttles SET window_started_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE, failed_attempts = 5, locked_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $this->throttle->assertAllowed('test_staff', self::ADDRESS);
        $this->throttle->recordFailure('test_staff', self::ADDRESS);
        self::assertSame([1, 1], array_map('intval', $this->pdo()->query('SELECT failed_attempts FROM login_throttles')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM login_throttles WHERE locked_until IS NOT NULL')->fetchColumn());
    }

    public function testSuccessfulLoginClearsBothThrottleRows(): void
    {
        $this->createStaffUser();
        $this->throttle->recordFailure('test_staff', self::ADDRESS);
        $this->throttle->recordFailure('obsolete_staff', '192.0.2.99');
        $statement = $this->pdo()->prepare('UPDATE login_throttles SET window_started_at = UTC_TIMESTAMP() - INTERVAL 2 DAY, updated_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE throttle_key IN (?, ?)');
        $statement->execute([hash_hmac('sha256', 'account:obsolete_staff', self::KEY), hash_hmac('sha256', 'address:192.0.2.99', self::KEY)]);
        $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM login_throttles')->fetchColumn());
    }

    public function testInactiveAccountCannotLoginOrContinueAnExistingSession(): void
    {
        $id = $this->createStaffUser(['is_active' => 0]);
        $this->assertApiFailure(fn () => $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS), 401, 'Invalid username or password.');
        $this->pdo()->exec('UPDATE staff_users SET is_active = 1');
        $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS);
        self::assertSame($id, $this->auth->requireUser()['id']);
        $this->pdo()->exec('UPDATE staff_users SET is_active = 0');
        $this->assertApiFailure(fn () => $this->auth->requireUser(), 403);
        self::assertArrayNotHasKey('staff', $_SESSION);
    }

    public function testSessionExpiresAfterThirtyMinutesOfInactivity(): void
    {
        $this->createStaffUser();
        $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS);
        $_SESSION['staff']['last_activity'] = time() - 120;
        $this->auth->requireUser();
        self::assertGreaterThanOrEqual(time() - 1, $_SESSION['staff']['last_activity']);
        $_SESSION['staff']['last_activity'] = time() - 1800;
        $oldToken = Csrf::token();
        $this->assertApiFailure(fn () => $this->auth->requireUser(), 401);
        self::assertArrayNotHasKey('staff', $_SESSION);
        self::assertNotSame($oldToken, Csrf::token());
    }

    public function testMissingOrDeletedStaffCannotUseAProtectedSession(): void
    {
        $this->assertApiFailure(fn () => $this->auth->requireUser(), 401);
        $this->createStaffUser();
        $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS);
        $this->pdo()->exec('DELETE FROM staff_users');
        $this->assertApiFailure(fn () => $this->auth->requireUser(), 401);
        self::assertArrayNotHasKey('staff', $_SESSION);
    }

    public function testLogoutClearsSessionAndRotatesCsrfToken(): void
    {
        $this->createStaffUser();
        $this->auth->attempt('test_staff', 'Correct Horse Battery Staple', self::ADDRESS);
        $oldToken = Csrf::token();
        $oldSession = session_id();
        $this->auth->logout();
        self::assertSame(['csrf_token'], array_keys($_SESSION));
        self::assertNotSame($oldToken, Csrf::token());
        self::assertNotSame($oldSession, session_id());
        $this->assertApiFailure(fn () => $this->auth->requireUser(), 401);
    }

    public function testAuditStoresOnlyNumericVersionMetadata(): void
    {
        $id = $this->createStaffUser();
        (new AuditLogger($this->pdo()))->record($id, null, 'update', ['from_version' => 1, 'to_version' => 2]);
        $metadata = $this->pdo()->query('SELECT metadata FROM audit_log')->fetchColumn();
        self::assertEquals(['from_version' => 1, 'to_version' => 2], json_decode($metadata, true, 512, JSON_THROW_ON_ERROR));
    }

    private function assertApiFailure(callable $operation, int $status, ?string $message = null): void
    {
        try {
            $operation();
            self::fail('Expected an API failure.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status());
            if ($message !== null) {
                self::assertSame($message, $exception->publicMessage());
            }
        }
    }
}
