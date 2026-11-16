<?php
/** @var array $p */
$compare = $compare ?? true;
$href = '/providers/' . $p['slug'] . '/';
?>
<article class="pcard" data-slug="<?= e($p['slug']) ?>">
  <div class="pcard__top">
    <span class="pcard__avatar" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
    <div>
      <div class="pcard__name"><a href="<?= e($href) ?>"><?= e($p['name']) ?></a></div>
      <div class="pcard__meta">
        <?= e($p['line_type']) ?>
        <?php if (!empty($p['region_count'])): ?> · <?= (int) $p['region_count'] ?> 个地区<?php endif; ?>
        <?php if (!empty($p['node_count'])): ?> · 节点 <?= e(node_count_label($p)) ?><?php endif; ?>
        <?php if (!empty($p['founded'])): ?> · <?= (int) $p['founded'] ?> 年成立<?php endif; ?>
      </div>
    </div>
  </div>

  <div class="badge-row">
    <span class="badge <?= e(line_badge_class($p['line_type'])) ?>"><?= e($p['line_type']) ?></span>
    <?php foreach (array_slice($p['protocols'], 0, 2) as $protocol): ?>
      <span class="badge"><?= e($protocol) ?></span>
    <?php endforeach; ?>
    <?php if (!empty($p['monthly_offline'])): ?>
      <span class="badge badge--amber">月付下线</span>
    <?php endif; ?>
    <?php if (in_array('免费试用', $p['features'], true)): ?>
      <span class="badge badge--green">可试用</span>
    <?php endif; ?>
  </div>

  <p class="pcard__summary"><?= e($p['summary']) ?></p>

  <div class="pcard__foot">
    <div class="pcard__price">
      <b><?= e(provider_price_label($p)) ?></b>
      <span><?= e(provider_price_note($p)) ?></span>
    </div>
    <?php if ($compare): ?>
      <label class="pcheck">
        <input class="js-compare" type="checkbox" value="<?= e($p['slug']) ?>">
        对比
      </label>
    <?php else: ?>
      <a class="pcard__arrow" href="<?= e($href) ?>">详情 ›</a>
    <?php endif; ?>
  </div>
</article>
