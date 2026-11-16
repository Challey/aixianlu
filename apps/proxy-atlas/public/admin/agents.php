<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();
$statusFilter = trim((string) ($_GET['status'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM agents WHERE id = ?')->execute([$id]);
            admin_flash('已删除该申请。');
        } elseif ($id > 0) {
            $status = in_array($action, ['pending', 'active', 'rejected'], true) ? $action : 'pending';
            $rate = isset($_POST['commission_rate']) ? max(0, min(0.6, (float) $_POST['commission_rate'])) : 0.1;
            $pdo->prepare('UPDATE agents SET status = ?, commission_rate = ? WHERE id = ?')->execute([$status, $rate, $id]);
            admin_flash('已更新申请状态。');
        }
    }
    redirect('/admin/agents.php' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''));
}

$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
     FROM agents"
)->fetch();

$sql = 'SELECT * FROM agents';
$params = [];
if (in_array($statusFilter, ['pending', 'active', 'rejected'], true)) {
    $sql .= ' WHERE status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY CASE status WHEN \'pending\' THEN 0 WHEN \'active\' THEN 1 ELSE 2 END, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agents = $stmt->fetchAll();

admin_header('代理申请', 'agents');
?>

<div class="admin-stats">
  <div class="admin-stat"><b><?= (int) $counts['total'] ?></b><span>申请总数</span></div>
  <div class="admin-stat"><b><?= (int) $counts['pending'] ?></b><span>待处理</span></div>
  <div class="admin-stat"><b><?= (int) $counts['active'] ?></b><span>已通过</span></div>
  <div class="admin-stat"><b><?= (int) $counts['total'] - (int) $counts['active'] - (int) $counts['pending'] ?></b><span>已拒绝</span></div>
</div>

<div class="admin-card" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
  <a class="btn <?= $statusFilter === '' ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/agents.php">全部</a>
  <a class="btn <?= $statusFilter === 'pending' ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/agents.php?status=pending">待处理</a>
  <a class="btn <?= $statusFilter === 'active' ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/agents.php?status=active">已通过</a>
  <a class="btn <?= $statusFilter === 'rejected' ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/agents.php?status=rejected">已拒绝</a>
  <span style="margin-left:auto;font-size:.75rem;color:var(--muted-2)">代理子 ID 形如 A1B2C3D4，可用于 /go/{slug}?a=CODE 追踪</span>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>渠道 / 邮箱</th>
        <th>类型</th>
        <th>说明</th>
        <th>子 ID</th>
        <th>状态</th>
        <th class="num">比例</th>
        <th>提交时间</th>
        <th class="actions">处理</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$agents): ?>
        <tr><td colspan="9"><div class="admin-empty">暂无代理申请。前台 <a href="/partner/" target="_blank" rel="noopener">/partner/</a> 表单提交后会出现在这里。</div></td></tr>
      <?php endif; ?>
      <?php foreach ($agents as $agent): ?>
        <tr>
          <td><?= (int) $agent['id'] ?></td>
          <td>
            <strong><?= e($agent['name']) ?></strong>
            <div style="color:var(--muted-2);font-size:.7rem;margin-top:.15rem"><?= e($agent['email']) ?></div>
          </td>
          <td><?= e($agent['channel'] ?: '—') ?></td>
          <td style="max-width:280px;white-space:pre-line;color:var(--muted);font-size:.74rem"><?= e(mb_substr((string) $agent['note'], 0, 220)) ?: '—' ?></td>
          <td><code style="font-size:.72rem"><?= e($agent['code']) ?></code></td>
          <td>
            <?php if ($agent['status'] === 'active'): ?>
              <span class="badge badge--green">已通过</span>
            <?php elseif ($agent['status'] === 'rejected'): ?>
              <span class="badge">已拒绝</span>
            <?php else: ?>
              <span class="badge badge--amber">待处理</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= e((string) round((float) $agent['commission_rate'] * 100, 1)) ?>%</td>
          <td style="white-space:nowrap;color:var(--muted-2);font-size:.72rem"><?= e(substr((string) $agent['created_at'], 0, 16)) ?></td>
          <td class="actions">
            <form method="post" action="/admin/agents.php" style="display:inline-flex;align-items:center;gap:.25rem">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $agent['id'] ?>">
              <input type="number" name="commission_rate" step="0.01" min="0" max="0.6" value="<?= e((string) $agent['commission_rate']) ?>" style="width:4rem;font-size:.72rem;padding:.2rem .3rem;border:1px solid var(--line);border-radius:6px">
              <button type="submit" name="action" value="active" style="color:var(--green)">通过</button>
              <button type="submit" name="action" value="rejected" style="color:#b42318">拒绝</button>
            </form>
            <form method="post" action="/admin/agents.php" style="display:inline" data-delete-form data-confirm="确认删除该代理申请？">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $agent['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="danger">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
