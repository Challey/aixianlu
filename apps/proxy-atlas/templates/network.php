<?php
/** @var array $providers */
$lineCounts = [];
$protocolCounts = [];
$regionSet = [];
$monthlyValues = [];
$trial = 0;
$withUnlock = 0;
$maxRegionProvider = null;
foreach ($providers as $p) {
    $lineCounts[$p['line_type']] = ($lineCounts[$p['line_type']] ?? 0) + 1;
    foreach ($p['protocols'] as $protocol) {
        $protocolCounts[$protocol] = ($protocolCounts[$protocol] ?? 0) + 1;
    }
    foreach ($p['regions'] as $region) {
        $regionSet[$region] = true;
    }
    $monthly = monthly_equiv_cny($p);
    if ($monthly !== null) {
        $monthlyValues[] = $monthly;
    }
    if (in_array('免费试用', $p['features'], true)) {
        $trial++;
    }
    if ($p['media_unlock'] || $p['ai_unlock']) {
        $withUnlock++;
    }
    if ($maxRegionProvider === null || $p['region_count'] > $maxRegionProvider['region_count']) {
        $maxRegionProvider = $p;
    }
}
arsort($lineCounts);
$unverifiedLine = (int) ($lineCounts['未标注'] ?? 0);
unset($lineCounts['未标注']);
$verifiedLine = array_sum($lineCounts);
arsort($protocolCounts);
sort($monthlyValues);
$median = $monthlyValues ? $monthlyValues[(int) floor(count($monthlyValues) / 2)] : null;
$lineMax = max($lineCounts ?: [1]);
$protocolMax = max($protocolCounts ?: [1]);
$lineLabels = [
    '专线' => 'IPLC / IEPL 专线',
    '专线+中转' => '专线 + 中转混合',
    '中转' => '公网隧道中转',
];
?>

<div class="shell shell--wide">
  <header class="page-head">
    <h1>上游网络</h1>
    <p>机场的体验差异，先看线路。这里把 <?= count($providers) ?> 家机场按线路类型、协议与地区覆盖做了聚合，帮你看懂“贵在哪里、便宜在哪里”。</p>
  </header>

  <div class="grid grid--4" style="margin-bottom:2.5rem">
    <div class="fact">
      <span class="fact__label">收录机场</span>
      <span class="fact__value"><?= count($providers) ?> 家</span>
    </div>
    <div class="fact">
      <span class="fact__label">覆盖国家/地区</span>
      <span class="fact__value"><?= count($regionSet) ?> 个</span>
    </div>
    <div class="fact">
      <span class="fact__label">月均中位数</span>
      <span class="fact__value"><?= $median !== null ? '￥' . format_number($median, 2) : '—' ?></span>
    </div>
    <div class="fact">
      <span class="fact__label">提及解锁</span>
      <span class="fact__value"><?= $withUnlock ?> 家 <small>流媒体或 AI</small></span>
    </div>
  </div>

  <section class="section section--tight" style="padding-top:0">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">线路类型</p>
        <h2 class="section__title">三种常见线路，三种成本结构。</h2>
      </div>
      <a class="text-link" href="/guide/">阅读完整线路科普</a>
    </div>
    <div class="grid grid--3">
      <div class="feature">
        <span class="feature__num">A</span>
        <h3>IPLC / IEPL 专线</h3>
        <p>国际段点对点专线，不过公共国际出口。延迟低、晚高峰稳，带宽按兆计价，常见于高端套餐。</p>
      </div>
      <div class="feature">
        <span class="feature__num">B</span>
        <h3>公网隧道中转</h3>
        <p>国内 BGP 入口 + 海外落地，走优化隧道。成本低、覆盖广，晚高峰表现取决于上游质量，是多数机场的主力方案。</p>
      </div>
      <div class="feature">
        <span class="feature__num">C</span>
        <h3>专线 + 中转混合</h3>
        <p>入门套餐走中转，高阶套餐才开放专线。购买前看清专线节点是否包含、是否有倍率。</p>
      </div>
    </div>
  </section>

  <section class="section section--tight">
    <div class="split">
      <div class="split__copy">
        <p class="section__eyebrow">数据分布</p>
        <h2>线路与协议，谁更常见？</h2>
        <p>按已核实线路类型的 <?= (int) $verifiedLine ?> 家机场统计。线路类型来自公开描述与套餐信息，部分机场同时提供专线与中转，归为“专线+中转”。</p>
        <div style="margin-top:1.25rem">
          <?php foreach ($lineCounts as $line => $n): ?>
            <div class="bar-row">
              <span class="bar-row__label"><?= e($lineLabels[$line] ?? $line) ?></span>
              <span class="bar"><span class="bar__fill" style="width:<?= (int) round($n / $lineMax * 100) ?>%"></span></span>
              <span class="bar-row__value"><?= (int) $n ?> 家</span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($unverifiedLine > 0): ?>
          <p style="margin:1rem 0 0;font-size:.74rem;color:var(--muted);line-height:1.6">另有 <?= (int) $unverifiedLine ?> 家机场的线路类型尚未核实，未计入上图分布；可在<a href="/providers/?line=未标注">机场对比</a>中筛选查看。</p>
        <?php endif; ?>
      </div>
      <div class="split__panel">
        <h3 style="font-size:.95rem;margin-bottom:.9rem">协议分布</h3>
        <?php foreach ($protocolCounts as $protocol => $n): ?>
          <div class="bar-row">
            <span class="bar-row__label"><?= e($protocol) ?></span>
            <span class="bar"><span class="bar__fill" style="width:<?= (int) round($n / $protocolMax * 100) ?>%"></span></span>
            <span class="bar-row__value"><?= (int) $n ?></span>
          </div>
        <?php endforeach; ?>
        <p style="margin:1rem 0 0;font-size:.74rem;color:var(--muted);line-height:1.6">
          同一机场可能同时支持多种协议，因此合计会超过机场总数。
        </p>
      </div>
    </div>
  </section>

  <section class="section section--tight">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">地区覆盖</p>
        <h2 class="section__title">节点最多能覆盖多少？</h2>
        <p class="section__lead">当前收录中，地区覆盖最广的是 <?= e($maxRegionProvider['name']) ?>，共 <?= (int) $maxRegionProvider['region_count'] ?> 个地区。多数机场集中在香港、日本、台湾、新加坡、美国五个常用地区。</p>
      </div>
      <?php if ($maxRegionProvider): ?>
        <a class="text-link" href="/providers/<?= e($maxRegionProvider['slug']) ?>/">查看该机场</a>
      <?php endif; ?>
    </div>
    <div class="panel">
      <div class="chips">
        <?php
          $regionList = array_keys($regionSet);
          usort($regionList, function ($a, $b) use ($regionSet) {
              return strcmp($a, $b);
          });
          foreach ($regionList as $region):
        ?>
          <span class="chip"><?= e($region) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section section--ink" style="border-radius:var(--radius-lg);margin-bottom:3rem">
    <div class="shell">
      <div class="split">
        <div class="split__copy">
          <p class="section__eyebrow" style="color:var(--blue-bright)">资源合作</p>
          <h2>你是线路或机场供给方？</h2>
          <p>如果你运营 IPLC / IEPL 专线、BGP 入口、中转资源，或希望把机场接入对比与代理渠道，可以提交资源信息。我们会先做人工核对，再决定是否收录。</p>
          <div class="hero__cta" style="justify-content:flex-start;margin-top:1.25rem">
            <a class="btn btn--light" href="/partner/?type=supply">提交资源</a>
            <a class="btn btn--ghost" href="/about/" style="color:#fff;border-color:rgba(255,255,255,.3)">了解收录标准</a>
          </div>
        </div>
        <div class="split__panel" style="background:rgba(255,255,255,.06);box-shadow:none;border:1px solid rgba(255,255,255,.12)">
          <div class="metric-grid">
            <div class="metric" style="background:rgba(255,255,255,.08)"><b style="color:#fff"><?= count($lineCounts) ?></b><span style="color:rgba(255,255,255,.6)">线路类型</span></div>
            <div class="metric" style="background:rgba(255,255,255,.08)"><b style="color:#fff"><?= count($protocolCounts) ?></b><span style="color:rgba(255,255,255,.6)">协议类型</span></div>
            <div class="metric" style="background:rgba(255,255,255,.08)"><b style="color:#fff"><?= count($regionSet) ?></b><span style="color:rgba(255,255,255,.6)">覆盖地区</span></div>
            <div class="metric" style="background:rgba(255,255,255,.08)"><b style="color:#fff"><?= $trial ?></b><span style="color:rgba(255,255,255,.6)">支持试用</span></div>
          </div>
          <p style="margin:1rem 0 0;font-size:.74rem;color:rgba(255,255,255,.55);line-height:1.6">收录不代表背书；整理信息以公开事实为准，商业合作单独标注。</p>
        </div>
      </div>
    </div>
  </section>
</div>
