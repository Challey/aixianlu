<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

$admin = require_admin();

$settingKeys = [
    'site_name', 'site_tagline', 'site_description', 'site_url',
    'source_name', 'source_url', 'data_updated_at',
    'affiliate_disclosure', 'partner_contact', 'admin_note',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        admin_flash('会话已过期，请重试。', 'error');
    } elseif (($_POST['action'] ?? '') === 'settings') {
        foreach ($settingKeys as $key) {
            if (array_key_exists($key, $_POST)) {
                put_setting($key, trim((string) $_POST[$key]));
            }
        }
        admin_flash('站点设置已保存。');
    } elseif (($_POST['action'] ?? '') === 'checkout') {
        $raw = trim((string) ($_POST['checkout_templates'] ?? ''));
        if ($raw === '') {
            put_setting('checkout_templates', '');
            admin_flash('购买深链模板已清空。');
        } else {
            $decoded = json_decode($raw, true);
            $ok = is_array($decoded);
            if ($ok) {
                foreach ($decoded as $slug => $tpl) {
                    if (!is_string($slug) || !is_string($tpl) || !preg_match('#^https?://#i', trim($tpl))) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                put_setting('checkout_templates', json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                admin_flash('购买深链模板已保存。重新构建静态站后前台生效。');
            } else {
                admin_flash('模板 JSON 无效：应为 { "机场slug": "https://…{invite}…" } 形式，URL 需以 http(s) 开头。', 'error');
            }
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $stmt = db()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([(int) $admin['id']]);
        $hash = (string) $stmt->fetchColumn();
        if (!password_verify($current, $hash)) {
            admin_flash('当前密码不正确。', 'error');
        } elseif (strlen($new) < 8) {
            admin_flash('新密码至少 8 位。', 'error');
        } elseif ($new !== $confirm) {
            admin_flash('两次输入的新密码不一致。', 'error');
        } else {
            db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $admin['id']]);
            admin_flash('密码已更新。');
        }
    }
    redirect('/admin/settings.php');
}

$dbPath = db_path();
$dbSize = is_file($dbPath) ? filesize($dbPath) : 0;

admin_header('站点设置', 'settings');
?>

<form method="post" action="/admin/settings.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="settings">

  <div class="admin-card">
    <h2>站点信息</h2>
    <div class="admin-form">
      <div class="admin-field">
        <label for="site_name">站点名称</label>
        <input id="site_name" name="site_name" type="text" value="<?= e(setting('site_name', 'AI 线路')) ?>">
      </div>
      <div class="admin-field">
        <label for="site_url">站点 URL（用于 canonical / sitemap）</label>
        <input id="site_url" name="site_url" type="url" value="<?= e(setting('site_url', '')) ?>" placeholder="https://example.com">
        <small>上线前必须改成正式域名，否则 canonical 会指向 localhost。</small>
      </div>
      <div class="admin-field full">
        <label for="site_tagline">副标题</label>
        <input id="site_tagline" name="site_tagline" type="text" value="<?= e(setting('site_tagline', '')) ?>">
      </div>
      <div class="admin-field full">
        <label for="site_description">站点描述</label>
        <textarea id="site_description" name="site_description" rows="2"><?= e(setting('site_description', '')) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="data_updated_at">数据更新时间</label>
        <input id="data_updated_at" name="data_updated_at" type="date" value="<?= e(setting('data_updated_at', date('Y-m-d'))) ?>">
      </div>
      <div class="admin-field">
        <label for="partner_contact">合作联系邮箱</label>
        <input id="partner_contact" name="partner_contact" type="email" value="<?= e(setting('partner_contact', '')) ?>">
      </div>
      <div class="admin-field">
        <label for="source_name">来源名称</label>
        <input id="source_name" name="source_name" type="text" value="<?= e(setting('source_name', '')) ?>">
      </div>
      <div class="admin-field">
        <label for="source_url">来源 URL</label>
        <input id="source_url" name="source_url" type="url" value="<?= e(setting('source_url', '')) ?>">
      </div>
      <div class="admin-field full">
        <label for="affiliate_disclosure">推广声明</label>
        <textarea id="affiliate_disclosure" name="affiliate_disclosure" rows="2"><?= e(setting('affiliate_disclosure', '')) ?></textarea>
      </div>
      <div class="admin-field full">
        <label for="admin_note">后台备注</label>
        <textarea id="admin_note" name="admin_note" rows="2"><?= e(setting('admin_note', '')) ?></textarea>
      </div>
    </div>
    <div class="admin-actions">
      <button class="btn btn--primary" type="submit">保存设置</button>
      <span style="font-size:.72rem;color:var(--muted-2)">保存后需重新构建静态站才会更新前台页脚与 meta。</span>
    </div>
  </div>
</form>

<form class="admin-card" method="post" action="/admin/settings.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="checkout">
  <h2>一键购买深链模板</h2>
  <p class="admin-hint" style="margin:0 0 .8rem">
    为个别机场配置“直达结算/注册页并预填邀请码”的深链。JSON 映射：机场 slug → URL，可用占位符
    <code>{invite}</code>（邀请码）与 <code>{coupon}</code>（优惠码）。配置后该机场详情页按钮变为“一键购买 · 预填邀请码”，
    跳转走 <code>/go/slug?src=buy</code> 并记 campaign=buy；未配置的机场保持原有邀请链接/官网跳转。留空表示全部不启用。
  </p>
  <div class="admin-field full">
    <label for="checkout_templates">模板 JSON</label>
    <textarea id="checkout_templates" name="checkout_templates" rows="8" spellcheck="false" placeholder='{&#10;  "example-slug": "https://panel.example.com/#/register?code={invite}"&#10;}'><?= e(setting('checkout_templates', '')) ?></textarea>
  </div>
  <div class="admin-actions">
    <button class="btn btn--primary" type="submit">保存深链模板</button>
    <span style="font-size:.72rem;color:var(--muted-2)">保存后需重新构建静态站。占位符对应的码为空时该模板自动失效，回退到邀请链接。</span>
  </div>
</form>

<div class="admin-two-col">
  <form class="admin-card" method="post" action="/admin/settings.php">
    <h2>修改密码</h2>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <div class="admin-field" style="margin-bottom:.7rem">
      <label for="current_password">当前密码</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
    </div>
    <div class="admin-field" style="margin-bottom:.7rem">
      <label for="new_password">新密码（至少 8 位）</label>
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
    </div>
    <div class="admin-field" style="margin-bottom:.7rem">
      <label for="confirm_password">确认新密码</label>
      <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
    </div>
    <button class="btn btn--primary btn--small" type="submit">更新密码</button>
    <p class="admin-hint" style="margin:.7rem 0 0">默认密码是 admin，上线前必须修改。也可以通过环境变量 PROXY_ADMIN_PASSWORD 在首次导入时指定。</p>
  </form>

  <div class="admin-card">
    <h2>运行环境</h2>
    <div class="admin-kv">
      <div><span>PHP</span><span><?= e(PHP_VERSION) ?></span></div>
      <div><span>SQLite 驱动</span><span><?= extension_loaded('pdo_sqlite') ? '已启用' : '缺失' ?></span></div>
      <div><span>数据库文件</span><span><?= e(basename($dbPath)) ?></span></div>
      <div><span>数据库大小</span><span><?= e(format_number($dbSize / 1024, 1)) ?> KB</span></div>
      <div><span>当前账号</span><span><?= e($admin['username']) ?></span></div>
      <div><span>时区</span><span><?= e(date_default_timezone_get()) ?></span></div>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
