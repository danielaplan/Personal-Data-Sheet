<?php

declare(strict_types=1);

namespace Pds\Tests\Unit;

use Pds\ApiException;
use Pds\JsonResponse;
use Pds\Request;
use PHPUnit\Framework\TestCase;

final class HttpFoundationTest extends TestCase
{
    public function testResponseEnvelopeAlwaysHasFourKeys(): void
    {
        self::assertSame(
            [
                'ok' => true,
                'data' => ['id' => 7],
                'message' => 'Created',
                'errors' => [],
            ],
            JsonResponse::payload(true, ['id' => 7], 'Created', []),
        );
    }

    public function testJsonRejectsUnsupportedContentType(): void
    {
        $request = new Request('POST', 'text/plain', '{}', [], []);

        try {
            $request->json();
            self::fail('Expected unsupported content type to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertSame('Content-Type must be application/json.', $exception->publicMessage());
        }
    }

    public function testJsonRejectsMalformedInput(): void
    {
        $request = new Request('POST', 'application/json', '{', [], []);

        try {
            $request->json();
            self::fail('Expected malformed JSON to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertSame('Malformed JSON request body.', $exception->publicMessage());
        }
    }

    public function testJsonRejectsOversizedBodies(): void
    {
        $request = new Request('POST', 'application/json', str_repeat('x', 1_048_577), [], []);

        try {
            $request->json();
            self::fail('Expected oversized JSON to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertSame('Request body exceeds the 1 MiB limit.', $exception->publicMessage());
        }
    }

    public function testJsonAcceptsAnObject(): void
    {
        $request = new Request(
            'POST',
            'application/json; charset=utf-8',
            '{"name":"Ada"}',
            ['X-CSRF-Token' => 'abc'],
            ['page' => '2'],
        );

        self::assertSame(['name' => 'Ada'], $request->json());
        self::assertSame('abc', $request->header('x-csrf-token'));
        self::assertSame(2, $request->queryPositiveInt('page', 1));
    }

    public function testJsonRejectsAList(): void
    {
        $request = new Request('POST', 'application/json', '[1,2]', [], []);

        $this->expectException(ApiException::class);
        $request->json();
    }

    public function testMethodAndPositiveQueryValidationUseBadRequest(): void
    {
        $request = new Request('GET', null, '', [], ['page' => 'zero']);

        try {
            $request->requireMethod('POST');
            self::fail('Expected method mismatch.');
        } catch (ApiException $exception) {
            self::assertSame(405, $exception->status());
        }

        try {
            $request->queryPositiveInt('page', 1);
            self::fail('Expected invalid positive integer.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
        }
    }
}
