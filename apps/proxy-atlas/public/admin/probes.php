<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();
probe_ensure_schema();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * A freshly issued token is handed over through the session, not the URL, so it
 * never lands in browser history, access logs or the Referer header.
 */
function probe_take_reveal(): ?array
{
    $reveal = $_SESSION['probe_reveal'] ?? null;
    unset($_SESSION['probe_reveal']);
    return is_array($reveal) ? $reveal : null;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
        redirect('/admin/probes.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'issue') {
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
        $probeId = probe_new_id();
        try {
            $issued = probe_issue_token($probeId, $label);
            $_SESSION['probe_reveal'] = ['token' => $issued['token'], 'probe_id' => $issued['probe_id']];
            admin_flash('token 已生成，请在页面顶部立即复制；刷新后不再显示。');
        } catch (PDOException $exc) {
            admin_flash('发放失败：' . $exc->getMessage(), 'error');
        }
    } elseif ($action === 'revoke' || $action === 'activate') {
        $probeId = mb_substr(trim((string) ($_POST['probe_id'] ?? '')), 0, 64);
        $status = $action === 'revoke' ? 'revoked' : 'active';
        $stmt = $pdo->prepare('UPDATE probe_tokens SET status = ? WHERE probe_id = ?');
        $stmt->execute([$status, $probeId]);
        admin_flash(
            $stmt->rowCount() > 0 ? "{$probeId} 已" . ($status === 'revoked' ? '撤销' : '恢复') . '。' : '未找到该探针。',
            $stmt->rowCount() > 0 ? 'ok' : 'error'
        );
    }
    redirect('/admin/probes.php');
}

$reveal = probe_take_reveal();

$hours = (int) ($_GET['hours'] ?? 24);
if (!in_array($hours, [6, 24, 72, 168], true)) {
    $hours = 24;
}
$sinceHour = gmdate('Y-m-d H:00', time() - $hours * 3600);

$tokenRows = $pdo->query(
    'SELECT probe_id, label, status, created_at, last_report_at
     FROM probe_tokens ORDER BY id DESC LIMIT 100'
)->fetchAll();

$reportStats = $pdo->prepare(
    "SELECT COUNT(*) AS reports, COALESCE(SUM(result_count), 0) AS results,
            SUM(CASE WHEN signature_ok = 0 THEN 1 ELSE 0 END) AS unsigned_reports
     FROM probe_reports WHERE received_at >= datetime('now', ?)"
);
$reportStats->execute(['-' . $hours . ' hour']);
$stats = $reportStats->fetch();

$activeCount = 0;
foreach ($tokenRows as $tokenRow) {
    $activeCount += $tokenRow['status'] === 'active' ? 1 : 0;
}

$snapshotRows = $pdo->prepare(
    'SELECT name, model, isp,
            SUM(samples) AS samples,
            SUM(success_count) AS success_count,
            SUM(reachable_count) AS reachable_count,
            SUM(avg_latency_ms * samples) / NULLIF(SUM(samples), 0) AS avg_latency_ms,
            MAX(p95_latency_ms) AS worst_p95,
            SUM(region_block_count) AS region_blocks,
            SUM(rate_limit_count) AS rate_limits,
            MAX(hour) AS last_hour
     FROM probe_snapshots WHERE hour >= ?
     GROUP BY name, model, isp
     ORDER BY samples DESC, name ASC LIMIT 50'
);
$snapshotRows->execute([$sinceHour]);
$snapshots = $snapshotRows->fetchAll();

$recentReports = $pdo->prepare(
    'SELECT id, probe_id, protocol_version, client_ts, received_at, isp, region, asn,
            client_version, result_count, success_count, reachable_count, signature_ok
     FROM probe_reports ORDER BY id DESC LIMIT 30'
);
$recentReports->execute();
$reports = $recentReports->fetchAll();

$totals = $pdo->query('SELECT COUNT(*) FROM probe_reports')->fetchColumn();
$maxSamples = 1;
foreach ($snapshots as $snapshot) {
    $maxSamples = max($maxSamples, (int) $snapshot['samples']);
}

admin_header('探针数据', 'probes');
?>

<?php if ($reveal): ?>
  <div class="admin-flash">
    <strong>新 token（仅显示一次，请立即复制）</strong><br>
    <code style="font-size:.75rem;word-break:break-all"><?= e($reveal['token']) ?></code><br>
    探针 ID：<code><?= e($reveal['probe_id']) ?></code>
    <p style="margin:.5rem 0 0">写入探针 <code>config.yaml</code>：
      <code>report.endpoint: <?= e(site_url('/api/v1/metrics/report')) ?></code> ·
      <code>report.probe_id: <?= e($reveal['probe_id']) ?></code> ·
      <code>report.token: &lt;上面的 token&gt;</code>，然后执行 <code>aixianlu-probe run --upload</code>。
    </p>
  </div>
<?php endif; ?>

<div class="admin-card" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
  <?php foreach ([6 => '6 小时', 24 => '24 小时', 72 => '3 天', 168 => '7 天'] as $value => $label): ?>
    <a class="btn <?= $hours === $value ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/probes.php?hours=<?= $value ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <a class="btn btn--ghost btn--small" href="/api/v1/metrics/latest?hours=<?= $hours ?>" target="_blank" rel="noopener">公开 API ↗</a>
</div>

<div class="admin-stats">
  <div class="admin-stat"><b><?= (int) $stats['reports'] ?></b><span>上报次数（窗口内）</span></div>
  <div class="admin-stat"><b><?= (int) $stats['results'] ?></b><span>探测结果条数</span></div>
  <div class="admin-stat"><b><?= $activeCount ?>/<?= count($tokenRows) ?></b><span>有效探针 token</span></div>
  <div class="admin-stat"><b><?= (int) $totals ?></b><span>历史上报总数</span></div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>发放新 token</h2>
    <form method="post" action="/admin/probes.php" class="admin-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="issue">
      <div class="admin-field">
        <label for="label">备注（机房 / 线路 / 城市）</label>
        <input id="label" name="label" type="text" placeholder="例如：电信-广东-VPS-01" maxlength="120">
      </div>
      <button class="btn btn--primary btn--small" type="submit">生成 token</button>
    </form>
    <p class="admin-hint" style="margin:.6rem 0 0">
      命令行方式：<code>php bin/probe_token.php issue --label="电信-广东-01"</code>。
      数据库只保存 token 的 SHA-256，明文不可找回；泄露请撤销后重新发放。
    </p>
  </div>

  <div class="admin-card">
    <h2>接入参数</h2>
    <div class="admin-kv">
      <span>接收端点</span><b><code><?= e(site_url('/api/v1/metrics/report')) ?></code></b>
      <span>方法</span><b>POST + JSON（协议 v0.2.0）</b>
      <span>鉴权</span><b>Authorization: Bearer &lt;token&gt;</b>
      <span>时间窗</span><b>±<?= (int) probe_setting('PROBE_TIMESTAMP_SKEW_SECONDS', '300') ?> 秒</b>
      <span>最小上报间隔</span><b><?= (int) probe_setting('PROBE_MIN_INTERVAL_SECONDS', '60') ?> 秒</b>
      <span>每小时上限</span><b><?= (int) probe_setting('PROBE_HOURLY_LIMIT', '120') ?> 次</b>
      <span>单次结果上限</span><b><?= (int) probe_setting('PROBE_MAX_RESULTS', '200') ?> 条</b>
      <span>请求体上限</span><b><?= number_format((int) probe_setting('PROBE_MAX_BODY_BYTES', '262144') / 1024, 0) ?> KB</b>
      <span>签名</span><b><?= probe_setting('PROBE_ALLOW_UNSIGNED', '0') === '1' ? '可选（兼容 0.1.x 未签名上报）' : '必需（HMAC-SHA256）' ?></b>
    </div>
    <p class="admin-hint" style="margin:.6rem 0 0">
      快照聚合：<code>php bin/probe_snapshot.php --hours=48</code>（建议每小时 cron），
      <code>--prune-days=30</code> 清理原始明细。
    </p>
  </div>
</div>

<div class="admin-card">
  <h2>线路质量（按 name / model / ISP 聚合，窗口 <?= $hours ?> 小时）</h2>
  <?php if (!$snapshots): ?>
    <div class="admin-empty">该窗口内还没有快照。先用探针上报数据，再执行 <code>php bin/probe_snapshot.php</code>。</div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>目标</th><th>模型</th><th>ISP</th><th>样本</th>
            <th>可达率</th><th>可用率</th><th>平均 TTFB</th><th>P95</th>
            <th>区域拦截</th><th>限流</th><th>最近小时 (UTC)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshots as $row):
              $samples = max(1, (int) $row['samples']);
              $reachableRate = (int) $row['reachable_count'] / $samples * 100;
              $successRate = (int) $row['success_count'] / $samples * 100; ?>
            <tr>
              <td><?= e($row['name']) ?></td>
              <td><?= e($row['model'] ?: '—') ?></td>
              <td><?= e($row['isp'] ?: '未标注') ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.4rem">
                  <span class="bar" style="flex:1"><span class="bar__fill" style="width:<?= (int) round((int) $row['samples'] / $maxSamples * 100) ?>%"></span></span>
                  <?= (int) $row['samples'] ?>
                </div>
              </td>
              <td><?= number_format($reachableRate, 1) ?>%</td>
              <td><?= number_format($successRate, 1) ?>%</td>
              <td><?= $row['avg_latency_ms'] !== null ? number_format((float) $row['avg_latency_ms'], 1) . ' ms' : '—' ?></td>
              <td><?= $row['worst_p95'] !== null ? number_format((float) $row['worst_p95'], 1) . ' ms' : '—' ?></td>
              <td><?= (int) $row['region_blocks'] ?></td>
              <td><?= (int) $row['rate_limits'] ?></td>
              <td style="white-space:nowrap"><?= e($row['last_hour']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="admin-hint" style="margin:.6rem 0 0">
      TTFB 为请求发出到收到响应头的时间（含跳转）；「可达」表示有 HTTP 响应，
      「可用」表示状态码在目标预期范围内。未鉴权的目标只证明网络可达性与错误类型，不代表账号可用。
    </p>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>探针 token</h2>
  <?php if (!$tokenRows): ?>
    <div class="admin-empty">还没有 token。用上方表单或 <code>bin/probe_token.php issue</code> 生成。</div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>probe_id</th><th>备注</th><th>状态</th><th>创建 (UTC)</th><th>最近上报 (UTC)</th><th>操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($tokenRows as $row): ?>
            <tr>
              <td><code style="font-size:.7rem"><?= e($row['probe_id']) ?></code></td>
              <td><?= e($row['label'] ?: '—') ?></td>
              <td><?= $row['status'] === 'active' ? '有效' : '已撤销' ?></td>
              <td style="white-space:nowrap"><?= e($row['created_at']) ?></td>
              <td style="white-space:nowrap"><?= e($row['last_report_at'] ?: '从未上报') ?></td>
              <td>
                <form method="post" action="/admin/probes.php" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="<?= $row['status'] === 'active' ? 'revoke' : 'activate' ?>">
                  <input type="hidden" name="probe_id" value="<?= e($row['probe_id']) ?>">
                  <button class="admin-link-btn <?= $row['status'] === 'active' ? 'admin-danger' : '' ?>" type="submit">
                    <?= $row['status'] === 'active' ? '撤销' : '恢复' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>最近上报</h2>
  <?php if (!$reports): ?>
    <div class="admin-empty">暂无上报记录。</div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th><th>probe_id</th><th>协议</th><th>客户端时间 (UTC)</th>
            <th>接收时间 (UTC)</th><th>ISP / 地区</th><th>结果</th><th>可用</th><th>签名</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $row): ?>
            <tr>
              <td><?= (int) $row['id'] ?></td>
              <td><code style="font-size:.7rem"><?= e($row['probe_id']) ?></code></td>
              <td><?= e($row['protocol_version']) ?></td>
              <td style="white-space:nowrap"><?= e($row['client_ts'] !== '' ? gmdate('Y-m-d H:i:s', (int) strtotime($row['client_ts'])) : '—') ?></td>
              <td style="white-space:nowrap"><?= e($row['received_at']) ?></td>
              <td><?= e(trim((string) $row['isp'] . ' ' . (string) $row['region']) ?: '未标注') ?><?= $row['asn'] ? ' · AS' . (int) $row['asn'] : '' ?></td>
              <td><?= (int) $row['result_count'] ?> / <code style="font-size:.7rem">v<?= e($row['client_version']) ?></code></td>
              <td><?= (int) $row['success_count'] ?>/<?= (int) $row['reachable_count'] ?></td>
              <td><?= (int) $row['signature_ok'] === 1 ? '✓' : '✗（兼容模式）' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
