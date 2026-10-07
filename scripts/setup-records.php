<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/record-store.php';
$db = recordDatabase(false);
$name = getenv('PDS_DB_NAME') ?: 'pds';
$db->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$name`");
$db->exec(file_get_contents(dirname(__DIR__) . '/database/records.sql'));
echo "PDS record storage is ready.\n";
