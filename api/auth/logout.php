<?php

declare(strict_types=1);

use Pds\ApiException;
use Pds\Csrf;
use Pds\Endpoint;
use Pds\JsonResponse;
use Pds\Request;

require dirname(__DIR__, 2) . '/config/bootstrap.php';

Endpoint::fromGlobals(static function (Request $request): void {
    $auth = app_auth();
    Endpoint::protected($request, $auth, requireCsrf: true, handler: static function (Request $request, array $user) use ($auth): void {
        $request->requireMethod('POST');
        if ($request->json() !== []) {
            throw new ApiException(400, 'Logout does not accept additional fields.');
        }
        $auth->logout();
        JsonResponse::send(200, true, ['csrf_token' => Csrf::token()], 'Signed out successfully.');
    });
});
