<?php
/** @var array $providers */
/** @var array $aiTools */
/** @var array $aiNews */
/** @var array $aiNewsStats */
$count = count($providers);
$planTotal = array_sum(array_map(fn ($p) => (int) $p['plan_count'], $providers));
$updated = setting('data_updated_at', date('Y-m-d'));
$featured = array_slice($providers, 0, 6);
$aiTools = $aiTools ?? [];
$aiNews = $aiNews ?? [];
$aiNewsStats = $aiNewsStats ?? ['total' => 0];
$aiToolCount = $aiToolCount ?? count($aiTools);
?>
<section class="hero hero--home">
  <div class="hero__inner">
    <span class="hero__eyebrow">数据更新 <?= e($updated) ?> · <b><?= $count ?> 家机场</b> · <?= $planTotal ?> 个套餐</span>
    <h1 class="hero__title">连上 AI，<em>先选对线路。</em></h1>
    <p class="hero__lead">AI 工具的最新动向、网络要求，与 <?= $count ?> 家机场的价格、线路和地区，在一个页面里看清。</p>

    <form class="search-pill" action="/providers/" method="get" data-home-search>
      <span class="search-pill__icon" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="m13.5 13.5 4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      </span>
      <input type="search" name="q" placeholder="搜索机场、协议或地区，如 Trojan、日本" aria-label="搜索机场">
      <button class="btn btn--primary btn--small" type="submit">搜索</button>
    </form>

    <div class="hero__cta">
      <a class="btn btn--primary" href="/providers/">浏览全部机场</a>
      <a class="btn btn--ghost" href="/news/">看 AI 前线</a>
    </div>

    <div class="hero__stats hero__stats--home">
      <div class="hero__stat"><b><?= $count ?></b><span>在录机场</span></div>
      <div class="hero__stat"><b><?= $planTotal ?></b><span>套餐选项</span></div>
      <div class="hero__stat"><b><?= (int) $aiNewsStats['total'] ?></b><span>AI 事件</span></div>
    </div>
  </div>
</section>

<?php if ($aiNews): ?>
<section class="section section--white">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">AI 前线 · 每日更新</p>
        <h2 class="section__title">今天值得知道的 AI 事件</h2>
        <p class="section__lead">首页只留结论。点开任意一条，查看影响分析、相关工具与原文。</p>
      </div>
      <a class="text-link" href="/news/">全部 <?= (int) $aiNewsStats['total'] ?> 条 →</a>
    </div>

    <?= partial('partials/news-list', ['news' => $aiNews, 'compact' => true]) ?>
  </div>
</section>
<?php endif; ?>

<?php if ($aiTools): ?>
<section class="section section--tight">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">AI 工具网络选型</p>
        <h2 class="section__title">常用 AI 工具，网络要求各不同。</h2>
        <p class="section__lead">先看工具对地区、长连接和账号环境的要求，再缩小机场范围。</p>
      </div>
      <a class="text-link" href="/ai/">全部 <?= (int) $aiToolCount ?> 个工具</a>
    </div>
    <div class="ai-tool-grid ai-tool-grid--home">
      <?php foreach ($aiTools as $tool): ?>
        <article class="ai-tool-card">
          <div class="ai-tool-card__top">
            <span class="badge badge--blue"><?= e(ai_tool_category_label($tool['category'])) ?></span>
            <span class="ai-tool-card__vendor"><?= e($tool['vendor']) ?></span>
          </div>
          <h3><a href="/ai/<?= e($tool['slug']) ?>/"><?= e($tool['name']) ?></a></h3>
          <p class="ai-tool-card__tagline"><?= e($tool['tagline']) ?></p>
          <div class="ai-tool-card__foot">
            <span><?= e($tool['network_traits'][0] ?? '网络选型') ?></span>
            <span class="pcard__arrow">查看 ›</span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section section--white">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">精选机场</p>
        <h2 class="section__title">先看这 6 家。</h2>
        <p class="section__lead">本站综合推荐顺序；勾选「对比」，最多并排比较 4 家。</p>
      </div>
      <a class="text-link" href="/providers/">查看全部 <?= $count ?> 家</a>
    </div>

    <div class="cards">
      <?php foreach ($featured as $p): ?>
        <?= partial('partials/provider-card', ['p' => $p]) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--tight">
  <div class="shell shell--wide">
    <div class="home-cta">
      <a class="home-cta__card" href="/match/">
        <span class="home-cta__eyebrow">智能匹配</span>
        <h2>三步缩小到 4 家</h2>
        <p>按预算、月流量和线路偏好打分，不用逐家翻表。</p>
        <span class="home-cta__link">开始匹配 ›</span>
      </a>
      <a class="home-cta__card home-cta__card--ink" href="/partner/">
        <span class="home-cta__eyebrow">渠道合作</span>
        <h2>把选品变成收入</h2>
        <p>统一入口、独立子 ID、点击与转化留痕，适合社群、内容站与企业客户。</p>
        <span class="home-cta__link">了解代理方案 ›</span>
      </a>
    </div>
  </div>
</section>
