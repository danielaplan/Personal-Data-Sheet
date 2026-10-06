<?php

declare(strict_types=1);

namespace Pds;

use PDO;
use RuntimeException;
use Throwable;

final class Auth
{
    private const INACTIVITY_SECONDS = 1800;
    // A fixed valid hash makes unknown usernames perform the same password check.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(string $username, string $password, string $sourceAddress): array
    {
        $username = mb_strtolower(trim($username), 'UTF-8');
        $this->throttle->assertAllowed($username, $sourceAddress);
        $statement = $this->pdo->prepare('SELECT id, display_name, password_hash, is_active FROM staff_users WHERE username = ?');
        $statement->execute([$username]);
        $user = $statement->fetch();
        $passwordValid = password_verify($password, $user === false ? self::DUMMY_HASH : $user['password_hash']);
        if ($user === false || (int) $user['is_active'] !== 1 || !$passwordValid || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->throttle->recordFailure($username, $sourceAddress);
            $this->audit->record($user === false ? null : (int) $user['id'], null, 'login_failure');
            throw new ApiException(401, 'Invalid username or password.');
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE staff_users SET last_login_at = UTC_TIMESTAMP(6) WHERE id = ?');
            $update->execute([$user['id']]);
            $this->throttle->clear($username, $sourceAddress);
            $this->audit->record((int) $user['id'], null, 'login_success');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->establish($user);
        return ['id' => (int) $user['id'], 'display_name' => (string) $user['display_name']];
    }

    public function establish(array $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the staff session.');
        }
        $_SESSION = ['staff' => [
            'id' => (int) $user['id'],
            'display_name' => (string) $user['display_name'],
            'last_activity' => time(),
        ]];
        Csrf::rotate();
    }

    public function requireUser(): array
    {
        $staff = $_SESSION['staff'] ?? null;
        if (!is_array($staff) || !isset($staff['id'], $staff['last_activity']) || !is_int($staff['id']) || $staff['id'] < 1 || !is_int($staff['last_activity'])) {
            if ($staff !== null) {
                $this->logout();
            }
            throw new ApiException(401, 'Please sign in to continue.');
        }
        if (time() - $staff['last_activity'] >= self::INACTIVITY_SECONDS) {
            $this->logout();
            throw new ApiException(401, 'Your session has expired. Please sign in again.');
        }
        $statement = $this->pdo->prepare('SELECT id, display_name, is_active FROM staff_users WHERE id = ?');
        $statement->execute([$staff['id']]);
        $user = $statement->fetch();
        if ($user === false) {
            $this->logout();
            throw new ApiException(401, 'Please sign in to continue.');
        }
        if ((int) $user['is_active'] !== 1) {
            $this->logout();
            throw new ApiException(403, 'Your staff account is disabled.');
        }
        $_SESSION['staff'] = ['id' => (int) $user['id'], 'display_name' => (string) $user['display_name'], 'last_activity' => time()];
        return ['id' => (int) $user['id'], 'display_name' => (string) $user['display_name']];
    }

    public function requirePageUser(string $loginPath = 'login.php'): array
    {
        header('Cache-Control: no-store');
        try {
            return $this->requireUser();
        } catch (ApiException $exception) {
            if (!in_array($exception->status(), [401, 403], true)) {
                throw $exception;
            }
            header('Location: ' . $loginPath, true, 302);
            exit;
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
            throw new RuntimeException('Unable to destroy the staff session.');
        }
        session_id('');
        if (!session_start()) {
            throw new RuntimeException('Unable to start an anonymous session.');
        }
        Csrf::rotate();
    }

    public static function sourceAddress(array $server, ?string $trustedProxy = null): string
    {
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        if ($trustedProxy !== null && $trustedProxy !== '' && $remote === $trustedProxy && is_string($server['HTTP_X_FORWARDED_FOR'] ?? null)) {
            $first = trim(explode(',', $server['HTTP_X_FORWARDED_FOR'], 2)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        return $remote;
    }
}
