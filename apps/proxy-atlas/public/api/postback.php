<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

/**
 * Server-to-server conversion postback.
 *
 * URL: https://aixianlu.com/api/postback.php
 * Auth: POSTBACK_TOKEN (query/body `token`) must match .env.
 * Fields:
 *   provider   required, provider slug
 *   commission required, number
 *   amount     optional, order amount
 *   subid      optional, your tracking id
 *   status     optional, pending|paid|rejected
 *   date       optional, YYYY-MM-DD or ISO datetime
 *   note       optional
 *
 * Response: JSON {ok: true, id: 123} or {ok: false, error: "..."}
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$json = [];
$raw = file_get_contents('php://input');
if ($raw !== false && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $json = $decoded;
    }
}

$input = array_merge($_GET, $_POST, $json);
$expected = trim((string) getenv('POSTBACK_TOKEN'));
if ($expected === '') {
    $expected = trim(setting('postback_token', ''));
}
$provided = (string) ($input['token'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = strtolower(trim((string) ($input['provider'] ?? $input['slug'] ?? '')));
$stmt = db()->prepare('SELECT id FROM providers WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$providerId = (int) $stmt->fetchColumn();
if ($providerId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'unknown provider'], JSON_UNESCAPED_UNICODE);
    exit;
}

$commission = round((float) ($input['commission'] ?? 0), 2);
$amount = round((float) ($input['amount'] ?? 0), 2);
if ($commission <= 0 && $amount <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'commission or amount required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = strtolower(trim((string) ($input['status'] ?? 'pending')));
$status = match ($status) {
    'paid', 'settled', '已结算' => 'paid',
    'rejected', 'invalid', '已拒绝' => 'rejected',
    default => 'pending',
};
$subid = substr(trim((string) ($input['subid'] ?? '')), 0, 64);
$note = mb_substr(trim((string) ($input['note'] ?? 'postback')), 0, 200);
$rawDate = trim((string) ($input['date'] ?? $input['created_at'] ?? ''));
$timestamp = $rawDate !== '' ? strtotime($rawDate) : false;
$createdAt = $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');

$insert = db()->prepare(
    'INSERT INTO conversions (agent_id, provider_id, subid, amount, commission, status, note, created_at)
     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute([$providerId, $subid, $amount, $commission, $status, $note, $createdAt]);

echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()], JSON_UNESCAPED_UNICODE);
