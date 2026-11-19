<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

/**
 * Public aggregated probe metrics — GET /api/v1/metrics/latest
 *
 * Query: hours (1–168, default 24), isp (电信/联通/移动/其他), name (target name)
 * Data comes from hourly snapshots; no probe IDs, IPs or raw payloads are exposed.
 * Cached for 60s so edge/CDN layers can absorb dashboard traffic.
 *
 * `summary`, `matrix` and `rows` are all produced by the same PHP functions the
 * static build uses, so a visitor, the baked page and this API can never show
 * three different numbers for one window.
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
    'scoring' => [
        'formula' => '50% 可用率 + 20% 可达率 + 30% 延迟一致性(P95 与均值之差)，区域拦截每次 -10（上限 -20）、限流每次 -5（上限 -10）',
        'min_samples_for_confidence' => 5,
        'headline_excludes' => '对照组端点（GitHub / Cloudflare）不参与综合稳定度，只单独给出对照可达率',
        'grades' => ['A' => '≥90 稳定', 'B' => '75–89 可用', 'C' => '60–74 波动', 'D' => '<60 不可靠'],
    ],
    'summary' => probe_status_summary($hours, $isp, $name),
    'matrix' => probe_status_matrix($hours, $isp, $name),
    'rows' => $rows,
]);
