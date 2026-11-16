<?php
/** @var array $providers */
$lineCounts = [];
foreach ($providers as $p) {
    $lineCounts[$p['line_type']] = ($lineCounts[$p['line_type']] ?? 0) + 1;
}
$unknown = (int) ($lineCounts['未标注'] ?? 0);
unset($lineCounts['未标注']);
$total = array_sum($lineCounts);
$dedicated = (int) ($lineCounts['专线'] ?? 0);
$mixed = (int) ($lineCounts['专线+中转'] ?? 0);
$relay = (int) ($lineCounts['中转'] ?? 0);
$direct = (int) ($lineCounts['直连'] ?? 0);
$updated = setting('data_updated_at', date('Y-m-d'));
$lineQuery = fn (string $line): string => '/providers/?line=' . urlencode($line);
?>
<div class="shell shell--wide">
  <nav class="page-bar" aria-label="返回导航">
    <a class="page-back" href="/">← 返回首页</a>
    <span class="page-bar__crumb">选择指南</span>
  </nav>

  <header class="page-head guide-head">
    <p class="section__eyebrow">选择指南 · 线路科普</p>
    <h1>VPN 怎么选：直连、中转与专线的真实差别</h1>
    <p>同样的机场，价格能从每月十几块到几百块，差别主要在线路。用三分钟看懂直连、BGP 中转与 IPLC / IEPL 专线，知道钱花在了哪里。</p>
    <p class="guide-updated">更新 <?= e($updated) ?> · 基于本站 <?= $total ?> 家机场的公开信息整理</p>
  </header>

  <div class="guide-quick">
    <div class="guide-quick__card">
      <span class="guide-quick__tag">最便宜</span>
      <h2>直连</h2>
      <p>数据直接走运营商国际出口发往海外，价格低，但晚高峰和敏感时期最看运气。</p>
      <span class="guide-quick__fit">适合：预算优先，能接受波动</span>
    </div>
    <div class="guide-quick__card guide-quick__card--featured">
      <span class="guide-quick__tag">性价比主力</span>
      <h2>中转</h2>
      <p>先到国内入口机房，再走优化隧道出国。比直连稳，成本可控，是多数机场的主力方案。</p>
      <span class="guide-quick__fit">适合：日常浏览、视频与办公</span>
    </div>
    <div class="guide-quick__card">
      <span class="guide-quick__tag">最稳最贵</span>
      <h2>IEPL / IPLC 专线</h2>
      <p>国际段走点对点专线，不过公共国际出口。延迟低、晚高峰稳，代价是价格和流量成本。</p>
      <span class="guide-quick__fit">适合：会议、直播、长期稳定需求</span>
    </div>
  </div>

  <article class="prose guide-prose">
    <h2>三种线路，一次讲清</h2>

    <h3>1. 直连线路：便宜，但把稳定性交给公网</h3>
    <p>客户端的数据直接经国内运营商的国际出口发往海外落地。没有中转机房，也没有专用带宽，所以成本最低。</p>
    <ul>
      <li><b>优点</b>：便宜、结构简单，低价套餐常见。</li>
      <li><b>短板</b>：跨境链路完全走公共互联网，受国际出口拥塞影响直接；晚高峰速度波动明显，遇到网络干扰时也更容易失效。</li>
      <li><b>现状</b>：主流付费机场已很少只做纯直连，纯直连更多见于低价小机场或免费工具。</li>
    </ul>

    <h3>2. 中转线路（BGP / 隧道中转）：性价比主力</h3>
    <p>数据先到国内入口机房，再由入口机经优化隧道转发到海外落地。入口机房通常用 BGP 多线接入，让电信、联通、移动用户都能就近进入，减少本地网络绕路。</p>
    <ul>
      <li><b>稳定性</b>：好于直连。入口就近接入、国际段走优化路径，日常使用更平顺。</li>
      <li><b>晚高峰</b>：仍会受上游带宽和调度影响。同样是“中转”，不同机场的入口质量、中转上游和落地 IP 差别很大。</li>
      <li><b>看什么</b>：入口是否 BGP 多线、中转上游是否稳定、常用地区是否有落地、套餐是否 1 倍率。</li>
    </ul>

    <h3>3. IEPL / IPLC 专线：最稳，也最贵</h3>
    <p>IPLC（国际私有租用电路）和 IEPL（国际以太网专线）都是点对点专线：国际段为物理或逻辑隔离的专用通道，不经过公共互联网国际出口，因此延迟更低、抖动更小，晚高峰通常不掉速。</p>
    <ul>
      <li><b>优点</b>：延迟低且稳定，跨境拥塞与网络干扰的影响显著更小，适合会议、直播、远程办公和长期挂机。</li>
      <li><b>成本</b>：专线带宽按兆计价，价格通常是中转的数倍，最终会体现在套餐价格或流量额度上。</li>
      <li><b>注意</b>：专线指“国际段”。国内入口接入方式、海外落地 IP 和机场调度仍是变量；所谓“不过 GFW”是通俗说法，任何服务都不应承诺永久稳定。</li>
    </ul>

    <h3>4. 混合线路：同一家机场，套餐不同线路也不同</h3>
    <p>不少机场把中转放在入门套餐，把专线留给高阶套餐；也有机场按节点区分线路。购买前要看清楚：专线节点是否包含在当前套餐、是否有倍率、流量额度是多少。本站把这类机场标为“专线+中转”。</p>

    <h2>一张表看懂差别</h2>
    <p class="guide-scroll-hint">左右滑动查看完整表格 →</p>
    <div class="table-wrap guide-table-wrap">
      <table class="data-table guide-table">
        <thead>
          <tr>
            <th>维度</th>
            <th>直连</th>
            <th>中转（BGP / 隧道）</th>
            <th>IPLC / IEPL 专线</th>
          </tr>
        </thead>
        <tbody>
          <tr><th>典型成本</th><td>低</td><td>中</td><td>高</td></tr>
          <tr><th>国际段路径</th><td>公共互联网出口</td><td>入口机房 + 优化隧道</td><td>点对点专线，不过公网</td></tr>
          <tr><th>延迟与抖动</th><td>波动较大</td><td>较稳，晚高峰有损耗</td><td>最低且稳定</td></tr>
          <tr><th>抗干扰</th><td>较弱</td><td>中等，取决于上游</td><td>相对更强</td></tr>
          <tr><th>带宽成本</th><td>低</td><td>中</td><td>高，按兆计价</td></tr>
          <tr><th>适合人群</th><td>预算优先、轻度使用</td><td>日常主力、视频与办公</td><td>会议、直播、长期稳定</td></tr>
        </tbody>
      </table>
    </div>
    <p class="guide-footnote">表中为相对差异，用于理解成本结构，不代表任何一家机场的实际表现。真实体验还取决于本地运营商、时段、落地 IP 与设备。</p>

    <h2>本站已核实线路的 <?= $total ?> 家机场分布</h2>
    <div class="guide-stats">
      <div class="guide-stat"><b><?= $dedicated ?></b><span>纯专线</span></div>
      <div class="guide-stat"><b><?= $mixed ?></b><span>专线 + 中转</span></div>
      <div class="guide-stat"><b><?= $relay ?></b><span>纯中转</span></div>
      <div class="guide-stat"><b><?= $direct ?></b><span>纯直连标签</span></div>
    </div>
    <p>标签来自机场公开描述与套餐信息，同一家机场可能多档套餐、多种线路，别只看一个标签。<?php if ($unknown > 0): ?>另有 <?= $unknown ?> 家机场的线路类型尚未核实，未计入上方统计。<?php endif; ?>想看具体价格与套餐，可以直接按线路筛选。</p>
    <div class="guide-actions">
      <a class="btn btn--primary btn--small" href="<?= e($lineQuery('专线')) ?>">看专线机场</a>
      <a class="btn btn--ghost btn--small" href="<?= e($lineQuery('中转')) ?>">看中转机场</a>
      <a class="btn btn--ghost btn--small" href="/match/">让系统帮我选</a>
    </div>

    <h2>常见误区</h2>
    <ol class="guide-mis">
      <li><b>“专线 = 永不封”</b>国际段是专线，但入口接入、落地 IP 和调度仍是变量。敏感时期任何线路都可能受影响，别把营销话术当承诺。</li>
      <li><b>“贵 = 快”</b>价格里还有流量额度、IP 质量、客服与备用线路。先月付试用，重点测晚高峰的实际速度与延迟。</li>
      <li><b>“BGP = 专线”</b>BGP 是多线接入方式，解决的是“就近入口”；国际段是否专线，要看机场是否明确标注 IEPL / IPLC。</li>
      <li><b>“节点多 = 好用”</b>节点数量只是覆盖范围。常用地区（香港、日本、新加坡、美国）的延迟和带宽更值得看。</li>
      <li><b>“原生 IP = 专线”</b>原生 IP 说的是地址归属与风控画像，和线路类型是两件事，两者可以同时存在，也可以互不相关。</li>
      <li><b>忽略倍率与流量</b>部分节点倍率大于 1，实际消耗流量 = 使用量 × 倍率。专线套餐流量通常更少，购买前算清够不够用。</li>
    </ol>

    <h2>按场景怎么选</h2>
    <div class="guide-fit">
      <div class="guide-fit__item">
        <b>刷网页、看视频、轻度使用</b>
        <p>先从口碑稳定的中转入手，月付试用，晚高峰测一轮速度和延迟，再考虑长期套餐。</p>
      </div>
      <div class="guide-fit__item">
        <b>远程办公、会议、直播</b>
        <p>优先专线，确认常用地区落地、是否 1 倍率、流量额度是否够用；别只看“专线”两个字。</p>
      </div>
      <div class="guide-fit__item">
        <b>多地区、团队或账号风控</b>
        <p>混合线路更灵活，高阶套餐开专线、入门走中转；再备一条不同上游的线路，避免单点故障。</p>
      </div>
    </div>

    <h2>术语表</h2>
    <dl class="guide-terms">
      <div><dt>GFW</dt><dd>中国网络防火墙，对跨境流量进行干扰与封锁。</dd></div>
      <div><dt>BGP</dt><dd>边界网关协议。机房通过多线接入，让不同运营商用户就近进入。</dd></div>
      <div><dt>入口 / 落地</dt><dd>入口是国内接入机房，落地是海外出口节点。</dd></div>
      <div><dt>IPLC</dt><dd>国际私有租用电路，点对点的国际专线。</dd></div>
      <div><dt>IEPL</dt><dd>国际以太网专线，基于以太网的专线，带宽配置更灵活。</dd></div>
      <div><dt>倍率</dt><dd>节点流量计费系数，实际消耗 = 使用量 × 倍率。</dd></div>
      <div><dt>原生 IP</dt><dd>IP 注册地与机房所在地一致的地址，风控画像更接近当地用户，与线路类型无关。</dd></div>
      <div><dt>CN2 GIA</dt><dd>中国电信优质国际出口，属于公网优化线路，比普通出口稳，但不等同专线。</dd></div>
    </dl>

    <div class="callout">
      本站仅整理公开信息，不销售或运营任何网络服务。线路表现受本地网络、时段与运营商影响，购买前请自行核实服务条款与合规要求。
    </div>

    <p class="guide-footnote">下一步：<a href="/providers/">按线路、价格和地区筛选机场</a>，或先看 <a href="/ai/">AI 工具的网络要求</a>。</p>
  </article>
</div>
