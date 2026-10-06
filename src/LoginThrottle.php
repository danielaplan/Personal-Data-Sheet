<?php

declare(strict_types=1);

namespace Pds;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class LoginThrottle
{
    private const WINDOW_SECONDS = 900;
    private const MAX_FAILURES = 5;

    public function __construct(private readonly PDO $pdo, private readonly string $appKey)
    {
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY must contain at least 32 bytes.');
        }
    }

    public function assertAllowed(string $normalizedUsername, string $sourceAddress): void
    {
        $this->prune();
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM login_throttles WHERE throttle_key IN (?, ?) AND locked_until > UTC_TIMESTAMP(6) LIMIT 1',
        );
        $statement->execute($this->keys($normalizedUsername, $sourceAddress));
        if ($statement->fetchColumn() !== false) {
            throw new ApiException(429, 'Too many login attempts. Try again later.');
        }
    }

    public function recordFailure(string $normalizedUsername, string $sourceAddress): void
    {
        $this->pdo->beginTransaction();
        try {
            // Use the same key order across requests to avoid opposite row-lock orders.
            foreach ($this->keys($normalizedUsername, $sourceAddress) as $key) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO login_throttles (throttle_key, failed_attempts, window_started_at)
                     VALUES (?, 0, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE throttle_key = throttle_key',
                );
                $insert->execute([$key]);
                $select = $this->pdo->prepare('SELECT * FROM login_throttles WHERE throttle_key = ? FOR UPDATE');
                $select->execute([$key]);
                $row = $select->fetch();
                if ($row === false) {
                    throw new RuntimeException('Throttle row was not available.');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                if ($row['locked_until'] !== null && new DateTimeImmutable($row['locked_until'], new DateTimeZone('UTC')) > $now) {
                    continue;
                }
                $started = new DateTimeImmutable($row['window_started_at'], new DateTimeZone('UTC'));
                $expired = $row['locked_until'] !== null || $started->getTimestamp() <= $now->getTimestamp() - self::WINDOW_SECONDS;
                $failures = $expired ? 1 : min(self::MAX_FAILURES, (int) $row['failed_attempts'] + 1);
                $lockedUntil = $failures >= self::MAX_FAILURES ? $now->modify('+15 minutes')->format('Y-m-d H:i:s.u') : null;
                $update = $this->pdo->prepare(
                    'UPDATE login_throttles SET failed_attempts = ?, window_started_at = ?, locked_until = ?, updated_at = UTC_TIMESTAMP(6) WHERE throttle_key = ?',
                );
                $update->execute([$failures, ($expired ? $now : $started)->format('Y-m-d H:i:s.u'), $lockedUntil, $key]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function clear(string $normalizedUsername, string $sourceAddress): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_throttles WHERE throttle_key IN (?, ?)');
        $statement->execute($this->keys($normalizedUsername, $sourceAddress));
        $this->prune();
    }

    private function keys(string $normalizedUsername, string $sourceAddress): array
    {
        $keys = [
            hash_hmac('sha256', 'account:' . mb_strtolower(trim($normalizedUsername), 'UTF-8'), $this->appKey),
            hash_hmac('sha256', 'address:' . $sourceAddress, $this->appKey),
        ];
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function prune(): void
    {
        $this->pdo->exec(
            'DELETE FROM login_throttles
             WHERE updated_at < UTC_TIMESTAMP(6) - INTERVAL 24 HOUR
             AND COALESCE(locked_until, window_started_at + INTERVAL 15 MINUTE) < UTC_TIMESTAMP(6) - INTERVAL 24 HOUR',
        );
    }
}
