<?php
/**
 * 公开探测页：主视觉突出「联合公开探测节点」的速率 / 可靠 / 稳定度。
 * 评分与矩阵口径统一由 app/probe_repository.php 计算，静态烘焙与只读 API 完全一致。
 *
 * @var array $probe_rows
 * @var array $probe_summary
 * @var array $probe_matrix
 * @var array $probe_meta
 * @var array $probe_config
 */
$probeRows = $probe_rows ?? [];
$probeSummary = $probe_summary ?? [];
$probeMeta = $probe_meta ?? [];
$probeConfig = $probe_config ?? [];
$probeMatrix = $probe_matrix ?? probe_status_matrix(24);

$statusTargets = $probeConfig['targets'] ?? [];
$probeWindows = $probeConfig['windows'] ?? [6, 24, 72, 168];
$generatedLabel = trim((string) ($probeMeta['generated_label'] ?? ''));
$ispList = $probeMatrix['isps'] ?? [];
$orderedMatrix = $probeMatrix['rows'] ?? [];

$labelOf = static function (string $name) use ($statusTargets): string {
    $target = $statusTargets[$name] ?? null;
    return is_array($target) && !empty($target['label']) ? (string) $target['label'] : $name;
};
$isControl = static function (string $name) use ($statusTargets): bool {
    return is_array($statusTargets[$name] ?? null) && !empty($statusTargets[$name]['control']);
};
$rateText = static function ($value): string {
    return $value === null ? '—' : rtrim(rtrim(number_format((float) $value * 100, 1, '.', ''), '0'), '.') . '%';
};
$msText = static function ($value): string {
    return $value === null ? '—' : number_format((float) $value, 0, '.', ',') . ' ms';
};
$gradeOf = static function (?int $score): string {
    if ($score === null) {
        return '';
    }
    return $score >= 90 ? 'a' : ($score >= 75 ? 'b' : ($score >= 60 ? 'c' : 'd'));
};
$gradeLabel = ['a' => '稳定', 'b' => '可用', 'c' => '波动', 'd' => '不可靠'];
$badgeByGrade = ['a' => 'badge badge--green', 'b' => 'badge badge--blue', 'c' => 'badge badge--amber', 'd' => 'badge badge--danger'];

$cellHTML = static function (?array $cell, ?array $stability) use ($rateText, $gradeOf): string {
    if (!is_array($cell) || (int) ($cell['samples'] ?? 0) <= 0) {
        return '<div class="status-cell"><b class="status-cell__score is-empty">—</b></div>';
    }
    $score = is_array($stability) && isset($stability['score']) ? (int) $stability['score'] : null;
    $grade = $score === null ? '' : $gradeOf($score);
    $star = is_array($stability) && ($stability['confidence'] ?? 'ok') === 'low' ? '*' : '';
    return '<div class="status-cell is-' . $grade . '">'
        . '<b class="status-cell__score">' . ($score === null ? '—' : $score . $star) . '</b>'
        . '<span class="status-cell__sub">' . $rateText($cell['success_rate'] ?? null) . ' · ' . (int) $cell['samples'] . ' 次</span>'
        . '</div>';
};

$control = is_array($probeSummary['control'] ?? null) ? $probeSummary['control'] : [];
$controlNames = $control['names'] ?? [];
$controlHTML = ($control['samples'] ?? 0) > 0
    ? '<span><b>' . (int) $control['samples'] . '</b> 次对照测量</span> · <span>可达率 <b>'
        . $rateText($control['reachable_rate'] ?? null) . '</b></span> · <span>'
        . e($controlNames ? implode(' / ', array_map(static fn (string $n): string => $labelOf($n), $controlNames)) : '对照组')
        . '：用来区分「目标连不上」与「本机断网」</span>'
    : '<span>对照组：本窗口暂无 GitHub / Cloudflare 对照样本</span>';

$summaryScore = isset($probeSummary['stability_score']) && $probeSummary['stability_score'] !== null
    ? (int) $probeSummary['stability_score']
    : null;
$summaryGrade = $gradeOf($summaryScore);
$summaryConfidence = (string) ($probeSummary['confidence'] ?? 'none');
$thinGroups = (int) ($probeSummary['thin_groups'] ?? 0);
$confidenceText = $summaryConfidence === 'ok'
    ? '样本量足够，评级可用于横向比较'
    : ($summaryConfidence === 'low' ? '样本量偏少，评级仅供方向性参考' : '当前窗口还没有探测样本');
if ($summaryConfidence !== 'none' && $thinGroups > 0) {
    $confidenceText .= '；其中 ' . $thinGroups . ' 组样本不足 5 次（表中带 *）。';
}
?>

<div class="shell shell--wide">
  <header class="page-head">
    <p class="section__eyebrow">联合公开探测</p>
    <h1>AI 端点的速率、可靠度与稳定度</h1>
    <p>由社区公开探测节点从中国大陆实测 OpenAI、Claude、Gemini、Cursor 等端点：多久响应、能不能连上、稳不稳。算法、样本量与时间窗全部公开，任何人可用同一支探针复现或反驳。</p>
  </header>

  <section class="section section--tight" style="padding-top:0">
    <div class="status-hero" data-status-hero>
      <div class="status-hero__score<?= $summaryGrade !== '' ? ' is-' . $summaryGrade : '' ?>" data-status-score-wrap>
        <span class="status-hero__label">综合稳定度</span>
        <div class="status-hero__value">
          <b data-status-score><?= $summaryScore === null ? '—' : $summaryScore ?></b>
          <span class="status-grade" data-status-grade><?= $summaryGrade !== '' ? e(strtoupper($summaryGrade) . ' ' . $gradeLabel[$summaryGrade]) : '待测量' ?></span>
        </div>
        <p class="status-hero__meta" data-status-confidence><?= e($confidenceText) ?></p>
      </div>

      <div class="status-hero__grid">
        <div class="status-kpi">
          <span>可用率</span>
          <b data-status-success><?= e($rateText($probeSummary['success_rate'] ?? null)) ?></b>
          <small>无密钥时 401/403 记为正常应答</small>
        </div>
        <div class="status-kpi">
          <span>可达率</span>
          <b data-status-reachable><?= e($rateText($probeSummary['reachable_rate'] ?? null)) ?></b>
          <small>拿到任意 HTTP 响应</small>
        </div>
        <div class="status-kpi">
          <span>平均 TTFB</span>
          <b data-status-latency><?= e($msText($probeSummary['avg_latency_ms'] ?? null)) ?></b>
          <small>请求到响应头</small>
        </div>
        <div class="status-kpi">
          <span>P95 最差</span>
          <b data-status-p95><?= e($msText($probeSummary['worst_p95_latency_ms'] ?? null)) ?></b>
          <small>慢的时候有多慢</small>
        </div>
      </div>

      <div class="status-hero__side">
        <p class="status-facts">
          <span><b data-status-samples><?= (int) ($probeSummary['samples'] ?? 0) ?></b> 次测量</span> ·
          <span><b data-status-targets><?= (int) ($probeSummary['target_count'] ?? 0) ?></b> 个 AI 端点</span> ·
          <span><b data-status-isps><?= (int) ($probeSummary['isp_count'] ?? 0) ?></b> 家运营商</span> ·
          <span><b><?= (int) ($probeMeta['probes'] ?? 0) ?></b> 台探针（24h）</span>
        </p>
        <p class="status-facts status-facts--control" data-status-control><?= $controlHTML ?></p>
        <form class="status-filters" data-status-controls
          data-api="/api/v1/metrics/latest"
          data-catalog="<?= e(json_encode(['targets' => $statusTargets, 'grades' => $gradeLabel, 'badges' => $badgeByGrade], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
          <div class="status-filters__row">
            <label for="status-window">窗口</label>
            <select id="status-window" data-status-window>
              <?php foreach ($probeWindows as $hours): ?>
                <option value="<?= (int) $hours ?>"<?= (int) $hours === 24 ? ' selected' : '' ?>><?= (int) $hours ?> 小时</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="status-filters__row">
            <label for="status-isp">运营商</label>
            <select id="status-isp" data-status-isp>
              <option value="">全部</option>
              <?php foreach ($ispList as $isp): ?>
                <?php if ($isp !== '未标注'): ?>
                  <option value="<?= e($isp) ?>"><?= e($isp) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="status-filters__row status-filters__row--action">
            <button class="btn btn--primary btn--small" type="button" data-status-refresh>刷新实时数据</button>
          </div>
        </form>
        <p class="status-note" data-status-note></p>
        <p class="status-hero__stamp">静态数据生成于 <?= e($generatedLabel !== '' ? $generatedLabel : '—') ?>（北京时间）；筛选与刷新直接读取聚合接口。</p>
      </div>
    </div>
  </section>

  <section class="section section--tight">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">横向对比</p>
        <h2 class="section__title">端点 × 运营商稳定度</h2>
        <p class="section__lead">同一端点在不同运营商上的差别，往往比宣传参数更真实。数字是稳定度评分，下方依次为可用率与样本数。</p>
      </div>
      <a class="text-link" href="/match/">按探测结果选线路</a>
    </div>

    <?php if (!$orderedMatrix): ?>
      <div class="empty-state"><b>当前窗口还没有探测样本</b>扩大统计窗口，或运行开源探针贡献第一批测量结果。</div>
    <?php else: ?>
      <div class="status-matrix-wrap">
        <div class="empty-state" data-status-matrix-empty hidden>当前筛选没有探测样本</div>
        <table class="status-matrix" data-status-matrix>
          <thead>
            <tr>
              <th class="status-matrix__head" scope="col">探测端点</th>
              <?php if (count($ispList) > 1): ?>
                <th scope="col" class="is-total">全部网络</th>
              <?php endif; ?>
              <?php foreach ($ispList as $isp): ?>
                <th scope="col"><?= e($isp) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody data-status-matrix-body>
            <?php foreach ($orderedMatrix as $entry):
                $name = (string) $entry['name'];
                $model = $entry['model'] !== null && $entry['model'] !== '' ? (string) $entry['model'] : '';
                $sub = $name . ($model !== '' ? ' · ' . $model : '') . (!empty($entry['control']) ? ' · 对照组' : '');
            ?>
              <tr<?= !empty($entry['control']) ? ' class="is-control"' : '' ?>>
                <th scope="row">
                  <?php if (($entry['tool'] ?? '') !== ''): ?>
                    <a class="status-matrix__name" href="/ai/<?= e($entry['tool']) ?>/"><?= e($entry['label']) ?></a>
                  <?php else: ?>
                    <span class="status-matrix__name"><?= e($entry['label']) ?></span>
                  <?php endif; ?>
                  <span class="status-matrix__sub"><?= e($sub) ?></span>
                </th>
                <?php if (count($ispList) > 1): ?>
                  <td class="is-total"><?= $cellHTML($entry['total'] ?? null, $entry['total']['stability'] ?? null) ?></td>
                <?php endif; ?>
                <?php foreach ($ispList as $isp): ?>
                  <?php $cell = $entry['cells'][$isp] ?? null; ?>
                  <td><?= $cellHTML(is_array($cell) ? $cell : null, is_array($cell) ? ($cell['stability'] ?? null) : null) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="status-matrix__legend">
        <span class="status-key is-a">A 稳定 ≥90</span>
        <span class="status-key is-b">B 可用 75–89</span>
        <span class="status-key is-c">C 波动 60–74</span>
        <span class="status-key is-d">D 不可靠 &lt;60</span>
        <span class="status-key is-plain">* 样本少于 5 次，方向性参考</span>
      </p>
    <?php endif; ?>
  </section>

  <section class="section section--tight">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">证据</p>
        <h2 class="section__title">逐端点明细</h2>
        <p class="section__lead">评分只用于横向比较，下结论前先确认样本量与覆盖小时：1 次测量的 100% 与 200 次测量的 100% 不是一回事。明细按样本量由多到少排列，对照组固定在最后，排序不含商业因素。</p>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table data-table--status">
        <thead>
          <tr>
            <th class="col-name" scope="col">目标端点</th>
            <th scope="col">运营商</th>
            <th scope="col" class="num">可用率</th>
            <th scope="col" class="num">可达率</th>
            <th scope="col" class="num">平均 TTFB</th>
            <th scope="col" class="num">P95 最差</th>
            <th scope="col" class="num">样本</th>
            <th scope="col">稳定度</th>
          </tr>
        </thead>
        <tbody data-status-rows>
          <?php if (!$probeRows): ?>
            <tr><td colspan="8"><div class="empty-state"><b>当前窗口还没有探针数据</b>扩大统计窗口，或运行开源探针贡献第一批测量结果。</div></td></tr>
          <?php else: ?>
            <?php foreach ($probeRows as $row):
                $name = (string) $row['name'];
                $model = $row['model'] !== null && $row['model'] !== '' ? (string) $row['model'] : null;
                $stability = is_array($row['stability'] ?? null) ? $row['stability'] : null;
                $score = $stability['score'] ?? null;
                $grade = $score === null ? '' : $gradeOf((int) $score);
                $badgeClass = $grade === '' ? 'badge' : $badgeByGrade[$grade];
                $badgeText = $grade === '' ? '数据不足' : strtoupper($grade) . ' ' . $gradeLabel[$grade] . ' ' . (int) $score . ($stability['confidence'] === 'low' ? '*' : '');
                $flags = [];
                if ((int) ($row['region_block_count'] ?? 0) > 0) {
                    $flags[] = '区域拦截 ' . (int) $row['region_block_count'] . ' 次';
                }
                if ((int) ($row['rate_limit_count'] ?? 0) > 0) {
                    $flags[] = '限流 ' . (int) $row['rate_limit_count'] . ' 次';
                }
            ?>
              <tr>
                <td class="col-name">
                  <b><?= e($labelOf($name)) ?></b>
                  <span class="sub"><?= e($name) ?><?= $model !== null ? ' · ' . e($model) : '' ?><?= $isControl($name) ? ' · 对照组' : '' ?></span>
                </td>
                <td><?php $isp = trim((string) ($row['isp'] ?? '')); echo $isp !== '' ? '<span class="badge badge--line">' . e($isp) . '</span>' : '<span class="sub">未标注</span>'; ?></td>
                <td class="num"><b><?= e($rateText($row['success_rate'] ?? null)) ?></b></td>
                <td class="num"><?= e($rateText($row['reachable_rate'] ?? null)) ?></td>
                <td class="num"><?= e($msText($row['avg_latency_ms'] ?? null)) ?></td>
                <td class="num"><?= e($msText($row['worst_p95_latency_ms'] ?? null)) ?></td>
                <td class="num"><?= (int) ($row['samples'] ?? 0) ?><span class="sub"><?= (int) ($row['hours_covered'] ?? 0) ?> 小时<?= $flags ? ' · ' . e(implode(' · ', $flags)) : '' ?></span></td>
                <td><span class="<?= e($badgeClass) ?>"><?= e($badgeText) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="status-legend">
      未配置 API 密钥时，模型级端点通常返回 <code>401</code> / <code>403</code>：这只能证明<b>网络可达</b>，不代表任何账号可用，也不构成对服务商的结论。
      GitHub 与 Cloudflare 是对照组，用来区分「目标连不上」与「本机断网」。
    </p>
  </section>

  <section class="section section--tight">
    <div class="grid grid--3">
      <div class="feature">
        <span class="feature__num">1</span>
        <h3>算法公开</h3>
        <p>稳定度 = 可用率 50% + 可达率 20% + 延迟一致性 30%（P95 与均值的差距），区域拦截与限流按次扣分；阈值印在本页，也写在协议文档里。</p>
      </div>
      <div class="feature">
        <span class="feature__num">2</span>
        <h3>样本可核</h3>
        <p>每个数字都带样本量、覆盖小时与运营商；上报经签名校验、时间窗、按探针限速与去重，聚合后才公开，不含探针身份、IP 地址与响应正文。</p>
      </div>
      <div class="feature">
        <span class="feature__num">3</span>
        <h3>评分与商业分开</h3>
        <p>站内部分链接为推广链接，注册可能为我们带来佣金。<b>佣金与合作不影响探测评分和排序</b>：评分只来自探针样本，收录标准见关于页。</p>
      </div>
    </div>
  </section>

  <section class="section section--ink status-buy" style="border-radius:var(--radius-lg)">
    <div class="split">
      <div class="split__copy">
        <p class="section__eyebrow" style="color:var(--blue-bright)">透明化购买</p>
        <h2>先看清数据，再决定买哪家</h2>
        <p>探测回答的是「这个端点在你的网络上通不通、稳不稳」，决定日常体验的仍是线路本身。带着这里的结果去匹配候选，再核对机场详情页的线路类型、AI 解锁与证据等级，最后按预算决定。</p>
        <div class="hero__cta" style="justify-content:flex-start;margin-top:1.25rem">
          <a class="btn btn--light" href="/match/">按需求匹配机场</a>
          <a class="btn btn--ghost" href="/providers/" style="color:#fff;border-color:rgba(255,255,255,.3)">查看全部机场</a>
        </div>
      </div>
      <div class="split__panel" style="background:rgba(255,255,255,.06);box-shadow:none;border:1px solid rgba(255,255,255,.12)">
        <h3 style="font-size:.95rem;margin-bottom:.9rem;color:#fff">购买前的四个问题</h3>
        <ul class="status-buy__list">
          <li>你的运营商上，目标端点是哪一档稳定度？</li>
          <li>线路是专线、中转还是混合，是否包含你要的地区？</li>
          <li>套餐倍率与流量是否覆盖实际用量？</li>
          <li>是否支持试用或退款，出问题能否自行取证？</li>
        </ul>
        <p style="margin:1rem 0 0;font-size:.74rem;color:rgba(255,255,255,.55);line-height:1.6">本页只做连通性测量，不代理、不加速、不存储账号信息；结论以你自己的复现结果为准。</p>
      </div>
    </div>
  </section>

  <details class="status-dev">
    <summary>开发者：接入探针、原始接口与协议</summary>
    <div class="status-dev__body">
      <div class="status-steps">
        <div class="status-step"><span class="status-step__num">1</span><div><b>安装并试跑</b><p><code>aixianlu-probe run</code>　本地查看结果，不外发。</p></div></div>
        <div class="status-step"><span class="status-step__num">2</span><div><b>申请上报 token</b><p>后台或 <code>bin/probe_token.php issue</code> 发放，明文只显示一次。</p></div></div>
        <div class="status-step"><span class="status-step__num">3</span><div><b>开启上报</b><p>配置 <code>report.enabled: true</code>，加 <code>--isp 电信 --region 广东</code> 标注线路。</p></div></div>
      </div>
      <p class="status-dev__links">
        <?php $probeRepo = (string) ($probeConfig['repo'] ?? ''); ?>
        <?php if ($probeRepo !== ''): ?>
          <a href="<?= e($probeRepo) ?>" rel="noopener" target="_blank">获取探针（Python，MIT）</a><span>·</span>
        <?php endif; ?>
        <a href="/api/v1/metrics/latest?hours=24" rel="nofollow">原始 JSON 接口</a><span>·</span>
        <a href="https://github.com/Challey/aixianlu/blob/main/probes/PROTOCOL.md" rel="noopener" target="_blank">协议 v0.2.0 与评分口径</a><span>·</span>
        <a href="/guide/">线路类型科普</a>
      </p>
    </div>
  </details>
</div>
