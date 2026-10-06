<?php

declare(strict_types=1);

namespace Pds;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return self::rotate();
        }

        return $_SESSION['csrf_token'];
    }

    public static function rotate(): string
    {
        return $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function assertValid(?string $candidate): void
    {
        $stored = $_SESSION['csrf_token'] ?? null;
        if (!is_string($candidate) || !is_string($stored) || !hash_equals($stored, $candidate)) {
            throw new ApiException(403, 'Your security token is invalid or expired. Refresh and try again.');
        }
    }
}
