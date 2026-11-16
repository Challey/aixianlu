<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server:
 *   php -S 127.0.0.1:8080 -t public public/router.php
 *
 * Production should use nginx/apache rules documented in README.
 */

$path = urldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$root = __DIR__;

if ($path !== '/' && is_file($root . $path)) {
    return false;
}

if (is_dir($root . $path)) {
    if (substr($path, -1) !== '/') {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . $path . '/' . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
    if (is_file($root . $path . 'index.php') || is_file($root . $path . 'index.html')) {
        return false;
    }
}

if ($path === '/go' || $path === '/go/') {
    header('Location: /providers/', true, 302);
    exit;
}

if (preg_match('#^/go/([a-z0-9-]+)/?$#', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    require $root . '/go.php';
    return true;
}

if (preg_match('#^/api/agent-apply/?$#', $path)) {
    require $root . '/api/agent-apply.php';
    return true;
}

if (preg_match('#^/api/v1/metrics/report/?$#', $path)) {
    require $root . '/api/metrics-report.php';
    return true;
}

if (preg_match('#^/api/v1/metrics/latest/?$#', $path)) {
    require $root . '/api/metrics-latest.php';
    return true;
}

if ($path === '/admin') {
    header('Location: /admin/', true, 301);
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
if (is_file($root . '/404.html')) {
    readfile($root . '/404.html');
} else {
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>404</title><body><h1>404</h1><p><a href="/">返回首页</a></p></body></html>';
}
return true;
