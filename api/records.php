<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/record-store.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
function positiveInteger(mixed $value): int {
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($result === false) respond(['message' => 'A valid record ID, version, or page is required.'], 400);
    return $result;
}
function validateRecord(mixed $input): array {
    if (!is_array($input) || array_is_list($input)) respond(['message' => 'Provide the form fields as an object.'], 422);
    $schema = json_decode(file_get_contents(__DIR__ . '/field-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $result = []; $errors = [];
    foreach ($schema as $key => $type) {
        $value = $input[$key] ?? '';
        if (!is_string($value)) { $errors[$key] = 'Enter a text value.'; continue; }
        $value = trim($value);
        $result[$key] = $value;
        if (strlen($value) > 2000 || mb_strlen($value) > 500) $errors[$key] = 'Use 500 characters or fewer.';
        if (in_array($key, ['surname', 'firstname'], true) && $value === '') $errors[$key] = 'This field is required.';
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[$key] = 'Enter a valid email address.';
        if ($type === 'date' && $value !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-31') $errors[$key] = 'Enter a valid date.';
        }
        if ($type === 'number' && $value !== '' && (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0)) $errors[$key] = 'Enter a non-negative number.';
    }
    foreach (['sexatbirth' => ['', 'Male', 'Female'], 'bloodtype' => ['', 'O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'], 'citizenship' => ['', 'Filipino', 'Dual Citizen']] as $key => $allowed) {
        if (!in_array($result[$key] ?? '', $allowed, true)) $errors[$key] = 'Choose one of the available options.';
    }
    if ($errors) respond(['message' => 'Check the highlighted form fields.', 'errors' => $errors], 422);
    return $result;
}

try {
    session_start(['use_strict_mode' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    $_SESSION['pds_csrf'] ??= bin2hex(random_bytes(32));
    $token = $_SESSION['pds_csrf'];
    session_write_close();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'session') respond(['csrf' => $token]);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
        header('Allow: GET, POST, PUT, DELETE'); respond(['message' => 'Method not allowed.'], 405);
    }
    if ($method !== 'GET') {
        if (!hash_equals($token, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['message' => 'Your session changed. Reload the page before saving.'], 403);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) respond(['message' => 'Send a JSON request.'], 415);
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if (strlen($raw) > 262144) respond(['message' => 'The record is too large.'], 413);
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { respond(['message' => 'Invalid JSON request.'], 400); }
        if (!is_array($body)) respond(['message' => 'Invalid request body.'], 400);
    }
    $db = recordDatabase();
    if ($method === 'GET' && isset($_GET['id'])) {
        $query = $db->prepare('SELECT id, data, version FROM pds_records WHERE id = ?');
        $query->execute([positiveInteger($_GET['id'])]);
        $record = $query->fetch();
        if (!$record) respond(['message' => 'This record no longer exists.'], 404);
        respond(['record' => ['id' => (int)$record['id'], 'version' => (int)$record['version'], 'fields' => json_decode($record['data'], true, 512, JSON_THROW_ON_ERROR)]]);
    }
    if ($method === 'GET') {
        $search = $_GET['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100) respond(['message' => 'Use a search of 100 characters or fewer.'], 400);
        $page = positiveInteger($_GET['page'] ?? 1); $size = 10;
        $where = ''; $params = [];
        if (trim($search) !== '') {
            $where = " WHERE CONCAT_WS(' ', surname, firstname, middlename, mobile, email) LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
        }
        $count = $db->prepare('SELECT COUNT(*) FROM pds_records' . $where); $count->execute($params);
        $total = (int)$count->fetchColumn(); $pages = max(1, (int)ceil($total / $size)); $page = min($page, $pages);
        $offset = ($page - 1) * $size;
        $query = $db->prepare('SELECT id, surname, firstname, middlename, dateofbirth, mobile, email, version, updated_at FROM pds_records' . $where . " ORDER BY updated_at DESC, id DESC LIMIT $size OFFSET $offset");
        $query->execute($params);
        respond(['records' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }
    if ($method === 'DELETE') {
        $query = $db->prepare('DELETE FROM pds_records WHERE id = ? AND version = ?');
        $query->execute([positiveInteger($_GET['id'] ?? null), positiveInteger($body['version'] ?? null)]);
        if (!$query->rowCount()) respond(['message' => 'This record changed or was deleted. Refresh the list and try again.'], 409);
        respond(['message' => 'Record deleted.']);
    }
    $fields = validateRecord($body['fields'] ?? null);
    $values = [$fields['surname'], $fields['firstname'], $fields['middlename'], $fields['dateofbirth'] ?: null, $fields['mobile'], $fields['email'], json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    if ($method === 'POST') {
        $query = $db->prepare('INSERT INTO pds_records (surname, firstname, middlename, dateofbirth, mobile, email, data) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $query->execute($values); $id = (int)$db->lastInsertId(); $version = 1;
    } else {
        $id = positiveInteger($_GET['id'] ?? null); $version = positiveInteger($body['version'] ?? null);
        $query = $db->prepare('UPDATE pds_records SET surname = ?, firstname = ?, middlename = ?, dateofbirth = ?, mobile = ?, email = ?, data = ?, version = version + 1 WHERE id = ? AND version = ?');
        $query->execute([...$values, $id, $version]);
        if (!$query->rowCount()) respond(['message' => 'This record changed or was deleted. Reload it from the list before editing.'], 409);
        $version++;
    }
    respond(['message' => 'Record saved.', 'record' => ['id' => $id, 'version' => $version, 'fields' => $fields]], $method === 'POST' ? 201 : 200);
} catch (PDOException $e) {
    error_log('PDS database error: ' . $e->getMessage());
    respond(['message' => 'Record storage is unavailable. Check MySQL and run the database setup. Your entries have not been cleared.'], 503);
} catch (Throwable $e) {
    error_log('PDS API error: ' . $e->getMessage());
    respond(['message' => 'Unable to complete the request. Please try again.'], 500);
}
