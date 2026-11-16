<?php
/** @var array $news */
$compact = $compact ?? false;
?>
<div class="news-feed<?= $compact ? ' news-feed--compact' : '' ?>">
  <?php foreach ($news as $item): ?>
    <?php
      $published = (string) ($item['published_at'] ?? '');
      $timestamp = $published !== '' ? strtotime($published . ' UTC') : false;
      $category = (string) ($item['category'] ?? 'industry');
      $detailUrl = news_url($item);
      $hot = (int) ($item['importance'] ?? 0) >= 95;
      $summary = news_excerpt((string) ($item['summary'] ?? ''), $compact ? 92 : 140);
    ?>
    <article class="news-item news-row<?= $compact ? ' news-row--compact' : '' ?>" data-category="<?= e($category) ?>">
      <div class="news-row__body">
        <div class="news-row__meta">
          <span class="badge <?= e(ai_news_category_class($category)) ?>"><?= e(ai_news_category_label($category)) ?></span>
          <?php if ($hot): ?><span class="badge badge--hot">热点</span><?php endif; ?>
          <?php if ($timestamp): ?>
            <time datetime="<?= e(date('c', $timestamp)) ?>"><?= e(date('m-d H:i', $timestamp)) ?></time>
          <?php endif; ?>
          <?php if (!empty($item['source_name'])): ?>
            <span class="news-row__source"><?= e($item['source_name']) ?></span>
          <?php endif; ?>
        </div>

        <h3 class="news-item__title">
          <a href="<?= e($detailUrl) ?>"><?= e($item['title'] ?? '') ?></a>
        </h3>

        <?php if ($summary !== ''): ?>
          <p class="news-item__summary"><?= e($summary) ?></p>
        <?php endif; ?>
      </div>

      <div class="news-row__side">
        <a class="news-row__more" href="<?= e($detailUrl) ?>">阅读详情 <span aria-hidden="true">›</span></a>
        <?php if (!empty($item['source_url'])): ?>
          <a class="news-row__origin" href="<?= e($item['source_url']) ?>" rel="nofollow noopener" target="_blank">原文 ↗</a>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
