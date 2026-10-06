<?php

declare(strict_types=1);

use Pds\ApiException;
use Pds\Csrf;

require __DIR__ . '/config/bootstrap.php';

header('Cache-Control: no-store');
if (isset($_SESSION['staff'])) {
    try {
        app_auth()->requireUser();
        header('Location: index.php', true, 302);
        exit;
    } catch (ApiException $exception) {
        if (!in_array($exception->status(), [401, 403], true)) {
            throw $exception;
        }
    }
}
$csrfToken = htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title>Staff sign in | Personal Data Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous" defer></script>
    <script src="assets/login.js" defer></script>
</head>
<body class="login-page">
    <main class="login-panel" aria-labelledby="login-title">
        <p class="login-eyebrow">PERSONAL DATA SHEET</p>
        <h1 id="login-title" class="h3 mb-2">Staff sign in</h1>
        <p class="text-muted mb-4">Use your staff account to access personnel records.</p>
        <div id="login-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <noscript><p class="alert alert-warning">Enable JavaScript to sign in.</p></noscript>
        <form id="login-form" action="login.php" method="post" aria-describedby="login-help">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input id="username" name="username" class="form-control" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="100" required aria-describedby="username-error">
                <div id="username-error" class="invalid-feedback"></div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input id="password" name="password" class="form-control" type="password" autocomplete="current-password" required aria-describedby="password-error">
                <div id="password-error" class="invalid-feedback"></div>
            </div>
            <button id="login-submit" class="btn btn-primary w-100" type="submit" disabled>Sign in</button>
            <p id="login-status" class="visually-hidden" role="status" aria-live="polite"></p>
        </form>
        <p id="login-help" class="small text-muted mt-4 mb-0">Need an account or help signing in? Contact your administrator.</p>
    </main>
</body>
</html>
