<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $options = [];
    for ($index = 1; $index < $argc; ++$index) {
        [$name, $value] = array_pad(explode('=', $argv[$index], 2), 2, null);
        if (!in_array($name, ['--username', '--display-name', '--password-stdin'], true) || array_key_exists($name, $options)) {
            throw new InvalidArgumentException('Use --username, --display-name, and --password-stdin or PDS_STAFF_PASSWORD. Password arguments are not accepted.');
        }
        if ($name === '--password-stdin') {
            if ($value !== null) {
                throw new InvalidArgumentException('--password-stdin does not take a value.');
            }
            $options[$name] = true;
            continue;
        }
        if ($value === null && isset($argv[$index + 1]) && !str_starts_with($argv[$index + 1], '--')) {
            $value = $argv[++$index];
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Both --username and --display-name require values.');
        }
        $options[$name] = $value;
    }
    $username = mb_strtolower(trim($options['--username'] ?? ''), 'UTF-8');
    $displayName = trim($options['--display-name'] ?? '');
    if ($username === '' || mb_strlen($username, 'UTF-8') > 100 || !mb_check_encoding($username, 'UTF-8') || str_contains($username, "\0")
        || $displayName === '' || mb_strlen($displayName, 'UTF-8') > 200 || !mb_check_encoding($displayName, 'UTF-8') || str_contains($displayName, "\0")) {
        throw new InvalidArgumentException('Provide a username of 1–100 characters and display name of 1–200 characters.');
    }
    $password = isset($options['--password-stdin']) ? stream_get_contents(STDIN, 4097) : getenv('PDS_STAFF_PASSWORD');
    if (isset($options['--password-stdin']) && is_string($password)) {
        $password = preg_replace('/\r?\n\z/', '', $password);
    }
    if (!is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Supply a nonempty password of at most 72 bytes through stdin or PDS_STAFF_PASSWORD.');
    }

    require dirname(__DIR__) . '/config/bootstrap.php';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password);
    $statement = app_pdo()->prepare('INSERT INTO staff_users (username, display_name, password_hash) VALUES (?, ?, ?)');
    $statement->execute([$username, $displayName, $hash]);
    fwrite(STDOUT, "Staff account created.\n");
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (PDOException $exception) {
    fwrite(STDERR, ($exception->errorInfo[1] ?? null) === 1062 ? "That username is already in use.\n" : "Unable to create the account. Check the database configuration.\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Unable to create the account. Check the PHP and application configuration.\n");
    exit(1);
}
