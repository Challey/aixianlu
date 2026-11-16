<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();
ensure_schema(db());

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $action === 'toggle') {
            $pdo->prepare("UPDATE ai_news SET status = CASE WHEN status = 'published' THEN 'draft' ELSE 'published' END WHERE id = ?")->execute([$id]);
            admin_flash('新闻状态已更新。');
        } elseif ($id > 0 && $action === 'delete') {
            $pdo->prepare('DELETE FROM ai_news WHERE id = ?')->execute([$id]);
            admin_flash('新闻已删除。');
        } else {
            admin_flash('未知操作。', 'error');
        }
    }
    redirect('/admin/news.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = [];
if ($q !== '') {
    $where[] = '(title LIKE ? OR summary LIKE ? OR source_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($status, ['published', 'draft'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
$sql = 'SELECT * FROM ai_news';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY published_at DESC, id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
            SUM(CASE WHEN date(published_at) = date('now') THEN 1 ELSE 0 END) AS today
     FROM ai_news"
)->fetch();

admin_header('AI 新闻', 'news');
?>

<div class="admin-stats">
  <div class="admin-stat"><b><?= (int) $counts['total'] ?></b><span>新闻总数</span></div>
  <div class="admin-stat"><b><?= (int) $counts['published'] ?></b><span>已发布</span></div>
  <div class="admin-stat"><b><?= (int) $counts['today'] ?></b><span>今日新增</span></div>
  <div class="admin-stat"><b>11</b><span>采集源</span></div>
</div>

<div class="admin-card">
  <div class="admin-section-title" style="margin-top:0">
    <h2>采集与发布</h2>
    <span class="badge badge--blue">RSS 摘要</span>
  </div>
  <p class="admin-hint" style="margin-top:0">采集器只保存标题、短摘要、来源与原文链接，不保存全文和图片。新采集默认发布，可在这里下线或删除。</p>
  <pre style="margin:0;padding:1rem;background:var(--paper);border-radius:12px;font-size:.75rem;line-height:1.6;overflow:auto">python3 bin/collect_news.py      # 采集最新 AI 新闻
php bin/import_ai.php            # 重建 AI 工具与机场适配
php bin/build.php                # 生成静态页面（含 /news/）</pre>
  <p class="admin-hint" style="margin:.7rem 0 0">建议每天采集一次，采集后重建；也可以放进 cron。</p>
</div>

<div class="admin-card" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
  <form method="get" action="/admin/news.php" style="display:flex;gap:.5rem;flex-wrap:wrap;flex:1;min-width:260px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索标题、摘要或来源" style="flex:1;min-width:180px;min-height:2.4rem;padding:.45rem .7rem;border:1px solid var(--line);border-radius:9px;font:inherit">
    <select name="status" style="min-height:2.4rem;padding:.45rem .7rem;border:1px solid var(--line);border-radius:9px;font:inherit">
      <option value="">全部状态</option>
      <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>已发布</option>
      <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>已下线</option>
    </select>
    <button class="btn btn--ghost btn--small" type="submit">筛选</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn btn--ghost btn--small" href="/admin/news.php">清除</a><?php endif; ?>
  </form>
  <a class="btn btn--primary btn--small" href="/news/" target="_blank" rel="noopener">查看前台 ↗</a>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>时间</th>
        <th>标题</th>
        <th>来源</th>
        <th>分类</th>
        <th>关联工具</th>
        <th>状态</th>
        <th class="actions">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7"><div class="admin-empty">还没有新闻。先在项目目录运行采集命令。</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php $tools = json_arr($row['related_tools'] ?? '[]'); ?>
        <tr>
          <td style="white-space:nowrap;color:var(--muted-2);font-size:.72rem"><?= e(substr((string) $row['published_at'], 0, 16)) ?></td>
          <td style="max-width:420px">
            <a href="<?= e((string) $row['source_url']) ?>" target="_blank" rel="nofollow noopener"><?= e((string) $row['title']) ?></a>
            <?php if (!empty($row['summary'])): ?>
              <div class="admin-hint" style="margin:.2rem 0 0;max-width:520px"><?= e(mb_substr((string) $row['summary'], 0, 90)) ?><?= mb_strlen((string) $row['summary']) > 90 ? '…' : '' ?></div>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap"><?= e((string) $row['source_name']) ?></td>
          <td><span class="badge <?= e(ai_news_category_class((string) $row['category'])) ?>"><?= e(ai_news_category_label((string) $row['category'])) ?></span></td>
          <td style="font-size:.75rem"><?= e($tools ? implode('、', array_map('ai_tool_name', $tools)) : '—') ?></td>
          <td>
            <?php if ($row['status'] === 'published'): ?>
              <span class="badge badge--green">已发布</span>
            <?php else: ?>
              <span class="badge">已下线</span>
            <?php endif; ?>
          </td>
          <td class="actions" style="white-space:nowrap">
            <form method="post" action="/admin/news.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="admin-link-btn" type="submit"><?= $row['status'] === 'published' ? '下线' : '发布' ?></button>
            </form>
            <form method="post" action="/admin/news.php" style="display:inline" data-confirm="确认删除这条新闻？">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="admin-link-btn" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
