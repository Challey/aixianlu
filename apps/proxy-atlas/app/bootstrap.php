<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

date_default_timezone_set(getenv('PROXY_TZ') ?: 'Asia/Shanghai');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/ai_repository.php';
require_once __DIR__ . '/probe_repository.php';

if (is_file(APP_ROOT . '/.env')) {
    foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}
