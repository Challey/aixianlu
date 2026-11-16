<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();
$filter = (string) ($_GET['filter'] ?? 'all');
$q = trim((string) ($_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
        redirect('/admin/affiliates.php');
    }

    if (($_POST['action'] ?? '') === 'import' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $slugMap = [];
        foreach ($pdo->query('SELECT id, slug, name FROM providers')->fetchAll() as $row) {
            $slugMap[strtolower($row['slug'])] = (int) $row['id'];
            $slugMap[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $map = [];
        $imported = 0;
        $skipped = 0;
        $rowNo = 0;
        $update = $pdo->prepare(
            'UPDATE providers
             SET affiliate_url = ?, affiliate_code = ?, affiliate_status = ?, affiliate_note = ?, affiliate_checked_at = ?
             WHERE id = ?'
        );
        while ($fh !== false && ($cols = fgetcsv($fh)) !== false) {
            $rowNo++;
            $cols = array_map(static fn ($v) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $cols);
            if ($rowNo === 1) {
                foreach ($cols as $i => $label) {
                    $key = strtolower($label);
                    foreach ([
                        'provider' => ['provider', 'slug', '机场', '服务商'],
                        'url' => ['affiliate_url', 'url', '推广链接', '链接'],
                        'code' => ['affiliate_code', 'code', '邀请码'],
                        'status' => ['affiliate_status', 'status', '状态'],
                        'note' => ['affiliate_note', 'note', '备注'],
                    ] as $field => $names) {
                        if (in_array($key, $names, true)) {
                            $map[$field] = $i;
                        }
                    }
                }
                if (!isset($map['provider']) || !isset($map['url'])) {
                    fclose($fh);
                    admin_flash('CSV 至少需要 provider（slug 或名称）和 affiliate_url 两列。', 'error');
                    redirect('/admin/affiliates.php');
                }
                continue;
            }
            $providerId = $slugMap[strtolower($cols[$map['provider']] ?? '')] ?? 0;
            $url = trim((string) ($cols[$map['url']] ?? ''));
            if ($providerId <= 0 || $url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                $skipped++;
                continue;
            }
            $code = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($cols[$map['code']] ?? '')), 0, 64);
            $status = strtolower((string) ($cols[$map['status']] ?? 'active'));
            $status = match ($status) {
                'active', '推广中', '启用' => 'active',
                'pending', '待审核' => 'pending',
                'paused', '已暂停' => 'paused',
                default => 'none',
            };
            $note = mb_substr(trim((string) ($cols[$map['note']] ?? '')), 0, 200);
            $update->execute([$url, $code, $status, $note, date('Y-m-d'), $providerId]);
            $imported++;
        }
        if ($fh !== false) {
            fclose($fh);
        }
        admin_flash('CSV 导入完成：更新 ' . $imported . ' 条，跳过 ' . $skipped . ' 条。');
        redirect('/admin/affiliates.php');
    }

    $ids = $_POST['provider_id'] ?? [];
    $saved = 0;
    $skipped = 0;
    $update = $pdo->prepare(
        'UPDATE providers
         SET affiliate_url = ?, affiliate_code = ?, affiliate_status = ?, affiliate_note = ?, affiliate_checked_at = ?
         WHERE id = ?'
    );
    foreach ((array) $ids as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            continue;
        }
        $url = trim((string) ($_POST['affiliate_url'][$id] ?? ''));
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
            $skipped++;
            continue;
        }
        $code = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['affiliate_code'][$id] ?? '')), 0, 64);
        $status = (string) ($_POST['affiliate_status'][$id] ?? 'none');
        if (!in_array($status, ['none', 'pending', 'active', 'paused'], true)) {
            $status = 'none';
        }
        $note = mb_substr(trim((string) ($_POST['affiliate_note'][$id] ?? '')), 0, 200);
        $checked = $url !== '' ? date('Y-m-d') : '';
        $update->execute([$url, $code, $status, $note, $checked, $id]);
        $saved++;
    }
    admin_flash('已保存 ' . $saved . ' 条推广链接' . ($skipped > 0 ? '，' . $skipped . ' 条因 URL 格式不正确被跳过' : '') . '。');
    $back = '/admin/affiliates.php?filter=' . urlencode($filter);
    if ($q !== '') {
        $back .= '&q=' . urlencode($q);
    }
    redirect($back);
}

$where = [];
$params = [];
if ($filter === 'missing') {
    $where[] = "affiliate_url = ''";
} elseif (in_array($filter, ['active', 'pending', 'paused', 'none'], true)) {
    $where[] = 'affiliate_status = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $where[] = '(name LIKE ? OR slug LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
$sql = 'SELECT id, slug, name, official_url, affiliate_url, affiliate_code, affiliate_status, affiliate_note, affiliate_checked_at, discount_code
        FROM providers';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY CASE WHEN affiliate_url = \'\' THEN 0 ELSE 1 END, weight DESC, source_order ASC, id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN affiliate_url <> '' THEN 1 ELSE 0 END) AS linked,
            SUM(CASE WHEN affiliate_status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN affiliate_url = '' THEN 1 ELSE 0 END) AS missing,
            SUM(CASE WHEN affiliate_code <> '' THEN 1 ELSE 0 END) AS coded
     FROM providers"
)->fetch();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="affiliates-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['slug', 'name', 'official_url', 'affiliate_url', 'affiliate_code', 'affiliate_status', 'note', 'checked_at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['slug'], $row['name'], $row['official_url'], $row['affiliate_url'],
            $row['affiliate_code'], $row['affiliate_status'], $row['affiliate_note'], $row['affiliate_checked_at'],
        ]);
    }
    fclose($out);
    exit;
}

admin_header('推广链接', 'affiliates');
?>

<div class="admin-stats">
  <div class="admin-stat"><b><?= (int) $stats['total'] ?></b><span>收录机场</span></div>
  <div class="admin-stat"><b><?= (int) $stats['linked'] ?></b><span>已填推广链接</span></div>
  <div class="admin-stat"><b><?= (int) $stats['active'] ?></b><span>推广中</span></div>
  <div class="admin-stat"><b><?= (int) $stats['missing'] ?></b><span>待补链接</span></div>
</div>

<div class="admin-card">
  <h2>怎么填</h2>
  <ol class="admin-hint" style="margin:0;padding-left:1.1rem;line-height:1.75">
    <li>分别登录你已经注册的机场后台，进入“推广 / 联盟 / 邀请”页面，复制你账号下的专属推广链接。</li>
    <li>把链接粘到下面“推广链接”列，邀请码（如果有）粘到“邀请码”列，状态改为“推广中”，保存。</li>
    <li>前台所有“访问官网”按钮都会走 <code>/go/{slug}</code>，自动带上你的推广参数并记录点击，不需要改模板。</li>
  </ol>
  <p class="admin-hint" style="margin:.7rem 0 0">不要在后台或聊天里填写你的登录密码。这里只需要推广链接和邀请码；密码请用密码管理器保管，并给每个机场设置不同密码。</p>
</div>

<div class="admin-card">
  <h2>批量导入 CSV</h2>
  <p class="admin-hint" style="margin:0 0 .6rem">表头至少包含 <code>provider</code>（slug 或机场名）和 <code>affiliate_url</code>；可选 <code>affiliate_code</code>、<code>affiliate_status</code>、<code>affiliate_note</code>。也支持中文表头“机场 / 推广链接 / 邀请码 / 状态 / 备注”。</p>
  <form method="post" action="/admin/affiliates.php" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="import">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <button class="btn btn--primary btn--small" type="submit">导入并覆盖</button>
  </form>
</div>

<div class="admin-card" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
  <?php foreach (['all' => '全部', 'missing' => '待补链接', 'active' => '推广中', 'pending' => '待审核', 'paused' => '已暂停'] as $key => $label): ?>
    <a class="btn <?= $filter === $key ? 'btn--primary' : 'btn--ghost' ?> btn--small" href="/admin/affiliates.php?filter=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <form method="get" action="/admin/affiliates.php" style="display:flex;gap:.4rem;margin-left:auto">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索机场" style="min-height:2.1rem;padding:.3rem .55rem;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:.78rem">
    <button class="btn btn--ghost btn--small" type="submit">搜索</button>
  </form>
  <a class="btn btn--ghost btn--small" href="/admin/affiliates.php?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">导出 CSV</a>
</div>

<form method="post" action="/admin/affiliates.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="filter" value="<?= e($filter) ?>">
  <input type="hidden" name="q" value="<?= e($q) ?>">

  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>#</th>
          <th>机场</th>
          <th>推广链接（优先于官网）</th>
          <th>邀请码</th>
          <th>状态</th>
          <th>备注</th>
          <th>核对</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7"><div class="admin-empty">没有匹配的机场。</div></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= (int) $row['id'] ?></td>
            <td style="min-width:8.5rem">
              <strong><?= e($row['name']) ?></strong>
              <div style="color:var(--muted-2);font-size:.68rem;margin-top:.15rem">/<?= e($row['slug']) ?>/</div>
              <?php if ($row['discount_code'] !== ''): ?>
                <div style="color:var(--muted-2);font-size:.68rem">页面优惠码：<?= e($row['discount_code']) ?></div>
              <?php endif; ?>
            </td>
            <td style="min-width:16rem">
              <input type="hidden" name="provider_id[]" value="<?= (int) $row['id'] ?>">
              <input type="url" name="affiliate_url[<?= (int) $row['id'] ?>]" value="<?= e($row['affiliate_url']) ?>" placeholder="https://…?ref=你的ID" style="width:100%;min-width:15rem;font-size:.72rem;padding:.3rem .4rem;border:1px solid var(--line);border-radius:6px">
              <?php if ($row['official_url'] !== ''): ?>
                <div style="margin-top:.2rem"><a href="<?= e($row['official_url']) ?>" target="_blank" rel="noopener" style="font-size:.66rem">官网 ↗</a></div>
              <?php endif; ?>
            </td>
            <td><input type="text" name="affiliate_code[<?= (int) $row['id'] ?>]" value="<?= e($row['affiliate_code']) ?>" placeholder="可选" style="width:6.5rem;font-size:.72rem;padding:.3rem .4rem;border:1px solid var(--line);border-radius:6px"></td>
            <td>
              <select name="affiliate_status[<?= (int) $row['id'] ?>]" style="font-size:.72rem;padding:.3rem .35rem;border:1px solid var(--line);border-radius:6px">
                <?php foreach (['none' => '未申请', 'pending' => '待审核', 'active' => '推广中', 'paused' => '已暂停'] as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= $row['affiliate_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="affiliate_note[<?= (int) $row['id'] ?>]" value="<?= e($row['affiliate_note']) ?>" placeholder="佣金比例/结算周期" style="width:8rem;font-size:.72rem;padding:.3rem .4rem;border:1px solid var(--line);border-radius:6px"></td>
            <td style="white-space:nowrap;color:var(--muted-2);font-size:.68rem"><?= e($row['affiliate_checked_at'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="admin-card" style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
    <button class="btn btn--primary" type="submit">保存全部</button>
    <span class="admin-hint" style="margin:0">保存后建议到“构建与导入”重建前台，邀请码与 CTA 才会更新。</span>
  </div>
</form>

<?php admin_footer(); ?>
