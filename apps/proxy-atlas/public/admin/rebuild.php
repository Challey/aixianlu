<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$output = '';
$ok = true;
$canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $output = "会话已过期，请重试。\n";
        $ok = false;
    } elseif (!$canExec) {
        $output = "当前 PHP 禁用了 exec()，无法从后台执行命令。请在服务器终端运行下面列出的命令。\n";
        $ok = false;
    } else {
        $job = (string) ($_POST['job'] ?? 'build');
        $commands = [];
        if ($job === 'import' || $job === 'all') {
            $commands[] = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/import.php');
        }
        if ($job === 'news' || $job === 'news_all') {
            $commands[] = 'python3 ' . escapeshellarg(APP_ROOT . '/bin/collect_news.py');
        }
        if ($job === 'build' || $job === 'all' || $job === 'news_all') {
            $commands[] = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/build.php');
        }
        $cwd = getcwd();
        chdir(APP_ROOT);
        foreach ($commands as $command) {
            $lines = [];
            $status = 0;
            exec($command . ' 2>&1', $lines, $status);
            $output .= '$ ' . str_replace(APP_ROOT . '/', '', $command) . "\n" . implode("\n", $lines) . "\n\n";
            if ($status !== 0) {
                $ok = false;
                break;
            }
        }
        if ($cwd !== false) {
            chdir($cwd);
        }
        if ($ok) {
            $output .= "完成。\n";
        }
    }
}

$buildFile = APP_ROOT . '/public/index.html';
$buildTime = is_file($buildFile) ? filemtime($buildFile) : 0;
$counts = db()->query("SELECT COUNT(*) AS total, SUM(plan_count) AS plans FROM providers")->fetch();

admin_header('构建与导入', 'rebuild');
?>

<div class="admin-card">
  <h2>当前状态</h2>
  <div class="admin-kv">
    <div><span>机场 / 套餐</span><span><?= (int) $counts['total'] ?> 家 / <?= (int) $counts['plans'] ?> 个</span></div>
    <div><span>最近构建</span><span><?= $buildTime ? e(date('Y-m-d H:i:s', $buildTime)) : '尚未构建' ?></span></div>
    <div><span>exec()</span><span><?= $canExec ? '可用' : '已禁用' ?></span></div>
  </div>
</div>

<div class="admin-two-col">
  <div class="admin-card">
    <h2>重新构建静态站</h2>
    <p class="admin-hint">读取 SQLite 中的已发布机场，生成首页、列表、详情、对比页、sitemap 与 JSON 索引。不会修改数据库。</p>
    <form method="post" action="/admin/rebuild.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="job" value="build">
      <button class="btn btn--primary btn--small" type="submit" <?= $canExec ? '' : 'disabled' ?>>执行构建</button>
    </form>
  </div>

  <div class="admin-card">
    <h2>采集 AI 新闻 + 构建</h2>
    <p class="admin-hint">从公开 RSS 采集标题与短摘要，写入 ai_news，然后重建 /news/ 页面。需要服务器可执行 python3。</p>
    <form method="post" action="/admin/rebuild.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="job" value="news_all">
      <button class="btn btn--primary btn--small" type="submit" <?= $canExec ? '' : 'disabled' ?>>采集并构建</button>
    </form>
  </div>

  <div class="admin-card">
    <h2>从种子重新导入 + 构建</h2>
    <p class="admin-hint">用 data/seed/providers.json 覆盖机场与套餐，然后重建。不会删除代理申请与点击记录；后台手工改过的机场会被种子覆盖。</p>
    <form method="post" action="/admin/rebuild.php" data-confirm="确认用种子数据覆盖机场与套餐？后台手动修改会被覆盖，代理与点击记录保留。">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="job" value="all">
      <button class="btn btn--ghost btn--small" type="submit" <?= $canExec ? '' : 'disabled' ?>>导入并构建</button>
    </form>
  </div>
</div>

<?php if ($output !== ''): ?>
  <div class="admin-card">
    <h2>执行结果 <?= $ok ? '<span class="badge badge--green">成功</span>' : '<span class="badge badge--amber">失败</span>' ?></h2>
    <pre style="margin:0;padding:1rem;background:#1d1d1f;color:#f5f5f7;border-radius:12px;font-size:.75rem;line-height:1.55;overflow:auto;white-space:pre-wrap"><?= e($output) ?></pre>
  </div>
<?php endif; ?>

<div class="admin-card">
  <h2>服务器命令</h2>
  <p class="admin-hint">如果后台无法执行命令，在项目目录运行：</p>
  <pre style="margin:0 0 .8rem;padding:1rem;background:var(--paper);border-radius:12px;font-size:.75rem;line-height:1.6;overflow:auto">php bin/import.php          # 从种子导入（覆盖机场与套餐）
php bin/import_ai.php       # 重建 AI 工具与机场适配，保留“已实测”
python3 bin/collect_news.py # 采集 AI 新闻（公开 RSS）
php bin/build.php           # 重新生成静态站
php bin/scrape.py           # 重新抓取公开数据（需 Python）</pre>
  <p class="admin-hint" style="margin:0">上线或迁移前，记得在“站点设置”把 site_url 改成正式域名，再构建一次。</p>
</div>

<?php admin_footer(); ?>
