<?php

declare(strict_types=1);

namespace Pds;

use Throwable;

final class Endpoint
{
    public static function run(Request $request, callable $handler): never
    {
        self::respond(static fn () => $handler($request));
    }

    public static function fromGlobals(callable $handler): never
    {
        self::respond(static fn () => $handler(Request::fromGlobals()));
    }

    private static function respond(callable $handler): never
    {
        try {
            $handler();
            throw new \LogicException('Endpoint handler returned without sending a response.');
        } catch (ApiException $exception) {
            JsonResponse::send($exception->status(), false, null, $exception->publicMessage(), $exception->errors());
        } catch (Throwable $exception) {
            $requestId = function_exists('app_request_id') ? app_request_id() : bin2hex(random_bytes(16));
            error_log(sprintf('[%s] %s: %s%s%s', $requestId, $exception::class, $exception->getMessage(), PHP_EOL, $exception->getTraceAsString()));
            JsonResponse::send(500, false, null, 'Unexpected server error. Reference: ' . $requestId);
        }
    }

    public static function protected(Request $request, Auth $auth, bool $requireCsrf, callable $handler): never
    {
        self::run($request, static function (Request $request) use ($auth, $requireCsrf, $handler): void {
            $user = $auth->requireUser();
            if ($requireCsrf) {
                Csrf::assertValid($request->header('X-CSRF-Token'));
            }
            $handler($request, $user);
        });
    }
}
