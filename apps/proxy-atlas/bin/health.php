#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db();
$providers = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
            SUM(CASE WHEN official_url = '' THEN 1 ELSE 0 END) AS no_official,
            SUM(CASE WHEN affiliate_url = '' THEN 1 ELSE 0 END) AS no_affiliate,
            SUM(CASE WHEN affiliate_status = 'active' THEN 1 ELSE 0 END) AS active_affiliate
     FROM providers"
)->fetch();
$news = $pdo->query(
    "SELECT COUNT(*) AS total, MAX(published_at) AS latest
     FROM ai_news WHERE status = 'published'"
)->fetch();
$news24 = (int) $pdo->query(
    "SELECT COUNT(*) FROM ai_news WHERE status = 'published' AND published_at >= datetime('now', '-1 day')"
)->fetchColumn();
$dbPath = db_path();
$dbSize = is_file($dbPath) ? filesize($dbPath) : 0;
$buildFile = APP_ROOT . '/public/index.html';
$buildAge = is_file($buildFile) ? time() - filemtime($buildFile) : null;
$latestNewsAge = !empty($news['latest']) ? time() - strtotime((string) $news['latest'] . ' UTC') : null;

$issues = [];
if ((int) $providers['published'] === 0) {
    $issues[] = '没有已发布的机场';
}
if ($buildAge === null) {
    $issues[] = '尚未构建静态站';
} elseif ($buildAge > 72 * 3600) {
    $issues[] = '静态站超过 72 小时未重建';
}
if ($latestNewsAge === null) {
    $issues[] = '没有任何 AI 新闻';
} elseif ($latestNewsAge > 7 * 86400) {
    $issues[] = '最新 AI 新闻超过 7 天';
}
if ((int) $providers['no_official'] > (int) $providers['total'] * 0.3) {
    $issues[] = '超过 30% 的机场缺少官网地址';
}

printf("== AI 线路 health %s ==\n", date('Y-m-d H:i:s'));
printf("providers: %d published / %d total, official missing %d, affiliate missing %d, active %d\n",
    (int) $providers['published'], (int) $providers['total'],
    (int) $providers['no_official'], (int) $providers['no_affiliate'],
    (int) $providers['active_affiliate']);
printf("news: %d total, %d in 24h, latest %s\n", (int) $news['total'], $news24, (string) ($news['latest'] ?: '—'));
printf("build: %s\n", $buildAge === null ? 'missing' : sprintf('%d min ago', (int) round($buildAge / 60)));
printf("db: %s (%.1f KB)\n", $dbPath, $dbSize / 1024);

if ($issues) {
    echo "ISSUES:\n";
    foreach ($issues as $issue) {
        echo "  - {$issue}\n";
    }
    $webhook = trim((string) getenv('ALERT_WEBHOOK_URL'));
    if ($webhook !== '' && preg_match('#^https?://#i', $webhook)) {
        $payload = json_encode([
            'text' => "AI 线路健康检查异常\n- " . implode("\n- ", $issues),
            'issues' => $issues,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_get_contents($webhook, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => (string) $payload,
                'timeout' => 10,
            ],
        ]));
    }
    exit(1);
}

echo "OK\n";
