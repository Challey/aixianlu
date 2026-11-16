<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();
$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
            SUM(plan_count) AS plans
     FROM providers"
)->fetch();
$clicks24 = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE ts >= datetime('now', '-1 day')")->fetchColumn();
$clicks7 = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE ts >= datetime('now', '-7 day')")->fetchColumn();
$agentCounts = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
     FROM agents"
)->fetch();
$affStats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN affiliate_url <> '' THEN 1 ELSE 0 END) AS linked,
            SUM(CASE WHEN affiliate_status = 'active' THEN 1 ELSE 0 END) AS active
     FROM providers"
)->fetch();
$commission = $pdo->query(
    "SELECT COALESCE(SUM(commission), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN commission ELSE 0 END), 0) AS pending
     FROM conversions"
)->fetch();

$recent = $pdo->query(
    "SELECT c.*, p.name AS provider_name, p.slug AS provider_slug
     FROM clicks c LEFT JOIN providers p ON p.id = c.provider_id
     ORDER BY c.id DESC LIMIT 10"
)->fetchAll();

$top = $pdo->query(
    "SELECT p.name, p.slug, COUNT(*) AS n
     FROM clicks c JOIN providers p ON p.id = c.provider_id
     WHERE c.ts >= datetime('now', '-7 day')
     GROUP BY p.id ORDER BY n DESC LIMIT 8"
)->fetchAll();

$buildFile = APP_ROOT . '/public/index.html';
$buildTime = is_file($buildFile) ? filemtime($buildFile) : 0;
$maxUpdated = (string) $pdo->query('SELECT COALESCE(MAX(updated_at), \'\') FROM providers')->fetchColumn();
$updatedLocal = '';
if ($maxUpdated !== '') {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $maxUpdated, new DateTimeZone('UTC'));
    if ($dt) {
        $updatedLocal = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
    }
}
$stale = $buildTime > 0 && $maxUpdated !== '' && strtotime($maxUpdated . ' UTC') > $buildTime;

admin_header('仪表盘', 'dashboard');
?>

<div class="admin-stats">
  <div class="admin-stat">
    <b><?= (int) $counts['total'] ?></b>
    <span>机场总数 · <?= (int) $counts['published'] ?> 已发布</span>
  </div>
  <div class="admin-stat">
    <b><?= (int) $counts['plans'] ?></b>
    <span>套餐选项</span>
  </div>
  <div class="admin-stat">
    <b><?= $clicks24 ?></b>
    <span>近 24 小时点击 · 7 天 <?= $clicks7 ?></span>
  </div>
  <div class="admin-stat">
    <b><?= (int) $agentCounts['total'] ?></b>
    <span>代理申请 · <?= (int) $agentCounts['pending'] ?> 待处理</span>
  </div>
  <div class="admin-stat">
    <b><?= (int) $affStats['linked'] ?>/<?= (int) $affStats['total'] ?></b>
    <span>推广链接 · <?= (int) $affStats['active'] ?> 推广中</span>
  </div>
  <div class="admin-stat">
    <b><?= e(format_number((float) $commission['total'], 2)) ?></b>
    <span>累计佣金 · 待结算 <?= e(format_number((float) $commission['pending'], 2)) ?></span>
  </div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>构建状态</h2>
    <div class="admin-kv">
      <div><span>静态站构建时间</span><span><?= $buildTime ? e(date('Y-m-d H:i', $buildTime)) : '尚未构建' ?></span></div>
      <div><span>数据最后修改</span><span><?= $updatedLocal !== '' ? e($updatedLocal) : '—' ?></span></div>
      <div><span>状态</span><span><?= $stale ? '<span class="badge badge--amber">数据已变更，需重建</span>' : '<span class="badge badge--green">已同步</span>' ?></span></div>
    </div>
    <?php if ($stale): ?>
      <p class="admin-hint" style="margin-top:.8rem">后台修改不会自动反映到前台静态页，请到“构建与导入”执行重建。</p>
      <a class="btn btn--primary btn--small" href="/admin/rebuild.php">去构建</a>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h2>近 7 天热门机场</h2>
    <?php if (!$top): ?>
      <p class="admin-hint">还没有点击数据。前台配置官网链接并产生跳转后，这里会出现排行。</p>
    <?php else: ?>
      <div class="admin-kv">
        <?php foreach ($top as $row): ?>
          <div>
            <span><a href="/providers/<?= e($row['slug']) ?>/" target="_blank" rel="noopener"><?= e($row['name']) ?></a></span>
            <span><?= (int) $row['n'] ?> 次</span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card">
  <div class="admin-section-title" style="margin-top:0">
    <h2>最近点击</h2>
    <a class="text-link" href="/admin/clicks.php">查看全部</a>
  </div>
  <?php if (!$recent): ?>
    <p class="admin-hint" style="margin:0">暂无点击记录。访客通过“访问官网”跳转时会记录机场、时间、设备与来源页；IP 仅保存加盐哈希。</p>
  <?php else: ?>
    <div class="admin-table-wrap" style="box-shadow:none">
      <table class="admin-table" style="min-width:640px">
        <thead>
          <tr>
            <th>时间</th>
            <th>机场</th>
            <th>设备</th>
            <th>来源</th>
            <th>子 ID</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td><?= e($row['ts']) ?></td>
              <td><?= e($row['provider_name'] ?: '已删除') ?></td>
              <td><?= e($row['device'] ?: '—') ?></td>
              <td><?= e($row['referer'] ?: '直接访问') ?></td>
              <td><?= e($row['subid'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>快捷入口</h2>
  <div class="admin-actions" style="margin-top:0">
    <a class="btn btn--primary btn--small" href="/admin/affiliates.php?filter=missing">补推广链接</a>
    <a class="btn btn--ghost btn--small" href="/admin/conversions.php">录入佣金</a>
    <a class="btn btn--ghost btn--small" href="/admin/providers.php">编辑机场数据</a>
    <a class="btn btn--ghost btn--small" href="/admin/agents.php">处理代理申请</a>
    <a class="btn btn--ghost btn--small" href="/admin/settings.php">站点设置</a>
    <a class="btn btn--ghost btn--small" href="/" target="_blank" rel="noopener">打开前台 ↗</a>
  </div>
</div>

<?php admin_footer(); ?>
