<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

/**
 * Probe report ingestion — PROTOCOL.md v0.2.0.
 *
 * Pretty URL: POST /api/v1/metrics/report  (router.php / nginx rewrite)
 * Direct URL: POST /api/metrics-report.php
 *
 * Auth:     Authorization: Bearer <probe token>
 * Signing:  payload.signature = HMAC-SHA256 over canonical JSON (see PROTOCOL.md §3)
 * Limits:   freshness window, per-probe min interval + hourly cap, dedupe, body size
 *
 * Response: {"ok":true,"report_id":123,"results":10} or {"ok":false,"error":"..."}
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response(['ok' => false, 'error' => 'method not allowed'], 405);
}

$maxBytes = probe_int_setting('PROBE_MAX_BODY_BYTES', 262144);
$raw = file_get_contents('php://input');
if ($raw === false) {
    json_response(['ok' => false, 'error' => 'unreadable body'], 400);
}
if (strlen($raw) > $maxBytes) {
    json_response(['ok' => false, 'error' => 'payload too large'], 413);
}
if (!str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    json_response(['ok' => false, 'error' => 'content-type must be application/json'], 415);
}

$data = json_decode($raw, true);
$tree = json_decode($raw, false);
if (!is_array($data) || !($tree instanceof stdClass)) {
    json_response(['ok' => false, 'error' => 'invalid JSON object'], 400);
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
    $token = trim($matches[1]);
}
if ($token === '') {
    $token = trim((string) ($_SERVER['HTTP_X_PROBE_TOKEN'] ?? ''));
}
if ($token === '') {
    json_response(['ok' => false, 'error' => 'missing bearer token'], 401);
}

$tokenRow = probe_find_token($token);
if ($tokenRow === null || ($tokenRow['status'] ?? '') !== 'active') {
    json_response(['ok' => false, 'error' => 'invalid or revoked token'], 403);
}

$probeId = (string) ($data['probe_id'] ?? '');
if ($probeId === '' || !hash_equals((string) $tokenRow['probe_id'], $probeId)) {
    json_response(['ok' => false, 'error' => 'probe_id does not match token'], 403);
}

$signature = (string) ($data['signature'] ?? '');
$allowUnsigned = probe_int_setting('PROBE_ALLOW_UNSIGNED', 0) === 1;
$version = (string) ($data['protocol_version'] ?? '');
$signatureOk = false;
if ($signature !== '') {
    try {
        $signatureOk = probe_verify_signature($tree, $token, $signature);
    } catch (Throwable) {
        $signatureOk = false;
    }
    if (!$signatureOk) {
        json_response(['ok' => false, 'error' => 'invalid signature'], 403);
    }
} elseif (!($allowUnsigned && in_array($version, ['0.1.0', '0.1.1'], true))) {
    json_response(['ok' => false, 'error' => 'signature required'], 403);
}

$error = probe_validate_payload($data);
if ($error !== null) {
    json_response(['ok' => false, 'error' => $error], 422);
}

$limit = probe_rate_limit($tokenRow);
if ($limit !== null) {
    header('Retry-After: ' . $limit['retry_after']);
    json_response(['ok' => false, 'error' => 'rate limited: ' . $limit['reason']], 429);
}

try {
    $unsigned = clone $tree;
    unset($unsigned->signature);
    $canonicalHash = hash('sha256', probe_canonical_json($unsigned));
} catch (Throwable) {
    json_response(['ok' => false, 'error' => 'payload cannot be canonicalized'], 400);
}

try {
    $stored = probe_ingest($data, $canonicalHash, $signatureOk);
} catch (RuntimeException $exc) {
    if ($exc->getMessage() === 'duplicate report') {
        json_response(['ok' => false, 'error' => 'duplicate report'], 409);
    }
    throw $exc;
}

json_response([
    'ok' => true,
    'report_id' => $stored['report_id'],
    'results' => $stored['results'],
    'received_at' => probe_utc_now(),
]);
