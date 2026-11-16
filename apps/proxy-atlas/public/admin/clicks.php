<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();
$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}
$providerFilter = trim((string) ($_GET['provider'] ?? ''));

$where = ["c.ts >= datetime('now', ?)"];
$params = ['-' . $days . ' day'];
if ($providerFilter !== '') {
    $where[] = 'p.slug = ?';
    $params[] = $providerFilter;
}
$whereSql = implode(' AND ', $where);

$summary = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COUNT(DISTINCT c.ip_hash) AS visitors,
            SUM(CASE WHEN c.subid <> '' THEN 1 ELSE 0 END) AS tagged
     FROM clicks c LEFT JOIN providers p ON p.id = c.provider_id
     WHERE {$whereSql}"
);
$summary->execute($params);
$sum = $summary->fetch();

$byProvider = $pdo->prepare(
    "SELECT p.name, p.slug, COUNT(*) AS n
     FROM clicks c JOIN providers p ON p.id = c.provider_id
     WHERE {$whereSql}
     GROUP BY p.id ORDER BY n DESC LIMIT 12"
);
$byProvider->execute($params);
$providers = $byProvider->fetchAll();

$byDay = $pdo->prepare(
    "SELECT substr(c.ts, 1, 10) AS day, COUNT(*) AS n
     FROM clicks c LEFT JOIN providers p ON p.id = c.provider_id
     WHERE {$whereSql}
     GROUP BY day ORDER BY day ASC"
);
$byDay->execute($params);
$daysRows = $byDay->fetchAll();

$recent = $pdo->prepare(
    "SELECT c.*, p.name AS provider_name, p.slug AS provider_slug
     FROM clicks c LEFT JOIN providers p ON p.id = c.provider_id
     WHERE {$whereSql}
     ORDER BY c.id DESC LIMIT 200"
);
$recent->execute($params);
$rows = $recent->fetchAll();

$providerOptions = $pdo->query('SELECT slug, name FROM providers ORDER BY name')->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clicks-' . $days . 'd-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['time', 'provider', 'device', 'agent_id', 'subid', 'campaign', 'referer', 'ip_hash']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['ts'], $row['provider_name'] ?: '', $row['device'], $row['agent_id'] ?: '',
            $row['subid'], $row['campaign'], $row['referer'], $row['ip_hash'],
        ]);
    }
    fclose($out);
    exit;
}

$maxDay = 1;
foreach ($daysRows as $dayRow) {
    $maxDay = max($maxDay, (int) $dayRow['n']);
}

admin_header('点击记录', 'clicks');
?>

<div class="admin-card" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
  <?php foreach ([1 => '24 小时', 7 => '7 天', 30 => '30 天', 90 => '90 天'] as $value => $label): ?>
    <a class="btn <?= $days === $value ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/clicks.php?days=<?= $value ?><?= $providerFilter !== '' ? '&provider=' . urlencode($providerFilter) : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <form method="get" action="/admin/clicks.php" style="margin-left:auto;display:flex;gap:.4rem;align-items:center">
    <input type="hidden" name="days" value="<?= $days ?>">
    <select name="provider" onchange="this.form.submit()" style="min-height:2.2rem;padding:.3rem .6rem;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:.78rem">
      <option value="">全部机场</option>
      <?php foreach ($providerOptions as $option): ?>
        <option value="<?= e($option['slug']) ?>" <?= $providerFilter === $option['slug'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a class="btn btn--ghost btn--small" href="/admin/clicks.php?days=<?= $days ?>&export=csv<?= $providerFilter !== '' ? '&provider=' . urlencode($providerFilter) : '' ?>">导出 CSV</a>
  </form>
</div>

<div class="admin-stats">
  <div class="admin-stat"><b><?= (int) $sum['total'] ?></b><span>点击总数</span></div>
  <div class="admin-stat"><b><?= (int) $sum['visitors'] ?></b><span>去重访客（IP 哈希）</span></div>
  <div class="admin-stat"><b><?= (int) $sum['tagged'] ?></b><span>带子 ID 点击</span></div>
  <div class="admin-stat"><b><?= count($providers) ?></b><span>产生点击的机场</span></div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>按机场</h2>
    <?php if (!$providers): ?>
      <p class="admin-hint" style="margin:0">该时间范围内暂无点击。</p>
    <?php else: ?>
      <?php foreach ($providers as $row): ?>
        <div class="bar-row">
          <span class="bar-row__label"><a href="/admin/clicks.php?days=<?= $days ?>&provider=<?= e(urlencode($row['slug'])) ?>"><?= e($row['name']) ?></a></span>
          <span class="bar"><span class="bar__fill" style="width:<?= (int) round((int) $row['n'] / max(1, (int) $providers[0]['n']) * 100) ?>%"></span></span>
          <span class="bar-row__value"><?= (int) $row['n'] ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h2>按天</h2>
    <?php if (!$daysRows): ?>
      <p class="admin-hint" style="margin:0">该时间范围内暂无点击。</p>
    <?php else: ?>
      <?php foreach ($daysRows as $row): ?>
        <div class="bar-row">
          <span class="bar-row__label"><?= e($row['day']) ?></span>
          <span class="bar"><span class="bar__fill" style="width:<?= (int) round((int) $row['n'] / $maxDay * 100) ?>%"></span></span>
          <span class="bar-row__value"><?= (int) $row['n'] ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>时间 (UTC)</th>
        <th>机场</th>
        <th>设备</th>
        <th>子 ID</th>
        <th>Campaign</th>
        <th>来源页</th>
        <th>IP 哈希</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7"><div class="admin-empty">暂无点击记录。</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td style="white-space:nowrap"><?= e($row['ts']) ?></td>
          <td><?= e($row['provider_name'] ?: '已删除') ?></td>
          <td><?= e($row['device'] ?: '—') ?></td>
          <td><?= e($row['subid'] ?: '—') ?></td>
          <td><?= e($row['campaign'] ?: '—') ?></td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($row['referer'] ?: '直接访问') ?></td>
          <td><code style="font-size:.68rem"><?= e(substr((string) $row['ip_hash'], 0, 12)) ?>…</code></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
