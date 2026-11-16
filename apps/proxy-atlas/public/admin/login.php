<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (current_admin()) {
    redirect('/admin/');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = '会话已过期，请重试。';
    } else {
        $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? ['count' => 0, 'first' => time()];
        $attempts = $_SESSION['login_attempts'];
        if ($attempts['count'] >= 8 && time() - $attempts['first'] < 600) {
            $error = '尝试次数过多，请 10 分钟后再试。';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $user['id'];
                unset($_SESSION['login_attempts']);
                redirect('/admin/');
            }
            $_SESSION['login_attempts'] = [
                'count' => $attempts['count'] + 1,
                'first' => $attempts['count'] === 0 ? time() : $attempts['first'],
            ];
            $error = '账号或密码不正确。';
        }
    }
}
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>登录 — AI 线路管理</title>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/site.css">
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<div class="admin-login">
  <form class="admin-login__card" method="post" action="/admin/login.php">
    <h1>AI 线路</h1>
    <p>管理后台 · 仅限授权人员</p>
    <?php if ($error): ?><div class="admin-login__error"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="admin-field">
      <label for="username">账号</label>
      <input id="username" name="username" type="text" autocomplete="username" required autofocus>
    </div>
    <div class="admin-field">
      <label for="password">密码</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn--primary" type="submit">登录</button>
    <div class="admin-login__foot">默认账号 admin / admin，首次登录后请立即修改。</div>
  </form>
</div>
</body>
</html>
