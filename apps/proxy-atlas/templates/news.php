<?php
/** @var array $news */
$stats = $stats ?? ['total' => 0, 'today' => 0, 'week' => 0, 'sources' => 0];
$categories = [];
foreach ($news as $item) {
    $category = (string) ($item['category'] ?? 'industry');
    $categories[$category] = ($categories[$category] ?? 0) + 1;
}
?>
<div class="page-bar">
  <div class="shell shell--wide">
    <a class="page-back" href="/">← 返回首页</a>
  </div>
</div>

<section class="hero hero--ai hero--compact">
  <div class="hero__inner">
    <span class="hero__eyebrow">AI 前线 · 公开 RSS 聚合</span>
    <h1 class="hero__title">AI 在变<em>网络要求也在变。</em></h1>
    <p class="hero__lead">每天采集公开 RSS 的 AI 事件，只保留标题、短摘要和原文链接；再按工具与主题，关联到机场选型。</p>
    <div class="hero__cta">
      <a class="btn btn--primary" href="/ai/">AI 工具选型</a>
      <a class="btn btn--ghost" href="/news/feed.xml">RSS 订阅</a>
    </div>
    <p class="hero__note">新闻版权归原媒体所有；本站不转载全文、不配图，只做摘要与链接。</p>
    <div class="hero__stats">
      <div class="hero__stat"><b><?= (int) $stats['total'] ?></b><span>已收录</span></div>
      <div class="hero__stat"><b><?= (int) $stats['today'] ?></b><span>今日新增</span></div>
      <div class="hero__stat"><b><?= (int) $stats['week'] ?></b><span>近 7 天</span></div>
      <div class="hero__stat"><b><?= (int) $stats['sources'] ?></b><span>来源媒体</span></div>
    </div>
  </div>
</section>

<section class="section section--tight">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">最新事件</p>
        <h2 class="section__title">按时间倒序</h2>
        <p class="section__lead">分类与“影响”说明由规则生成，只用于把事件和网络选型关联起来，不构成投资或采购建议。</p>
      </div>
      <a class="text-link" href="/ai/">AI 工具页</a>
    </div>

    <?php if (!$news): ?>
      <div class="panel">
        <p style="margin:0;color:var(--muted);font-size:.9rem">还没有采集到新闻。运行 <code>python3 bin/collect_news.py</code>，然后重新构建静态站。</p>
      </div>
    <?php else: ?>
      <div class="news-filter" data-news-filter-bar>
        <button class="news-filter__btn is-active" type="button" data-news-filter="all">全部 <span><?= count($news) ?></span></button>
        <?php foreach ($categories as $category => $count): ?>
          <button class="news-filter__btn" type="button" data-news-filter="<?= e($category) ?>"><?= e(ai_news_category_label($category)) ?> <span><?= (int) $count ?></span></button>
        <?php endforeach; ?>
      </div>

      <?= partial('partials/news-list', ['news' => $news, 'compact' => false]) ?>

      <p class="ai-footnote">列表只展示最近采集的内容。若原文需要登录、付费或已删除，请以原媒体页面为准。</p>
    <?php endif; ?>
  </div>
</section>
