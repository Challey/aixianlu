<?php

declare(strict_types=1);

function admin_flash(string $message, string $type = 'ok'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['admin_flash'] = ['message' => $message, 'type' => $type];
}

function admin_take_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return is_array($flash) ? $flash : null;
}

function admin_header(string $title, string $active = ''): void
{
    $admin = current_admin();
    $nav = [
        ['href' => '/admin/', 'key' => 'dashboard', 'label' => '仪表盘'],
        ['href' => '/admin/providers.php', 'key' => 'providers', 'label' => '机场数据'],
        ['href' => '/admin/affiliates.php', 'key' => 'affiliates', 'label' => '推广链接'],
        ['href' => '/admin/conversions.php', 'key' => 'conversions', 'label' => '佣金'],
        ['href' => '/admin/news.php', 'key' => 'news', 'label' => 'AI 新闻'],
        ['href' => '/admin/agents.php', 'key' => 'agents', 'label' => '代理申请'],
        ['href' => '/admin/clicks.php', 'key' => 'clicks', 'label' => '点击记录'],
        ['href' => '/admin/settings.php', 'key' => 'settings', 'label' => '站点设置'],
        ['href' => '/admin/rebuild.php', 'key' => 'rebuild', 'label' => '构建与导入'],
    ];
    $flash = admin_take_flash();
    ?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title) ?> — AI 线路管理</title>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/site.css">
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin-body">
<header class="admin-top">
  <div class="admin-top__inner">
    <a class="brand" href="/admin/">
      <span class="brand__mark" aria-hidden="true">AI</span>
      <span class="brand__text">AI 线路 <small>管理</small></span>
    </a>
    <div class="admin-top__right">
      <a href="/" target="_blank" rel="noopener">查看前台 ↗</a>
      <?php if ($admin): ?>
        <span class="admin-user"><?= e($admin['username']) ?></span>
        <form action="/admin/logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="admin-link-btn" type="submit">退出</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="admin-shell">
  <aside class="admin-side">
    <nav aria-label="管理导航">
      <?php foreach ($nav as $item): ?>
        <a class="<?= $active === $item['key'] ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-side__note">
      <strong>静态前台</strong>
      <p>修改数据后需重新构建，前台才会更新。</p>
      <a class="text-link" href="/admin/rebuild.php">去构建</a>
    </div>
  </aside>

  <main class="admin-main">
    <div class="admin-head">
      <h1><?= e($title) ?></h1>
    </div>
    <?php if ($flash): ?>
      <div class="admin-flash <?= $flash['type'] === 'error' ? 'is-error' : '' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
<?php
}

function admin_footer(): void
{
    ?>
  </main>
</div>
<script src="/assets/admin.js" defer></script>
</body>
</html>
<?php
}
