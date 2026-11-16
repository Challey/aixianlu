<?php
$siteName = setting('site_name', 'AI 线路');
$tagline = setting('site_tagline', 'AI 工具网络要求与机场对比');
$title = $title ?? $siteName;
$description = $description ?? setting('site_description', '');
$canonical = $canonical ?? '/';
$bodyClass = $body_class ?? '';
$jsonld = $jsonld ?? null;
$ogType = $og_type ?? 'website';
$noindex = $noindex ?? false;
?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <?php if ($noindex): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
  <link rel="canonical" href="<?= e(site_url($canonical)) ?>">
  <meta property="og:type" content="<?= e($ogType) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:url" content="<?= e(site_url($canonical)) ?>">
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta name="theme-color" content="#f5f5f7">
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="alternate" type="application/rss+xml" title="AI 前线" href="/news/feed.xml">
  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>">
  <?php if (is_array($jsonld)): ?>
    <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main">跳到正文</a>
<header class="site-top" data-top>
  <div class="site-top__inner">
    <a class="brand" href="/" aria-label="<?= e($siteName) ?> 首页">
      <span class="brand__mark" aria-hidden="true">AI</span>
      <span class="brand__text"><?= e($siteName) ?></span>
    </a>
    <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-label="打开菜单">
      <span class="nav-toggle__bars" aria-hidden="true"></span>
    </button>
    <nav class="site-nav" data-nav aria-label="主导航">
      <a class="<?= e(active_nav('/providers')) ?>" href="/providers/">机场对比</a>
      <a class="<?= e(active_nav('/ai')) ?>" href="/ai/">AI 工具</a>
      <a class="<?= e(active_nav('/news')) ?>" href="/news/">AI 前线</a>
      <a class="<?= e(active_nav('/guide')) ?>" href="/guide/">选择指南</a>
      <a class="<?= e(active_nav('/match')) ?>" href="/match/">智能匹配</a>
      <a class="<?= e(active_nav('/partner')) ?>" href="/partner/">代理合作</a>
      <a class="<?= e(active_nav('/about')) ?>" href="/about/">关于</a>
      <a class="<?= e(active_nav('/my')) ?>" href="/my/">我的足迹<span class="nav-due" data-fp-due hidden></span></a>
      <a class="btn btn--primary btn--small" href="/compare/" data-compare-link>对比<span data-compare-count>0</span></a>
    </nav>
  </div>
</header>

<main id="main">
  <?php require APP_ROOT . '/templates/' . $content_template . '.php'; ?>
</main>

<footer class="site-foot">
  <div class="site-foot__inner">
    <div class="site-foot__brand">
      <div class="brand brand--foot">
        <span class="brand__mark" aria-hidden="true">AI</span>
        <span class="brand__text"><?= e($siteName) ?></span>
      </div>
      <p><?= e($tagline) ?>。把价格、线路、协议、地区和优惠放在同一张表里，先比较，再决定。</p>
    </div>
    <div class="site-foot__cols">
      <div>
        <h3>浏览</h3>
        <a href="/providers/">全部机场</a>
        <a href="/ai/">AI 工具</a>
        <a href="/news/">AI 前线</a>
        <a href="/guide/">选择指南</a>
        <a href="/compare/">并排对比</a>
        <a href="/match/">智能匹配</a>
        <a href="/my/">我的足迹</a>
      </div>
      <div>
        <h3>合作</h3>
        <a href="/network/">上游网络</a>
        <a href="/partner/">代理合作</a>
        <a href="/about/">关于与来源</a>
      </div>
      <div>
        <h3>数据</h3>
        <span>更新 <?= e(setting('data_updated_at', date('Y-m-d'))) ?></span>
        <a href="<?= e(setting('source_url', 'https://clashios.com/')) ?>" rel="nofollow noopener" target="_blank">评测来源</a>
        <a href="/sitemap.xml">网站地图</a>
      </div>
    </div>
  </div>
  <div class="site-foot__legal">
    <p>本站为独立信息整理与对比站点，与服务商无隶属关系；价格、套餐与可用性以服务商官网为准。<?= e(setting('affiliate_disclosure', '')) ?></p>
    <p>© <?= date('Y') ?> <?= e($siteName) ?> · 请遵守所在地法律法规。</p>
  </div>
</footer>

<dialog class="fp-dialog" id="fp-dialog" aria-labelledby="fp-dialog-title">
  <form class="fp-dialog__form" id="fp-form" novalidate>
    <h2 id="fp-dialog-title">标记已购 / 记到期</h2>
    <p class="fp-dialog__sub" data-fp-dialog-name></p>
    <input type="hidden" id="fp-slug" name="slug">
    <div class="fp-field">
      <label for="fp-period">订阅周期</label>
      <select id="fp-period" name="period">
        <option value="30">月付</option>
        <option value="90">季付</option>
        <option value="180">半年付</option>
        <option value="365">年付</option>
        <option value="custom">自定义日期</option>
      </select>
    </div>
    <div class="fp-field">
      <label for="fp-expires">到期日</label>
      <input type="date" id="fp-expires" name="expires">
    </div>
    <div class="fp-field">
      <label for="fp-note">备注（可选）</label>
      <input type="text" id="fp-note" name="note" maxlength="60" placeholder="如：日本节点、月 100G">
    </div>
    <div class="fp-dialog__actions">
      <button class="btn btn--ghost" type="button" data-fp-cancel>取消</button>
      <button class="btn btn--primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<div class="compare-tray" data-compare-tray hidden>
  <div class="compare-tray__inner">
    <div class="compare-tray__items" data-compare-items></div>
    <div class="compare-tray__actions">
      <button class="btn btn--ghost btn--small" type="button" data-compare-clear>清空</button>
      <a class="btn btn--primary btn--small" href="/compare/" data-compare-go>去对比</a>
    </div>
  </div>
</div>

<script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
