<?php

declare(strict_types=1);

use Pds\ApiException;
use Pds\Auth;
use Pds\Csrf;
use Pds\Endpoint;
use Pds\JsonResponse;
use Pds\Request;

require dirname(__DIR__, 2) . '/config/bootstrap.php';

Endpoint::fromGlobals(static function (Request $request): void {
    $request->requireMethod('POST');
    Csrf::assertValid($request->header('X-CSRF-Token'));
    $input = $request->json();
    if (array_diff(array_keys($input), ['username', 'password']) !== []) {
        throw new ApiException(400, 'Unexpected login fields.');
    }
    if (!is_string($input['username'] ?? null) || !is_string($input['password'] ?? null)) {
        throw new ApiException(422, 'Enter your username and password.', ['username' => 'Username is required.', 'password' => 'Password is required.']);
    }
    $username = trim($input['username']);
    if ($username === '' || mb_strlen($username, 'UTF-8') > 100 || str_contains($username, "\0")) {
        throw new ApiException(422, 'Enter a valid username.', ['username' => 'Enter a username of 1 to 100 characters.']);
    }
    $trustedProxy = (string) ($_ENV['TRUSTED_PROXY'] ?? getenv('TRUSTED_PROXY') ?: '');
    $user = app_auth()->attempt($username, $input['password'], Auth::sourceAddress($_SERVER, $trustedProxy));
    JsonResponse::send(200, true, ['user' => $user, 'csrf_token' => Csrf::token()], 'Signed in successfully.');
});
