<?php
/** @var array $item */
/** @var array $related */
$published = (string) ($item['published_at'] ?? '');
$timestamp = $published !== '' ? strtotime($published . ' UTC') : false;
$category = (string) ($item['category'] ?? 'industry');
$hot = (int) ($item['importance'] ?? 0) >= 95;
$related = $related ?? [];
$tags = $item['tags'] ?? [];
$tools = $item['related_tools'] ?? [];
$summary = trim((string) ($item['summary'] ?? ''));
?>
<div class="page-bar">
  <div class="shell shell--wide">
    <a class="page-back" href="/news/">← 返回 AI 前线</a>
  </div>
</div>

<section class="section section--tight news-detail">
  <div class="shell shell--wide">
    <article class="news-detail__card">
      <div class="news-row__meta">
        <span class="badge <?= e(ai_news_category_class($category)) ?>"><?= e(ai_news_category_label($category)) ?></span>
        <?php if ($hot): ?><span class="badge badge--hot">热点</span><?php endif; ?>
        <?php if ($timestamp): ?>
          <time datetime="<?= e(date('c', $timestamp)) ?>"><?= e(date('Y-m-d H:i', $timestamp)) ?></time>
        <?php endif; ?>
        <?php if (!empty($item['source_name'])): ?>
          <span class="news-row__source"><?= e($item['source_name']) ?></span>
        <?php endif; ?>
      </div>

      <h1 class="news-detail__title"><?= e($item['title'] ?? '') ?></h1>

      <?php if ($summary !== ''): ?>
        <p class="news-detail__summary"><?= nl2br(e($summary)) ?></p>
      <?php endif; ?>

      <div class="news-detail__impact">
        <b>对网络选型的影响</b>
        <p><?= e(ai_news_impact($item)) ?></p>
      </div>

      <?php if ($tools || $tags): ?>
        <div class="news-detail__tags">
          <h2>相关工具与主题</h2>
          <div class="chips">
            <?php foreach ($tools as $toolSlug): ?>
              <a class="chip chip--link" href="/ai/<?= e($toolSlug) ?>/"><?= e(ai_tool_name((string) $toolSlug)) ?></a>
            <?php endforeach; ?>
            <?php foreach ($tags as $tag): ?>
              <span class="chip"><?= e($tag) ?></span>
            <?php endforeach; ?>
            <?php if (!$tools): ?>
              <a class="chip chip--link" href="/ai/">AI 工具网络选型</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="news-detail__actions">
        <?php if (!empty($item['source_url'])): ?>
          <a class="btn btn--primary" href="<?= e($item['source_url']) ?>" rel="nofollow noopener" target="_blank">阅读原文 ↗</a>
        <?php endif; ?>
        <a class="btn btn--ghost" href="/ai/">查看 AI 工具网络要求</a>
      </div>

      <p class="ai-footnote">摘要、分类与影响说明由本站根据公开来源整理，版权归原媒体所有；不转载全文、不配图。涉及价格与服务可用性时，以官方页面为准。</p>
    </article>

    <?php if ($related): ?>
      <div class="news-detail__related">
        <div class="section__head">
          <div>
            <p class="section__eyebrow">继续阅读</p>
            <h2 class="section__title">相关 AI 事件</h2>
          </div>
          <a class="text-link" href="/news/">全部新闻</a>
        </div>
        <?= partial('partials/news-list', ['news' => $related, 'compact' => true]) ?>
      </div>
    <?php endif; ?>

    <div class="news-detail__foot">
      <a class="page-back" href="/news/">← 返回 AI 前线</a>
    </div>
  </div>
</section>
