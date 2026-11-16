<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();

function conversion_status(string $value): string
{
    $value = strtolower(trim($value));
    return match ($value) {
        'paid', 'settled', '已结算', '已支付', '已到账' => 'paid',
        'rejected', 'invalid', '已拒绝', '无效' => 'rejected',
        'pending', '待结算', '待确认', '未结算', '' => 'pending',
        default => 'pending',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
        redirect('/admin/conversions.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM conversions WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        admin_flash('已删除该条佣金记录。');
        redirect('/admin/conversions.php');
    }

    if ($action === 'add') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $commission = round((float) ($_POST['commission'] ?? 0), 2);
        $status = conversion_status((string) ($_POST['status'] ?? 'pending'));
        $subid = substr(trim((string) ($_POST['subid'] ?? '')), 0, 64);
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200);
        $date = trim((string) ($_POST['created_at'] ?? ''));
        if ($providerId <= 0 || ($amount <= 0 && $commission <= 0)) {
            admin_flash('请选择机场，并至少填写订单金额或佣金。', 'error');
        } else {
            $createdAt = $date !== '' ? date('Y-m-d H:i:s', strtotime($date)) : date('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO conversions (agent_id, provider_id, subid, amount, commission, status, note, created_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$providerId, $subid, $amount, $commission, $status, $note, $createdAt]);
            admin_flash('已添加佣金记录。');
        }
        redirect('/admin/conversions.php');
    }

    if ($action === 'import' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($fh === false) {
            admin_flash('无法读取上传文件。', 'error');
            redirect('/admin/conversions.php');
        }
        $map = [];
        $providers = [];
        foreach ($pdo->query('SELECT id, slug, name FROM providers')->fetchAll() as $row) {
            $providers[strtolower($row['slug'])] = (int) $row['id'];
            $providers[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $inserted = 0;
        $failed = 0;
        $rowNo = 0;
        while (($cols = fgetcsv($fh)) !== false) {
            $rowNo++;
            $cols = array_map(static fn ($v) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $cols);
            if ($rowNo === 1) {
                foreach ($cols as $i => $label) {
                    $key = strtolower($label);
                    $aliases = [
                        'date' => ['date', '日期', '时间', 'created_at', '结算日期'],
                        'provider' => ['provider', 'slug', '机场', '服务商', 'provider_slug'],
                        'amount' => ['amount', '金额', '订单金额', 'gmv'],
                        'commission' => ['commission', '佣金', '返佣', '收益'],
                        'status' => ['status', '状态'],
                        'subid' => ['subid', '子id', '子 ID', '渠道'],
                        'note' => ['note', '备注', '说明'],
                    ];
                    foreach ($aliases as $field => $names) {
                        if (in_array($key, $names, true)) {
                            $map[$field] = $i;
                        }
                    }
                }
                if (!isset($map['provider']) || !isset($map['commission'])) {
                    fclose($fh);
                    admin_flash('CSV 至少需要 provider（机场 slug 或名称）和 commission（佣金）两列。', 'error');
                    redirect('/admin/conversions.php');
                }
                continue;
            }
            $providerKey = strtolower($cols[$map['provider']] ?? '');
            $providerId = $providers[$providerKey] ?? 0;
            if ($providerId <= 0) {
                $failed++;
                continue;
            }
            $amount = isset($map['amount']) ? (float) ($cols[$map['amount']] ?? 0) : 0.0;
            $commission = (float) ($cols[$map['commission']] ?? 0);
            if ($commission <= 0 && $amount <= 0) {
                $failed++;
                continue;
            }
            $rawDate = isset($map['date']) ? (string) ($cols[$map['date']] ?? '') : '';
            $timestamp = $rawDate !== '' ? strtotime($rawDate) : false;
            $createdAt = $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
            $status = conversion_status((string) ($cols[$map['status']] ?? 'pending'));
            $subid = isset($map['subid']) ? substr((string) ($cols[$map['subid']] ?? ''), 0, 64) : '';
            $note = isset($map['note']) ? mb_substr((string) ($cols[$map['note']] ?? ''), 0, 200) : '';
            $pdo->prepare(
                'INSERT INTO conversions (agent_id, provider_id, subid, amount, commission, status, note, created_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$providerId, $subid, $amount, $commission, $status, $note, $createdAt]);
            $inserted++;
        }
        fclose($fh);
        admin_flash('CSV 导入完成：成功 ' . $inserted . ' 条，跳过 ' . $failed . ' 条。');
        redirect('/admin/conversions.php');
    }
}

$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(commission), 0) AS commission,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN commission ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN commission ELSE 0 END), 0) AS paid
     FROM conversions"
)->fetch();
$thisMonth = $pdo->query(
    "SELECT COALESCE(SUM(commission), 0) FROM conversions WHERE created_at >= datetime('now', 'start of month')"
)->fetchColumn();

$rows = $pdo->query(
    "SELECT c.*, p.name AS provider_name, p.slug AS provider_slug
     FROM conversions c LEFT JOIN providers p ON p.id = c.provider_id
     ORDER BY c.created_at DESC, c.id DESC LIMIT 200"
)->fetchAll();
$providerOptions = $pdo->query('SELECT id, name FROM providers ORDER BY name')->fetchAll();

$byProvider = $pdo->query(
    "SELECT p.name, COALESCE(SUM(c.commission), 0) AS commission, COUNT(*) AS n
     FROM conversions c JOIN providers p ON p.id = c.provider_id
     GROUP BY p.id ORDER BY commission DESC LIMIT 10"
)->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="conversions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['date', 'provider', 'amount', 'commission', 'status', 'subid', 'note']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'], $row['provider_slug'] ?: '', $row['amount'], $row['commission'],
            $row['status'], $row['subid'], $row['note'],
        ]);
    }
    fclose($out);
    exit;
}

admin_header('佣金', 'conversions');
?>

<div class="admin-stats">
  <div class="admin-stat"><b><?= e(format_number((float) $summary['commission'], 2)) ?></b><span>累计佣金（元）</span></div>
  <div class="admin-stat"><b><?= e(format_number((float) $summary['pending'], 2)) ?></b><span>待结算</span></div>
  <div class="admin-stat"><b><?= e(format_number((float) $summary['paid'], 2)) ?></b><span>已结算</span></div>
  <div class="admin-stat"><b><?= e(format_number((float) $thisMonth, 2)) ?></b><span>本月新增</span></div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>手动添加</h2>
    <form method="post" action="/admin/conversions.php" class="admin-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <div class="admin-field">
        <label for="provider_id">机场</label>
        <select id="provider_id" name="provider_id" required>
          <option value="">选择机场</option>
          <?php foreach ($providerOptions as $option): ?>
            <option value="<?= (int) $option['id'] ?>"><?= e($option['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="amount">订单金额</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0" placeholder="0.00">
      </div>
      <div class="admin-field">
        <label for="commission">佣金</label>
        <input id="commission" name="commission" type="number" step="0.01" min="0" placeholder="0.00" required>
      </div>
      <div class="admin-field">
        <label for="status">状态</label>
        <select id="status" name="status">
          <option value="pending">待结算</option>
          <option value="paid">已结算</option>
          <option value="rejected">已拒绝</option>
        </select>
      </div>
      <div class="admin-field">
        <label for="created_at">日期</label>
        <input id="created_at" name="created_at" type="date" value="<?= e(date('Y-m-d')) ?>">
      </div>
      <div class="admin-field">
        <label for="subid">子 ID</label>
        <input id="subid" name="subid" type="text" placeholder="可选">
      </div>
      <div class="admin-field full">
        <label for="note">备注</label>
        <input id="note" name="note" type="text" placeholder="套餐、渠道或结算备注">
      </div>
      <div class="admin-field full">
        <button class="btn btn--primary btn--small" type="submit">添加记录</button>
      </div>
    </form>
  </div>

  <div class="admin-card">
    <h2>CSV 导入</h2>
    <p class="admin-hint">表头至少包含 <code>provider</code>（机场 slug 或名称）和 <code>commission</code>；可选 date、amount、status、subid、note。支持中文表头“机场 / 佣金 / 日期 / 状态”。</p>
    <form method="post" action="/admin/conversions.php" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="import">
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <button class="btn btn--primary btn--small" type="submit">导入</button>
    </form>
    <p class="admin-hint" style="margin-top:.7rem">每次结算后从各机场后台导出或手工汇总，导入这里即可看到累计收益。不同机场没有统一 API，这一步无法完全自动化。</p>
    <a class="btn btn--ghost btn--small" href="/admin/conversions.php?export=csv" style="margin-top:.4rem">导出全部记录</a>
  </div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>近 10 家贡献</h2>
    <?php if (!$byProvider): ?>
      <p class="admin-hint">还没有佣金记录。</p>
    <?php else: ?>
      <div class="admin-kv">
        <?php foreach ($byProvider as $row): ?>
          <div><span><?= e($row['name']) ?>（<?= (int) $row['n'] ?> 笔）</span><span><?= e(format_number((float) $row['commission'], 2)) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="admin-card">
    <h2>自动回传（可选）</h2>
    <p class="admin-hint" style="margin:0 0 .5rem;line-height:1.75">
      如果某家机场支持 conversion postback，可把结算结果实时推到本站：
    </p>
    <pre style="margin:0 0 .5rem;padding:.7rem;background:var(--paper);border-radius:10px;font-size:.7rem;line-height:1.6;overflow:auto">POST <?= e(site_url('/api/postback.php')) ?>

token=你的 POSTBACK_TOKEN
provider=机场 slug
commission=佣金
amount=订单金额
subid=子 ID
status=pending|paid|rejected</pre>
    <p class="admin-hint" style="margin:0">
      <?= getenv('POSTBACK_TOKEN') !== '' ? '当前已配置 POSTBACK_TOKEN。' : '当前未配置 POSTBACK_TOKEN，请在 .env 设置后重启 PHP-FPM。' ?>
      多数机场没有 postback，CSV 导入仍是主要方式。
    </p>
  </div>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>日期</th>
        <th>机场</th>
        <th class="num">订单金额</th>
        <th class="num">佣金</th>
        <th>状态</th>
        <th>子 ID</th>
        <th>备注</th>
        <th class="actions">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9"><div class="admin-empty">还没有佣金记录。可以先手动添加一条测试。</div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= (int) $row['id'] ?></td>
          <td style="white-space:nowrap;color:var(--muted-2);font-size:.72rem"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
          <td><?= e($row['provider_name'] ?: '—') ?><div style="color:var(--muted-2);font-size:.68rem"><?= e($row['provider_slug'] ?: '') ?></div></td>
          <td class="num"><?= e(format_number((float) $row['amount'], 2)) ?></td>
          <td class="num"><b><?= e(format_number((float) $row['commission'], 2)) ?></b></td>
          <td>
            <?php if ($row['status'] === 'paid'): ?>
              <span class="badge badge--green">已结算</span>
            <?php elseif ($row['status'] === 'rejected'): ?>
              <span class="badge">已拒绝</span>
            <?php else: ?>
              <span class="badge badge--amber">待结算</span>
            <?php endif; ?>
          </td>
          <td><code style="font-size:.7rem"><?= e($row['subid'] ?: '—') ?></code></td>
          <td style="max-width:16rem;color:var(--muted);font-size:.72rem"><?= e($row['note'] ?: '—') ?></td>
          <td class="actions">
            <form method="post" action="/admin/conversions.php" style="display:inline" data-delete-form data-confirm="确认删除这条佣金记录？">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button type="submit" class="danger">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
