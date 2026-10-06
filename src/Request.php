<?php

declare(strict_types=1);

namespace Pds;

use JsonException;
use stdClass;

final class Request
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private readonly string $method,
        private readonly ?string $contentType,
        private readonly string $body,
        private readonly array $headers,
        private readonly array $query,
    ) {}

    public static function fromGlobals(): self
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($length !== null && ctype_digit((string) $length) && (float) $length > self::MAX_BODY_BYTES) {
            throw new ApiException(400, 'Request body exceeds the 1 MiB limit.');
        }

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new ApiException(400, 'Unable to read request body.');
        }
        try {
            $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            throw new ApiException(400, 'Unable to read request body.');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new ApiException(400, 'Request body exceeds the 1 MiB limit.');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : null,
            $body,
            $headers,
            $_GET,
        );
    }

    public function requireMethod(string $method): void
    {
        if (strtoupper($this->method) !== strtoupper($method)) {
            throw new ApiException(405, 'Method not allowed.');
        }
    }

    public function json(int $maxBytes = self::MAX_BODY_BYTES): array
    {
        $mediaType = strtolower(trim(explode(';', $this->contentType ?? '', 2)[0]));
        if ($mediaType !== 'application/json') {
            throw new ApiException(400, 'Content-Type must be application/json.');
        }
        if (strlen($this->body) > $maxBytes) {
            throw new ApiException(400, 'Request body exceeds the 1 MiB limit.');
        }

        try {
            $value = json_decode($this->body, false, 512, JSON_THROW_ON_ERROR);
            if (!$value instanceof stdClass) {
                throw new ApiException(400, 'JSON request body must be an object.');
            }
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException(400, 'Malformed JSON request body.', previous: $exception);
        }
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_string($value) ? $value : null;
            }
        }
        return null;
    }

    public function queryPositiveInt(string $name, int $default, int $max = PHP_INT_MAX): int
    {
        $value = $this->query[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new ApiException(400, "Invalid {$name} query parameter.");
        }
        $number = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($number === false) {
            throw new ApiException(400, "Invalid {$name} query parameter.");
        }
        return $number;
    }

    public function queryString(string $name, string $default = '', int $maxLength = 100): string
    {
        $value = $this->query[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new ApiException(400, "Invalid {$name} query parameter.");
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new ApiException(400, "Invalid {$name} query parameter.");
        }
        return $value;
    }
}
