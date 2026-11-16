<?php
/** @var array $tools */
$fitCounts = $fitCounts ?? [];
$news = $news ?? [];
$newsStats = $newsStats ?? [];
$providerCount = (int) ($providerCount ?? 0);
$toolCount = count($tools);
$totalFits = 0;
$sourceFits = 0;
$inferredFits = 0;
foreach ($fitCounts as $counts) {
    $totalFits += (int) $counts['total'];
    $sourceFits += (int) $counts['source'];
    $inferredFits += (int) $counts['inferred'];
}
?>
<section class="hero hero--ai">
  <div class="hero__inner">
    <span class="hero__eyebrow">AI 工具网络选型 · 独立整理</span>
    <h1 class="hero__title">AI 工具<em>先看网络要求。</em></h1>
    <p class="hero__lead">ChatGPT、Claude、Gemini、Cursor、Qoder 国际版……不同工具对地区、延迟、长连接和账号环境的要求并不一样。先理解要求，再选机场。</p>
    <div class="hero__cta">
      <a class="btn btn--primary" href="/providers/">浏览机场</a>
      <a class="btn btn--ghost" href="/news/">看 AI 前线</a>
    </div>
    <p class="hero__note">页面只做网络需求整理与方案对比，不冒充任何 AI 工具官方说明。</p>
    <div class="hero__stats">
      <div class="hero__stat"><b><?= $toolCount ?></b><span>AI 工具</span></div>
      <div class="hero__stat"><b><?= $providerCount ?></b><span>候选机场</span></div>
      <div class="hero__stat"><b><?= $sourceFits ?></b><span>来源提及</span></div>
      <div class="hero__stat"><b><?= $inferredFits ?></b><span>条件推断</span></div>
    </div>
  </div>
</section>

<section class="section section--tight">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">工具索引</p>
        <h2 class="section__title">先选工具，再看机场。</h2>
        <p class="section__lead">每个工具页都包含网络要求、常用出口地区、候选机场和证据等级。证据不足的地方会明确标注，不写成“稳定解锁”。</p>
      </div>
      <a class="text-link" href="/providers/">全部机场</a>
    </div>

    <div class="ai-tool-grid">
      <?php foreach ($tools as $tool): ?>
        <?php $counts = $fitCounts[$tool['slug']] ?? ['total' => 0, 'source' => 0, 'inferred' => 0, 'verified' => 0]; ?>
        <article class="ai-tool-card">
          <div class="ai-tool-card__top">
            <span class="badge badge--blue"><?= e(ai_tool_category_label($tool['category'])) ?></span>
            <span class="ai-tool-card__vendor"><?= e($tool['vendor']) ?></span>
          </div>
          <h3><a href="/ai/<?= e($tool['slug']) ?>/"><?= e($tool['name']) ?></a></h3>
          <p class="ai-tool-card__tagline"><?= e($tool['tagline']) ?></p>
          <p class="ai-tool-card__summary"><?= e($tool['summary']) ?></p>
          <div class="ai-tool-card__foot">
            <span><?= (int) $counts['source'] ?> 家来源提及 · <?= (int) $counts['inferred'] ?> 家条件推断</span>
            <span class="pcard__arrow">查看 ›</span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--white">
  <div class="shell">
    <div class="section__head section--center" style="display:block;text-align:center">
      <p class="section__eyebrow">证据等级</p>
      <h2 class="section__title">把“能用”拆成三种证据。</h2>
    </div>
    <div class="grid grid--3">
      <div class="feature">
        <span class="badge badge--green">已实测</span>
        <h3 style="margin-top:.7rem">独立验证过</h3>
        <p>在固定时间、固定节点下做过可用性检查。会记录日期、地区和测试方式，结果可能随平台策略变化。</p>
      </div>
      <div class="feature">
        <span class="badge badge--blue">来源提及</span>
        <h3 style="margin-top:.7rem">来源页面写过</h3>
        <p>机场自己的介绍或公开评测提到支持该工具。我们只做事实摘录，不等于独立复现。</p>
      </div>
      <div class="feature">
        <span class="badge badge--amber">条件推断</span>
        <h3 style="margin-top:.7rem">按网络条件匹配</h3>
        <p>根据线路类型、地区覆盖、节点规模和现有 AI 提及推断，仅作为候选，需自行验证。</p>
      </div>
    </div>
  </div>
</section>

<?php if ($news): ?>
<section class="section">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">AI 前线</p>
        <h2 class="section__title">最近发生的 AI 事件</h2>
        <p class="section__lead">每天从公开 RSS 采集标题与短摘要，保留原文链接；只做与网络选型相关的简短影响说明。</p>
      </div>
      <a class="text-link" href="/news/">全部 AI 新闻</a>
    </div>
    <?= partial('partials/news-list', ['news' => array_slice($news, 0, 5), 'compact' => true]) ?>
  </div>
</section>
<?php endif; ?>

<section class="section section--white">
  <div class="shell">
    <div class="section__head section--center" style="display:block;text-align:center">
      <p class="section__eyebrow">使用方式</p>
      <h2 class="section__title">三步完成选型。</h2>
    </div>
    <div class="grid grid--3">
      <div class="feature">
        <span class="feature__num">1</span>
        <h3>确认工具要求</h3>
        <p>看官方可用地区、是否依赖长连接、是否上传大文件，以及账号对登录环境是否敏感。</p>
      </div>
      <div class="feature">
        <span class="feature__num">2</span>
        <h3>筛候选机场</h3>
        <p>优先看“来源提及”，再看“条件推断”。结合线路、地区、节点和试用政策缩小范围。</p>
      </div>
      <div class="feature">
        <span class="feature__num">3</span>
        <h3>短周期验证</h3>
        <p>先买最短周期或试用，在自己的账号、设备和时段下验证，再考虑长期套餐。</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--tight">
  <div class="shell">
    <div class="callout">
      <strong>说明：</strong>本站与 OpenAI、Anthropic、Google、Anysphere、GitHub、阿里等公司均无隶属关系。AI 工具的可用地区、账号政策与价格以其官方页面为准；机场的“来源提及”与“条件推断”都不构成持续可用承诺。
    </div>
  </div>
</section>
