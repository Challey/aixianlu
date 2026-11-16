<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

/**
 * Public aggregated probe metrics — GET /api/v1/metrics/latest
 *
 * Query: hours (1–168, default 24), isp (电信/联通/移动/其他), name (target name)
 * Data comes from hourly snapshots; no probe IDs, IPs or raw payloads are exposed.
 * Cached for 60s so edge/CDN layers can absorb dashboard traffic.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=60');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_response(['ok' => false, 'error' => 'method not allowed'], 405);
}

$hours = (int) ($_GET['hours'] ?? 24);
if ($hours < 1 || $hours > 168) {
    $hours = 24;
}
$isp = trim((string) ($_GET['isp'] ?? ''));
$name = trim((string) ($_GET['name'] ?? ''));
if (mb_strlen($isp) > 40) {
    $isp = mb_substr($isp, 0, 40);
}
if (mb_strlen($name) > 64) {
    $name = mb_substr($name, 0, 64);
}

$rows = probe_latest_metrics($hours, $isp, $name);

json_response([
    'ok' => true,
    'generated_at' => gmdate('c'),
    'window_hours' => $hours,
    'filters' => ['isp' => $isp, 'name' => $name],
    'source' => 'hourly snapshots of open-source probe reports',
    'rows' => $rows,
]);
