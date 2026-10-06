<?php

declare(strict_types=1);

namespace Pds;

final class JsonResponse
{
    public static function payload(bool $ok, mixed $data, string $message, array $errors): array
    {
        return compact('ok', 'data', 'message', 'errors');
    }

    public static function send(int $status, bool $ok, mixed $data, string $message, array $errors = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (function_exists('app_request_id')) {
            header('X-Request-ID: ' . app_request_id());
        }
        echo json_encode(self::payload($ok, $data, $message, $errors), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
