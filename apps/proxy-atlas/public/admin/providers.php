<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$q = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'weight') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
    } else {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $weight = max(0, min(999, (int) ($_POST['weight'] ?? 0)));
        $stmt = db()->prepare('UPDATE providers SET weight = ?, updated_at = ? WHERE slug = ?');
        $stmt->execute([$weight, date('Y-m-d H:i:s'), $slug]);
        $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $built = false;
        if ($stmt->rowCount() > 0 && $canExec) {
            $cwd = getcwd();
            chdir(APP_ROOT);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/build.php') . ' 2>&1', $out, $status);
            if ($cwd !== false) {
                chdir($cwd);
            }
            $built = $status === 0;
        }
        if ($built) {
            admin_flash('权重已保存并重建前台：首页精选取权重前 6，列表「推荐顺序」按权重排列。');
        } else {
            admin_flash('权重已保存。当前环境无法自动重建，请到「构建与导入」执行 build 后前台生效。');
        }
    }
    header('Location: /admin/providers.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    exit;
}

$params = [];
$sql = 'SELECT * FROM providers';
if ($q !== '') {
    $sql .= ' WHERE name LIKE ? OR slug LIKE ? OR line_type LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY weight DESC, source_order ASC, id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_header('机场数据', 'providers');
?>

<div class="admin-card" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
  <form method="get" action="/admin/providers.php" style="display:flex;gap:.5rem;flex:1;min-width:260px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索名称、slug 或线路" style="flex:1;min-height:2.4rem;padding:.45rem .7rem;border:1px solid var(--line);border-radius:9px;font:inherit">
    <button class="btn btn--ghost btn--small" type="submit">搜索</button>
    <?php if ($q !== ''): ?><a class="btn btn--ghost btn--small" href="/admin/providers.php">清除</a><?php endif; ?>
  </form>
  <a class="btn btn--primary btn--small" href="/admin/provider-edit.php?new=1">新增机场</a>
</div>

<p class="admin-hint" style="margin:.75rem 0 0">
  排序权重越大越靠前：首页精选取前 6 家，「推荐顺序」按权重排序。可直接在表格里改数字点 ✓，保存后自动重建前台。
</p>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>机场</th>
        <th>线路</th>
        <th class="num">套餐</th>
        <th class="num">地区</th>
        <th class="num">入门价</th>
        <th class="num">月均</th>
        <th>状态</th>
        <th class="num">权重</th>
        <th>更新</th>
        <th class="actions">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11"><div class="admin-empty">没有匹配的机场。</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= (int) $row['source_order'] ?></td>
          <td>
            <strong><?= e($row['name']) ?></strong>
            <div style="color:var(--muted-2);font-size:.7rem;margin-top:.15rem">/<?= e($row['slug']) ?>/</div>
          </td>
          <td><?= e($row['line_type']) ?></td>
          <td class="num"><?= (int) $row['plan_count'] ?></td>
          <td class="num"><?= (int) $row['region_count'] ?></td>
          <td class="num"><?= e(provider_price_label($row)) ?></td>
          <td class="num"><?= $row['monthly_price_from'] !== null ? '￥' . format_number((float) $row['monthly_price_from'], 2) : '—' ?></td>
          <td>
            <?php if ($row['status'] === 'published'): ?>
              <span class="badge badge--green">已发布</span>
            <?php else: ?>
              <span class="badge">草稿</span>
            <?php endif; ?>
          </td>
          <td class="num">
            <form method="post" action="/admin/providers.php" class="weight-form">
              <input type="hidden" name="action" value="weight">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="slug" value="<?= e($row['slug']) ?>">
              <input type="hidden" name="q" value="<?= e($q) ?>">
              <input class="weight-input" name="weight" type="number" min="0" max="999" value="<?= (int) $row['weight'] ?>" aria-label="<?= e($row['name']) ?> 排序权重">
              <button class="weight-save" type="submit" title="保存权重并重建">✓</button>
            </form>
          </td>
          <td style="white-space:nowrap;color:var(--muted-2);font-size:.72rem"><?= e(substr((string) $row['updated_at'], 0, 16)) ?></td>
          <td class="actions">
            <a href="/admin/provider-edit.php?slug=<?= e(urlencode($row['slug'])) ?>">编辑</a>
            <a href="/providers/<?= e($row['slug']) ?>/" target="_blank" rel="noopener">前台</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
