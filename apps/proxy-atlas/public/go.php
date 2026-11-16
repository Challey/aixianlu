<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
if ($slug === '' && isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (preg_match('#^/go/([a-z0-9-]+)/?$#', $path, $matches)) {
        $slug = $matches[1];
    }
}

$provider = $slug !== '' ? provider_by_slug($slug) : null;

if (!$provider) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>链接不存在</title></head><body style="font-family:-apple-system,sans-serif;padding:3rem;text-align:center"><h1>链接不存在</h1><p><a href="/providers/">返回机场列表</a></p></body></html>';
    exit;
}

$target = trim((string) ($provider['affiliate_url'] ?: $provider['official_url']));
$src = (string) ($_GET['src'] ?? '');
if ($src === 'buy' && provider_has_checkout($provider)) {
    $target = provider_checkout_url($provider);
}
if ($target === '' || !preg_match('#^https?://#i', $target)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $name = e($provider['name']);
    $detail = '/providers/' . e($provider['slug']) . '/';
    $source = e($provider['source_review_url'] ?: $provider['source_home_url']);
    echo <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$name} — 官网地址待补录</title>
<style>
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Noto Sans SC",sans-serif;background:#f5f5f7;color:#1d1d1f;display:grid;place-items:center;min-height:100vh}
.card{max-width:520px;margin:1.5rem;padding:2rem;background:#fff;border-radius:20px;box-shadow:0 8px 28px rgba(0,0,0,.07);text-align:center}
h1{font-size:1.4rem;letter-spacing:-.03em;margin:0 0 .6rem}
p{color:#6e6e73;font-size:.9rem;line-height:1.6;margin:0 0 1.2rem}
a{display:inline-block;margin:.2rem;padding:.55rem 1.1rem;border-radius:980px;background:#0071e3;color:#fff;text-decoration:none;font-size:.85rem}
a.ghost{background:transparent;color:#0071e3;border:1px solid rgba(0,0,0,.15)}
</style>
</head>
<body>
<div class="card">
<h1>{$name} 的官网地址待补录</h1>
<p>我们尚未核对到该机场的官方网站，因此暂时不提供外跳，避免把你带到错误页面。可以先查看详情或评测来源。</p>
<a href="{$detail}">查看详情</a>
<a class="ghost" href="{$source}" rel="nofollow noopener">评测来源</a>
</div>
</body>
</html>
HTML;
    exit;
}

$agentId = null;
$agentCode = trim((string) ($_GET['a'] ?? $_GET['ref'] ?? ''));
if ($agentCode !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $agentCode)) {
    try {
        $stmt = db()->prepare('SELECT id FROM agents WHERE code = ? LIMIT 1');
        $stmt->execute([$agentCode]);
        $found = $stmt->fetchColumn();
        $agentId = $found !== false ? (int) $found : null;
    } catch (Throwable $e) {
        $agentId = null;
    }
}

$subid = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['subid'] ?? '')) ?? '', 0, 64);
$campaign = substr(trim((string) ($_GET['c'] ?? $_GET['campaign'] ?? '')), 0, 80);
if ($campaign === '' && $src === 'buy') {
    $campaign = 'buy';
}
$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
$device = 'desktop';
if (preg_match('/iPad|Tablet/i', $ua)) {
    $device = 'tablet';
} elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) {
    $device = 'mobile';
}

try {
    $stmt = db()->prepare(
        'INSERT INTO clicks (provider_id, agent_id, subid, campaign, ip_hash, ua, referer, device)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$provider['id'], $agentId, $subid, $campaign, client_ip_hash(), $ua, $referer, $device]);
} catch (Throwable $e) {
    error_log('click log failed: ' . $e->getMessage());
}

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Location: ' . $target, true, 302);
exit;
