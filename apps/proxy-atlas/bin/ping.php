#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Submit the current sitemap to IndexNow (Bing/Yandex/Seznam) and, optionally,
 * Baidu's search resource push API.
 *
 * Google retired the sitemap ping endpoint in 2023; Google discovers new pages
 * through the sitemap, internal links and normal crawling. IndexNow covers Bing.
 */

$sitemapFile = APP_ROOT . '/public/sitemap.xml';
if (!is_file($sitemapFile)) {
    fwrite(STDERR, "sitemap not found: {$sitemapFile} (run php bin/build.php first)\n");
    exit(1);
}

$xml = (string) file_get_contents($sitemapFile);
preg_match_all('#<loc>(.*?)</loc>#i', $xml, $matches);
$urls = array_values(array_unique(array_map(
    static fn (string $url): string => html_entity_decode(trim($url), ENT_QUOTES | ENT_XML1, 'UTF-8'),
    $matches[1] ?? []
)));

if (!$urls) {
    fwrite(STDERR, "no URLs found in sitemap\n");
    exit(1);
}

$site = site_url('/');
$host = (string) parse_url($site, PHP_URL_HOST);
printf("sitemap: %d URLs, host: %s\n", count($urls), $host);

function http_post(string $url, string $body, array $headers = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $started = microtime(true);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [
        'status' => $status,
        'body' => $response === false ? '' : substr($response, 0, 500),
        'ms' => (int) ((microtime(true) - $started) * 1000),
    ];
}

// IndexNow: the key file proves domain ownership.
$indexNowKey = trim((string) getenv('INDEXNOW_KEY'));
if ($indexNowKey !== '' && preg_match('/^[A-Za-z0-9-]{8,128}$/', $indexNowKey)) {
    $keyFile = APP_ROOT . '/public/' . $indexNowKey . '.txt';
    if (!is_file($keyFile) || trim((string) file_get_contents($keyFile)) !== $indexNowKey) {
        file_put_contents($keyFile, $indexNowKey);
        echo "wrote IndexNow key file: public/{$indexNowKey}.txt\n";
    }
    $keyLocation = rtrim($site, '/') . '/' . $indexNowKey . '.txt';
    $submitted = 0;
    foreach (array_chunk($urls, 500) as $chunk) {
        $payload = json_encode([
            'host' => $host,
            'key' => $indexNowKey,
            'keyLocation' => $keyLocation,
            'urlList' => array_values($chunk),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = http_post('https://api.indexnow.org/indexnow', (string) $payload, [
            'Content-Type: application/json; charset=utf-8',
        ]);
        printf("IndexNow: HTTP %d, %d URLs, %d ms %s\n", $result['status'], count($chunk), $result['ms'], $result['body'] !== '' ? '- ' . $result['body'] : '');
        if ($result['status'] >= 200 && $result['status'] < 300) {
            $submitted += count($chunk);
        }
    }
    printf("IndexNow submitted: %d/%d\n", $submitted, count($urls));
} else {
    echo "IndexNow skipped: set INDEXNOW_KEY in .env\n";
}

// Baidu push: needs a verified site and token from ziyuan.baidu.com.
$baiduToken = trim((string) getenv('BAIDU_PUSH_TOKEN'));
if ($baiduToken !== '') {
    $endpoint = 'http://data.zz.baidu.com/urls?site=' . rawurlencode(rtrim($site, '/')) . '&token=' . rawurlencode($baiduToken);
    foreach (array_chunk($urls, 1000) as $chunk) {
        $result = http_post($endpoint, implode("\n", $chunk), [
            'Content-Type: text/plain',
        ]);
        printf("Baidu: HTTP %d, %d URLs, %d ms %s\n", $result['status'], count($chunk), $result['ms'], $result['body'] !== '' ? '- ' . $result['body'] : '');
    }
} else {
    echo "Baidu skipped: set BAIDU_PUSH_TOKEN in .env\n";
}
