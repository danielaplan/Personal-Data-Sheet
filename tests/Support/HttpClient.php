<?php

declare(strict_types=1);

namespace Pds\Tests\Support;

use RuntimeException;

final class HttpClient
{
    private ?string $cookie = null;

    public function __construct(private readonly string $baseUrl) {}

    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        if ($this->cookie !== null) {
            $headers['Cookie'] = $this->cookie;
        }
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $response = file_get_contents($this->baseUrl . $path, false, $context);
        if ($response === false) {
            throw new RuntimeException('The test HTTP server did not respond.');
        }
        $responseHeaders = [];
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $line, $matches)) {
                $status = (int) $matches[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $responseHeaders[$name] = trim($value);
                if ($name === 'set-cookie') {
                    $this->cookie = explode(';', trim($value), 2)[0];
                }
            }
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'json' => json_decode($response, true), 'body' => $response];
    }

    public function json(string $method, string $path, array $payload, ?string $csrf = null): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($csrf !== null) {
            $headers['X-CSRF-Token'] = $csrf;
        }
        return $this->request($method, $path, json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR), $headers);
    }
}
