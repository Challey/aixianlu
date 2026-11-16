<?php

declare(strict_types=1);

/**
 * Probe ingestion helpers.
 *
 * Implements PROTOCOL.md v0.2.0: canonical JSON + HMAC-SHA256 verification,
 * token auth, freshness window, per-probe rate limiting, dedupe, storage and
 * hourly MetricSnapshot aggregation.
 *
 * The canonical JSON writer mirrors probes/src/aixianlu_probe/signing.py so
 * that Python, PHP and a future Go probe produce identical signatures.
 */

const PROBE_SIGNING_CONTEXT = 'aixianlu-probe/v1/signing';
const PROBE_SUPPORTED_VERSIONS = ['0.1.0', '0.1.1', '0.2.0'];
const PROBE_ERROR_TYPES = ['timeout', 'dns', 'tls', 'connect', 'http', 'other'];
const PROBE_ERROR_CLASSES = [
    'region_block', 'rate_limit', 'auth_required', 'forbidden',
    'not_found', 'bad_request', 'server_error', 'http_error',
];

function probe_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $sql = file_get_contents(__DIR__ . '/probe_schema.sql');
    if ($sql === false) {
        throw new RuntimeException('probe_schema.sql not found');
    }
    db()->exec($sql);
    $done = true;
}

function probe_json_string(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function probe_canonical_json(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('non-finite numbers cannot be signed');
        }
        return number_format($value, 6, '.', '');
    }
    if (is_string($value)) {
        return probe_json_string($value);
    }

    if ($value instanceof stdClass) {
        $items = get_object_vars($value);
        ksort($items, SORT_STRING);
        $parts = [];
        foreach ($items as $key => $item) {
            $parts[] = probe_json_string((string) $key) . ':' . probe_canonical_json($item);
        }
        return '{' . implode(',', $parts) . '}';
    }

    if (is_array($value)) {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map('probe_canonical_json', $value)) . ']';
        }
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = probe_json_string((string) $key) . ':' . probe_canonical_json($item);
        }
        return '{' . implode(',', $parts) . '}';
    }

    throw new InvalidArgumentException('unsupported type for canonical JSON');
}

function probe_signing_key(string $token): string
{
    return hash_hmac('sha256', PROBE_SIGNING_CONTEXT, $token, true);
}

function probe_sign_tree(object|array $tree, string $token): string
{
    if ($tree instanceof stdClass) {
        $tree = clone $tree;
        unset($tree->signature);
    } else {
        unset($tree['signature']);
    }
    return hash_hmac('sha256', probe_canonical_json($tree), probe_signing_key($token));
}

function probe_verify_signature(object|array $tree, string $token, string $signature): bool
{
    if ($signature === '' || $token === '') {
        return false;
    }
    return hash_equals(probe_sign_tree($tree, $token), strtolower($signature));
}

function probe_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function probe_find_token(string $token): ?array
{
    probe_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM probe_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([probe_token_hash($token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function probe_new_id(): string
{
    return 'probe-' . substr(bin2hex(random_bytes(8)), 0, 16);
}

function probe_issue_token(string $probeId, string $label = ''): array
{
    probe_ensure_schema();
    $token = 'axp_' . bin2hex(random_bytes(24));
    $stmt = db()->prepare('INSERT INTO probe_tokens(probe_id, token_hash, label) VALUES(?, ?, ?)');
    $stmt->execute([$probeId, probe_token_hash($token), mb_substr($label, 0, 120)]);
    return ['id' => (int) db()->lastInsertId(), 'probe_id' => $probeId, 'token' => $token];
}

function probe_setting(string $key, string $default): string
{
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return setting(strtolower($key), $default);
}

function probe_int_setting(string $key, int $default): int
{
    $value = probe_setting($key, (string) $default);
    return is_numeric($value) ? (int) $value : $default;
}

function probe_utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function probe_utc_hour(string $isoTimestamp): string
{
    $epoch = strtotime($isoTimestamp);
    return $epoch === false ? gmdate('Y-m-d H:00') : gmdate('Y-m-d H:00', $epoch);
}

/**
 * @return array{retry_after:int, reason:string}|null
 */
function probe_rate_limit(array $tokenRow): ?array
{
    $minInterval = probe_int_setting('PROBE_MIN_INTERVAL_SECONDS', 60);
    $last = (string) ($tokenRow['last_report_at'] ?? '');
    if ($minInterval > 0 && $last !== '') {
        $delta = time() - (int) strtotime($last . ' UTC');
        if ($delta < $minInterval) {
            return ['retry_after' => $minInterval - $delta, 'reason' => 'min_interval'];
        }
    }

    $hourlyLimit = probe_int_setting('PROBE_HOURLY_LIMIT', 120);
    if ($hourlyLimit > 0) {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM probe_reports WHERE probe_id = ? AND received_at >= datetime('now', '-1 hour')"
        );
        $stmt->execute([(string) $tokenRow['probe_id']]);
        if ((int) $stmt->fetchColumn() >= $hourlyLimit) {
            return ['retry_after' => 300, 'reason' => 'hourly_limit'];
        }
    }

    return null;
}

/**
 * Validate a decoded report payload. Returns an error message or null.
 */
function probe_validate_payload(array $data): ?string
{
    $version = (string) ($data['protocol_version'] ?? '');
    if (!in_array($version, PROBE_SUPPORTED_VERSIONS, true)) {
        return 'unsupported protocol_version';
    }

    $probeId = (string) ($data['probe_id'] ?? '');
    if ($probeId === '' || strlen($probeId) > 64) {
        return 'invalid probe_id';
    }

    $timestamp = strtotime((string) ($data['timestamp'] ?? ''));
    if ($timestamp === false) {
        return 'invalid timestamp';
    }
    $skew = probe_int_setting('PROBE_TIMESTAMP_SKEW_SECONDS', 300);
    if (abs(time() - $timestamp) > $skew) {
        return 'timestamp outside freshness window';
    }

    if (!isset($data['client']) || !is_array($data['client'])) {
        return 'missing client info';
    }

    $results = $data['results'] ?? null;
    if (!is_array($results) || $results === [] || !array_is_list($results)) {
        return 'results must be a non-empty array';
    }
    $maxResults = probe_int_setting('PROBE_MAX_RESULTS', 200);
    if (count($results) > $maxResults) {
        return 'too many results';
    }

    foreach ($results as $result) {
        if (!is_array($result)) {
            return 'invalid result entry';
        }
        $name = (string) ($result['name'] ?? '');
        if ($name === '' || strlen($name) > 64) {
            return 'invalid result name';
        }
        if (!isset($result['success'], $result['reachable'])
            || !is_bool($result['success']) || !is_bool($result['reachable'])) {
            return 'success/reachable must be boolean';
        }
        $status = $result['status_code'] ?? null;
        if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
            return 'invalid status_code';
        }
        $latency = $result['latency_ms'] ?? null;
        if ($latency !== null && (!is_numeric($latency) || (float) $latency < 0)) {
            return 'invalid latency_ms';
        }
        $errorType = (string) ($result['error_type'] ?? '');
        if ($errorType !== '' && !in_array($errorType, PROBE_ERROR_TYPES, true)) {
            return 'invalid error_type';
        }
        $errorClass = (string) ($result['error_class'] ?? '');
        if ($errorClass !== '' && !in_array($errorClass, PROBE_ERROR_CLASSES, true)) {
            return 'invalid error_class';
        }
        if (strtotime((string) ($result['checked_at'] ?? '')) === false) {
            return 'invalid checked_at';
        }
    }

    return null;
}

/**
 * Store a validated report and its results. Returns [report_id, result_count].
 *
 * @return array{report_id:int, results:int}
 */
function probe_ingest(array $data, string $canonicalHash, bool $signatureOk): array
{
    probe_ensure_schema();
    $pdo = db();

    $probeId = (string) $data['probe_id'];
    $clientTs = (string) $data['timestamp'];
    $location = is_array($data['location'] ?? null) ? $data['location'] : [];
    $client = is_array($data['client'] ?? null) ? $data['client'] : [];
    $results = $data['results'];

    $dedupeKey = hash('sha256', $probeId . '|' . $clientTs . '|' . $canonicalHash);
    $exists = $pdo->prepare('SELECT id FROM probe_reports WHERE dedupe_key = ? LIMIT 1');
    $exists->execute([$dedupeKey]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('duplicate report');
    }

    $successCount = 0;
    $reachableCount = 0;
    foreach ($results as $result) {
        $successCount += !empty($result['success']) ? 1 : 0;
        $reachableCount += !empty($result['reachable']) ? 1 : 0;
    }

    $receivedAt = probe_utc_now();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO probe_reports(
                probe_id, protocol_version, client_ts, received_at, isp, region, asn, country,
                client_version, client_os, result_count, success_count, reachable_count,
                signature_ok, ip_hash, dedupe_key, payload
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $probeId,
            (string) ($data['protocol_version'] ?? ''),
            $clientTs,
            $receivedAt,
            mb_substr((string) ($location['isp'] ?? ''), 0, 40),
            mb_substr((string) ($location['region'] ?? ''), 0, 80),
            isset($location['asn']) && is_numeric($location['asn']) ? (int) $location['asn'] : null,
            mb_substr((string) ($location['country'] ?? ''), 0, 8),
            mb_substr((string) ($client['version'] ?? ''), 0, 24),
            mb_substr((string) ($client['os'] ?? ''), 0, 80),
            count($results),
            $successCount,
            $reachableCount,
            $signatureOk ? 1 : 0,
            client_ip_hash(),
            $dedupeKey,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $reportId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO probe_results(
                report_id, probe_id, name, url, category, model, method, success, reachable,
                status_code, latency_ms, error_type, error_class, tls_version, http_version,
                redirect_count, response_size, attempts, isp, checked_at, hour
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $isp = mb_substr((string) ($location['isp'] ?? ''), 0, 40);
        foreach ($results as $result) {
            $checkedAt = (string) ($result['checked_at'] ?? '');
            $insert->execute([
                $reportId,
                $probeId,
                mb_substr((string) $result['name'], 0, 64),
                mb_substr((string) ($result['url'] ?? ''), 0, 500),
                mb_substr((string) ($result['category'] ?? ''), 0, 24),
                mb_substr((string) ($result['model'] ?? ''), 0, 64),
                strtoupper(mb_substr((string) ($result['method'] ?? 'GET'), 0, 8)),
                !empty($result['success']) ? 1 : 0,
                !empty($result['reachable']) ? 1 : 0,
                isset($result['status_code']) && is_int($result['status_code']) ? $result['status_code'] : null,
                isset($result['latency_ms']) && is_numeric($result['latency_ms']) ? (float) $result['latency_ms'] : null,
                (string) ($result['error_type'] ?? ''),
                (string) ($result['error_class'] ?? ''),
                mb_substr((string) ($result['tls_version'] ?? ''), 0, 16),
                mb_substr((string) ($result['http_version'] ?? ''), 0, 16),
                max(0, (int) ($result['redirect_count'] ?? 0)),
                isset($result['response_size']) && is_numeric($result['response_size']) ? (int) $result['response_size'] : null,
                max(1, (int) ($result['attempts'] ?? 1)),
                $isp,
                $checkedAt,
                probe_utc_hour($checkedAt),
            ]);
        }

        $pdo->prepare('UPDATE probe_tokens SET last_report_at = ? WHERE probe_id = ?')
            ->execute([$receivedAt, $probeId]);

        $pdo->commit();
    } catch (Throwable $exc) {
        $pdo->rollBack();
        throw $exc;
    }

    return ['report_id' => $reportId, 'results' => count($results)];
}

function probe_percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $index = (int) ceil($percentile / 100 * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return round((float) $values[$index], 1);
}

/**
 * Rebuild hourly snapshots for the recent window. Returns rows written.
 */
function probe_build_snapshots(int $hours = 48): int
{
    probe_ensure_schema();
    $pdo = db();
    $since = gmdate('Y-m-d H:00', time() - max(1, $hours) * 3600);

    $groups = $pdo->prepare(
        'SELECT hour, name, model, isp FROM probe_results WHERE hour >= ?
         GROUP BY hour, name, model, isp ORDER BY hour ASC'
    );
    $groups->execute([$since]);

    $fetch = $pdo->prepare(
        'SELECT success, reachable, latency_ms, error_class FROM probe_results
         WHERE hour = ? AND name = ? AND model = ? AND isp = ?'
    );
    $upsert = $pdo->prepare(
        'INSERT INTO probe_snapshots(
            hour, name, model, isp, samples, success_count, reachable_count,
            avg_latency_ms, p50_latency_ms, p95_latency_ms, region_block_count, rate_limit_count, updated_at
         ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON CONFLICT(hour, name, model, isp) DO UPDATE SET
            samples = excluded.samples,
            success_count = excluded.success_count,
            reachable_count = excluded.reachable_count,
            avg_latency_ms = excluded.avg_latency_ms,
            p50_latency_ms = excluded.p50_latency_ms,
            p95_latency_ms = excluded.p95_latency_ms,
            region_block_count = excluded.region_block_count,
            rate_limit_count = excluded.rate_limit_count,
            updated_at = excluded.updated_at'
    );

    $written = 0;
    foreach ($groups->fetchAll() as $group) {
        $fetch->execute([$group['hour'], $group['name'], $group['model'], $group['isp']]);
        $rows = $fetch->fetchAll();
        if ($rows === []) {
            continue;
        }
        $latencies = [];
        $success = 0;
        $reachable = 0;
        $regionBlock = 0;
        $rateLimit = 0;
        foreach ($rows as $row) {
            $success += (int) $row['success'];
            $reachable += (int) $row['reachable'];
            if ($row['latency_ms'] !== null) {
                $latencies[] = (float) $row['latency_ms'];
            }
            if ($row['error_class'] === 'region_block') {
                $regionBlock++;
            }
            if ($row['error_class'] === 'rate_limit') {
                $rateLimit++;
            }
        }
        $avg = $latencies === [] ? null : round(array_sum($latencies) / count($latencies), 1);
        $upsert->execute([
            $group['hour'], $group['name'], $group['model'], $group['isp'],
            count($rows), $success, $reachable,
            $avg, probe_percentile($latencies, 50), probe_percentile($latencies, 95),
            $regionBlock, $rateLimit, probe_utc_now(),
        ]);
        $written++;
    }

    return $written;
}

/**
 * Aggregate snapshots into the public "latest metrics" view.
 */
function probe_latest_metrics(int $hours = 24, string $isp = '', string $name = ''): array
{
    probe_ensure_schema();
    $since = gmdate('Y-m-d H:00', time() - max(1, $hours) * 3600);
    $where = ['hour >= ?'];
    $params = [$since];
    if ($isp !== '') {
        $where[] = 'isp = ?';
        $params[] = $isp;
    }
    if ($name !== '') {
        $where[] = 'name = ?';
        $params[] = $name;
    }
    $sql = 'SELECT name, model, isp,
                   SUM(samples) AS samples,
                   SUM(success_count) AS success_count,
                   SUM(reachable_count) AS reachable_count,
                   SUM(avg_latency_ms * samples) / NULLIF(SUM(samples), 0) AS avg_latency_ms,
                   MAX(p95_latency_ms) AS worst_p95_latency_ms,
                   SUM(region_block_count) AS region_block_count,
                   SUM(rate_limit_count) AS rate_limit_count,
                   COUNT(DISTINCT hour) AS hours_covered,
                   MAX(hour) AS last_hour
            FROM probe_snapshots WHERE ' . implode(' AND ', $where) . '
            GROUP BY name, model, isp ORDER BY samples DESC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $samples = (int) $row['samples'];
        $rows[] = [
            'name' => $row['name'],
            'model' => $row['model'],
            'isp' => $row['isp'],
            'samples' => $samples,
            'success_rate' => $samples > 0 ? round((int) $row['success_count'] / $samples, 4) : null,
            'reachable_rate' => $samples > 0 ? round((int) $row['reachable_count'] / $samples, 4) : null,
            'avg_latency_ms' => $row['avg_latency_ms'] !== null ? round((float) $row['avg_latency_ms'], 1) : null,
            'worst_p95_latency_ms' => $row['worst_p95_latency_ms'] !== null ? round((float) $row['worst_p95_latency_ms'], 1) : null,
            'region_block_count' => (int) $row['region_block_count'],
            'rate_limit_count' => (int) $row['rate_limit_count'],
            'hours_covered' => (int) $row['hours_covered'],
            'last_hour' => $row['last_hour'],
        ];
    }
    return $rows;
}

/**
 * Delete raw reports/results older than the retention window.
 */
function probe_prune(int $days = 30): int
{
    probe_ensure_schema();
    $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    $stmt = db()->prepare('DELETE FROM probe_reports WHERE received_at < ?');
    $stmt->execute([$cutoff]);
    $deleted = $stmt->rowCount();
    db()->prepare('DELETE FROM probe_snapshots WHERE hour < ?')->execute([$cutoff]);
    return $deleted;
}
