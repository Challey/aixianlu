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
        $item = [
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
        $item['stability'] = probe_stability($item);
        $rows[] = $item;
    }

    // Neutral, reproducible ordering for every consumer of this function:
    // most evidence first, control group pinned last, name as tie-breaker.
    // Commercial relationships are not an input here.
    $controls = [];
    foreach (probe_target_catalog() as $key => $target) {
        $controls[$key] = !empty($target['control']) ? 1 : 0;
    }
    usort($rows, static function (array $a, array $b) use ($controls): int {
        $ca = $controls[$a['name']] ?? 0;
        $cb = $controls[$b['name']] ?? 0;
        if ($ca !== $cb) {
            return $ca <=> $cb;
        }
        if ($a['samples'] !== $b['samples']) {
            return $b['samples'] <=> $a['samples'];
        }
        return strcmp($a['name'], $b['name']);
    });

    return $rows;
}

/**
 * Composite stability score for one aggregated row (0–100).
 *
 * Deliberately simple and fully public: 50% 可用率 + 20% 可达率 + 30% 延迟一致性
 * (P95 与均值的接近程度)，再按区域拦截与限流扣分。样本不足时标记 confidence=low，
 * 前台必须一并展示样本量，不能让 1 次测量的 100% 与 200 次测量的 100% 看起来一样。
 */
function probe_stability(array $row): ?array
{
    $samples = (int) ($row['samples'] ?? 0);
    $success = $row['success_rate'] ?? null;
    $reachable = $row['reachable_rate'] ?? null;
    if ($samples <= 0 || $success === null || $reachable === null) {
        return null;
    }
    $avg = $row['avg_latency_ms'] !== null ? (float) $row['avg_latency_ms'] : null;
    $p95 = $row['worst_p95_latency_ms'] !== null ? (float) $row['worst_p95_latency_ms'] : null;
    $jitter = null;
    if ($avg !== null && $avg > 0 && $p95 !== null && $p95 >= $avg) {
        $jitter = max(0.0, min(1.0, 1 - ($p95 - $avg) / $avg));
    }

    $base = 0.5 * (float) $success + 0.2 * (float) $reachable;
    $score = $jitter === null ? ($base / 0.7) * 100 : ($base + 0.3 * $jitter) * 100;
    $penalty = min(20, (int) ($row['region_block_count'] ?? 0) * 10) + min(10, (int) ($row['rate_limit_count'] ?? 0) * 5);
    $score = max(0, min(100, $score - $penalty));

    $grades = [90 => 'A', 75 => 'B', 60 => 'C', 0 => 'D'];
    $grade = 'D';
    foreach ($grades as $min => $label) {
        if ($score >= $min) {
            $grade = $label;
            break;
        }
    }

    return [
        'score' => (int) round($score),
        'grade' => $grade,
        'confidence' => $samples >= 5 ? 'ok' : 'low',
        'samples' => $samples,
        'jitter_score' => $jitter === null ? null : round($jitter, 3),
        'penalty' => $penalty,
    ];
}

/**
 * Weighted headline numbers for the public status page.
 *
 * Everything is derived from the same rows the table renders, so the hero
 * figures can never drift away from the detail. Control-group endpoints
 * (GitHub / Cloudflare) are kept out of the headline: they exist to tell a
 * dead target apart from a dead probe, and averaging them in would flatter the
 * score. They are reported separately so the page can prove the measurement
 * itself was valid.
 */
function probe_status_summary(int $hours = 24, string $isp = '', string $name = ''): array
{
    $rows = probe_latest_metrics($hours, $isp, $name);
    $catalog = probe_target_catalog();
    $samples = 0;
    $successWeighted = 0.0;
    $reachableWeighted = 0.0;
    $latencyWeighted = 0.0;
    $latencySamples = 0;
    $p95Worst = null;
    $stabilityWeighted = 0.0;
    $stabilitySamples = 0;
    $thinGroups = 0;
    $blocks = 0;
    $limits = 0;
    $targets = [];
    $isps = [];
    $controlSamples = 0;
    $controlReachableWeighted = 0.0;
    $controlLatencyWeighted = 0.0;
    $controlLatencySamples = 0;
    $controlNames = [];

    foreach ($rows as $row) {
        $n = (int) $row['samples'];
        if (!empty($catalog[$row['name']]['control'])) {
            $controlSamples += $n;
            if ($row['reachable_rate'] !== null) {
                $controlReachableWeighted += (float) $row['reachable_rate'] * $n;
            }
            if ($row['avg_latency_ms'] !== null) {
                $controlLatencyWeighted += (float) $row['avg_latency_ms'] * $n;
                $controlLatencySamples += $n;
            }
            $controlNames[(string) $row['name']] = true;
            continue;
        }
        $samples += $n;
        if ($row['success_rate'] !== null) {
            $successWeighted += (float) $row['success_rate'] * $n;
        }
        if ($row['reachable_rate'] !== null) {
            $reachableWeighted += (float) $row['reachable_rate'] * $n;
        }
        if ($row['avg_latency_ms'] !== null) {
            $latencyWeighted += (float) $row['avg_latency_ms'] * $n;
            $latencySamples += $n;
        }
        if ($row['worst_p95_latency_ms'] !== null) {
            $p95Worst = $p95Worst === null ? (float) $row['worst_p95_latency_ms'] : max($p95Worst, (float) $row['worst_p95_latency_ms']);
        }
        // Weight every scored group, but the page still labels the result 'low'
        // until the window as a whole has enough samples (see confidence below).
        $stability = $row['stability'] ?? null;
        if (is_array($stability) && isset($stability['score'])) {
            $stabilityWeighted += (int) $stability['score'] * $n;
            $stabilitySamples += $n;
            if ($stability['confidence'] !== 'ok') {
                $thinGroups++;
            }
        }
        $blocks += (int) $row['region_block_count'];
        $limits += (int) $row['rate_limit_count'];
        $targets[$row['name']] = true;
        $value = trim((string) $row['isp']);
        if ($value !== '') {
            $isps[$value] = true;
        }
    }

    return [
        'control' => [
            'samples' => $controlSamples,
            'names' => array_keys($controlNames),
            'reachable_rate' => $controlSamples > 0 ? round($controlReachableWeighted / $controlSamples, 4) : null,
            'avg_latency_ms' => $controlLatencySamples > 0 ? round($controlLatencyWeighted / $controlLatencySamples, 1) : null,
        ],
        'window_hours' => max(1, $hours),
        'samples' => $samples,
        'target_count' => count($targets),
        'isp_count' => count($isps),
        'success_rate' => $samples > 0 ? round($successWeighted / $samples, 4) : null,
        'reachable_rate' => $samples > 0 ? round($reachableWeighted / $samples, 4) : null,
        'avg_latency_ms' => $latencySamples > 0 ? round($latencyWeighted / $latencySamples, 1) : null,
        'worst_p95_latency_ms' => $p95Worst !== null ? round($p95Worst, 1) : null,
        'stability_score' => $stabilitySamples > 0 ? (int) round($stabilityWeighted / $stabilitySamples) : null,
        'thin_groups' => $thinGroups,
        'region_block_count' => $blocks,
        'rate_limit_count' => $limits,
        'confidence' => $samples >= 20 ? 'ok' : ($samples > 0 ? 'low' : 'none'),
    ];
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

/**
 * Human-readable catalogue of the default probe targets (mirrors
 * probes/src/aixianlu_probe/targets.py). Used by the public /status/ page so
 * raw target names never have to be shown on their own.
 */
function probe_target_catalog(): array
{
    return [
        'openai-api' => ['label' => 'OpenAI 模型列表', 'control' => false, 'tool' => 'chatgpt'],
        'openai-chat' => ['label' => 'OpenAI Chat', 'control' => false, 'tool' => 'chatgpt'],
        'openai-embeddings' => ['label' => 'OpenAI Embeddings', 'control' => false, 'tool' => 'chatgpt'],
        'claude-api' => ['label' => 'Claude Messages', 'control' => false, 'tool' => 'claude'],
        'claude-web' => ['label' => 'Claude 网页端', 'control' => false, 'tool' => 'claude'],
        'gemini-api' => ['label' => 'Gemini 模型列表', 'control' => false, 'tool' => 'gemini'],
        'gemini-flash' => ['label' => 'Gemini Flash', 'control' => false, 'tool' => 'gemini'],
        'cursor-api' => ['label' => 'Cursor API', 'control' => false, 'tool' => 'cursor'],
        'github-api' => ['label' => 'GitHub 对照', 'control' => true, 'tool' => ''],
        'cloudflare' => ['label' => 'Cloudflare 对照', 'control' => true, 'tool' => ''],
    ];
}

/**
 * Aggregate numbers for the public status page (no probe identifiers).
 */
function probe_status_meta(int $hours = 24): array
{
    probe_ensure_schema();
    $since = gmdate('Y-m-d H:00', time() - max(1, $hours) * 3600);
    $row = db()->prepare(
        'SELECT COUNT(*) AS groups, COALESCE(SUM(samples), 0) AS samples,
                COUNT(DISTINCT isp) AS isps, COUNT(DISTINCT name) AS targets,
                MAX(hour) AS last_hour
         FROM probe_snapshots WHERE hour >= ?'
    );
    $row->execute([$since]);
    $stats = $row->fetch() ?: [];
    $probes = db()->prepare(
        'SELECT COUNT(DISTINCT probe_id) AS n FROM probe_reports WHERE received_at >= ?'
    );
    $probes->execute([$since]);
    return [
        'groups' => (int) ($stats['groups'] ?? 0),
        'samples' => (int) ($stats['samples'] ?? 0),
        'isps' => (int) ($stats['isps'] ?? 0),
        'targets' => (int) ($stats['targets'] ?? 0),
        'last_hour' => $stats['last_hour'] ?? null,
        'probes' => (int) $probes->fetchColumn(),
        'generated_at' => gmdate('c'),
        'generated_label' => date('Y-m-d H:i'),
    ];
}

/**
 * Endpoint × ISP matrix for the public status page.
 *
 * Built here (not in the template or in JavaScript) so the baked HTML, the
 * live API and any future client show identical numbers from one formula.
 */
function probe_status_matrix(int $hours = 24, string $isp = '', string $name = ''): array
{
    $rows = probe_latest_metrics($hours, $isp, $name);
    $catalog = probe_target_catalog();
    $byName = [];
    $ispTotals = [];

    foreach ($rows as $row) {
        $key = (string) $row['name'];
        $cell = trim((string) ($row['isp'] ?? ''));
        if ($cell === '') {
            $cell = '未标注';
        }
        if (!isset($byName[$key])) {
            $byName[$key] = [
                'name' => $key,
                'label' => (string) ($catalog[$key]['label'] ?? $key),
                'tool' => (string) ($catalog[$key]['tool'] ?? ''),
                'control' => !empty($catalog[$key]['control']),
                'model' => null,
                'samples' => 0,
                'cells' => [],
                'agg' => ['success' => 0.0, 'reachable' => 0.0, 'latency' => 0.0, 'latency_n' => 0, 'p95' => null, 'block' => 0, 'limit' => 0],
            ];
        }
        if ($row['model'] !== null && $row['model'] !== '') {
            $byName[$key]['model'] = (string) $row['model'];
        }
        $n = (int) $row['samples'];
        $byName[$key]['samples'] += $n;
        $byName[$key]['cells'][$cell] = $row;
        if ($row['success_rate'] !== null) {
            $byName[$key]['agg']['success'] += (float) $row['success_rate'] * $n;
        }
        if ($row['reachable_rate'] !== null) {
            $byName[$key]['agg']['reachable'] += (float) $row['reachable_rate'] * $n;
        }
        if ($row['avg_latency_ms'] !== null) {
            $byName[$key]['agg']['latency'] += (float) $row['avg_latency_ms'] * $n;
            $byName[$key]['agg']['latency_n'] += $n;
        }
        if ($row['worst_p95_latency_ms'] !== null) {
            $p95 = (float) $row['worst_p95_latency_ms'];
            $byName[$key]['agg']['p95'] = $byName[$key]['agg']['p95'] === null ? $p95 : max($byName[$key]['agg']['p95'], $p95);
        }
        $byName[$key]['agg']['block'] += (int) $row['region_block_count'];
        $byName[$key]['agg']['limit'] += (int) $row['rate_limit_count'];
        $ispTotals[$cell] = ($ispTotals[$cell] ?? 0) + $n;
    }

    $out = [];
    foreach ($byName as $entry) {
        $agg = $entry['agg'];
        $total = $entry['samples'];
        $summary = [
            'samples' => $total,
            'success_rate' => $total > 0 ? round($agg['success'] / $total, 4) : null,
            'reachable_rate' => $total > 0 ? round($agg['reachable'] / $total, 4) : null,
            'avg_latency_ms' => $agg['latency_n'] > 0 ? round($agg['latency'] / $agg['latency_n'], 1) : null,
            'worst_p95_latency_ms' => $agg['p95'] !== null ? round($agg['p95'], 1) : null,
            'region_block_count' => $agg['block'],
            'rate_limit_count' => $agg['limit'],
        ];
        $entry['total'] = $summary + ['stability' => probe_stability($summary)];
        unset($entry['agg']);
        $out[] = $entry;
    }

    usort($out, static function (array $a, array $b): int {
        if ($a['control'] !== $b['control']) {
            return ((int) $a['control']) <=> ((int) $b['control']);
        }
        return $b['samples'] <=> $a['samples'];
    });

    $columns = array_keys($ispTotals);
    usort($columns, static function (string $a, string $b) use ($ispTotals): int {
        return $ispTotals[$b] <=> $ispTotals[$a];
    });

    return ['isps' => $columns, 'rows' => $out];
}
