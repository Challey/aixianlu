<?php
/**
 * 公开连通性状态页模板（构建时静态烘焙最近 24 小时探针快照，浏览器可再向只读 API 刷新）。
 *
 * @var array $probe_rows
 * @var array $probe_meta
 * @var array $probe_config
 */
$probeRows = $probe_rows ?? [];
$probeMeta = $probe_meta ?? [];
$probeConfig = $probe_config ?? [];

$statusTargets = $probeConfig['targets'] ?? [];
$probeWindows = $probeConfig['windows'] ?? [6, 24, 72, 168];
$generatedLabel = trim((string) ($probeMeta['generated_label'] ?? ''));

$vendorOf = static function (string $name): string {
    if (str_starts_with($name, 'openai-')) {
        return 'OpenAI';
    }
    if (str_starts_with($name, 'claude-')) {
        return 'Anthropic Claude';
    }
    if (str_starts_with($name, 'gemini-')) {
        return 'Google Gemini';
    }
    if (str_starts_with($name, 'cursor-')) {
        return 'Cursor';
    }
    if ($name === 'github-api') {
        return 'GitHub';
    }
    if ($name === 'cloudflare') {
        return 'Cloudflare';
    }
    return $name;
};
$labelOf = static function (string $name, ?string $model) use ($statusTargets, $vendorOf): string {
    $target = $statusTargets[$name] ?? null;
    if (is_array($target) && !empty($target['label'])) {
        return (string) $target['label'];
    }
    return $model !== null && $model !== '' ? $vendorOf($name) . ' · ' . $model : $vendorOf($name);
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
$rateBadge = static function ($reachable, $success): array {
    if ($reachable === null) {
        return ['badge', '数据不足'];
    }
    if ((float) $reachable < 0.5) {
        return ['badge badge--amber', '连接困难'];
    }
    if ($success === null) {
        return ['badge', '可达'];
    }
    if ((float) $success >= 0.95) {
        return ['badge badge--green', '通畅'];
    }
    if ((float) $success >= 0.7) {
        return ['badge badge--amber', '不稳定'];
    }
    return ['badge', '仅可达'];
};

$probeIsp = static function (array $rows): array {
    $values = [];
    foreach ($rows as $row) {
        $isp = trim((string) ($row['isp'] ?? ''));
        if ($isp !== '') {
            $values[$isp] = true;
        }
    }
    $list = array_keys($values);
    sort($list, SORT_STRING);
    return $list;
};
?>

<div class="shell shell--wide">
  <header class="page-head">
    <h1>AI 服务连通性状态</h1>
    <p>本页数据来自社区贡献的开源探针：从中国大陆网络向 OpenAI、Claude、Gemini、Cursor 等端点发起真实请求，记录可达率、可用率与 TTFB。全部结果按小时聚合后公开，不含任何探针身份、IP 地址或响应内容。</p>
  </header>

  <div class="grid grid--4" style="margin-bottom:2rem">
    <div class="fact">
      <span class="fact__label">上报探针</span>
      <span class="fact__value"><?= (int) ($probeMeta['probes'] ?? 0) ?> <small>台 / 24 小时</small></span>
    </div>
    <div class="fact">
      <span class="fact__label">测量次数</span>
      <span class="fact__value"><?= (int) ($probeMeta['samples'] ?? 0) ?> <small>次</small></span>
    </div>
    <div class="fact">
      <span class="fact__label">覆盖运营商</span>
      <span class="fact__value"><?= count($probeIsp($probeRows)) ?> <small>家</small></span>
    </div>
    <div class="fact">
      <span class="fact__label">数据生成</span>
      <span class="fact__value" data-status-updated><?= e($generatedLabel !== '' ? $generatedLabel : '—') ?></span>
    </div>
  </div>

  <section class="section section--tight" style="padding-top:0">
    <form class="panel status-controls" data-status-controls
      data-api="/api/v1/metrics/latest"
      data-catalog="<?= e(json_encode(['targets' => $statusTargets], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
      <div class="field">
        <label for="status-window">统计窗口</label>
        <select id="status-window" data-status-window>
          <?php foreach ($probeWindows as $hours): ?>
            <option value="<?= (int) $hours ?>"<?= (int) $hours === 24 ? ' selected' : '' ?>>最近 <?= (int) $hours ?> 小时</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status-isp">运营商</label>
        <select id="status-isp" data-status-isp>
          <option value="">全部运营商</option>
          <?php foreach ($probeIsp($probeRows) as $isp): ?>
            <option value="<?= e($isp) ?>"><?= e($isp) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status-name">目标端点</label>
        <select id="status-name" data-status-name>
          <option value="">全部端点</option>
          <?php foreach ($probeConfig['names'] ?? [] as $name): ?>
            <option value="<?= e($name) ?>"><?= e($labelOf((string) $name, null)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="status-controls__actions">
        <button class="btn btn--primary btn--small" type="button" data-status-refresh>刷新</button>
        <span class="status-note" data-status-note></span>
      </div>
    </form>

    <p class="status-hint">
      <b>可用率</b>＝返回预期状态码的比例；<b>可达率</b>＝拿到任意 HTTP 响应的比例（401/403 也算可达）；
      <b>TTFB</b>＝发出请求到收到响应头的时间。
    </p>
    <p class="table-hint">左右滑动查看完整表格 →</p>
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
            <th scope="col">状态</th>
          </tr>
        </thead>
        <tbody data-status-rows>
          <?php if (!$probeRows): ?>
            <tr><td colspan="8"><div class="empty-state"><b>当前窗口还没有探针数据</b>扩大统计窗口，或运行开源探针贡献第一批测量结果。</div></td></tr>
          <?php else: ?>
            <?php foreach ($probeRows as $row):
                $name = (string) $row['name'];
                $model = $row['model'] !== null && $row['model'] !== '' ? (string) $row['model'] : null;
                [$badgeClass, $badgeText] = $rateBadge($row['reachable_rate'] ?? null, $row['success_rate'] ?? null);
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
                  <b><?= e($labelOf($name, null)) ?></b>
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
      样本量较小时比例波动很大，请结合“样本 / 覆盖小时”一起看。
      GitHub 与 Cloudflare 是对照组，用于区分“目标被墙”与“本机断网”。
    </p>
  </section>

  <section class="section section--tight">
    <div class="split">
      <div class="split__copy">
        <p class="section__eyebrow">开源探针</p>
        <h2>数据由社区测量，代码可自查</h2>
        <p>探针是一个 Python 命令行工具，默认测量 10 个端点（含 5 个模型级请求），可选上报到本站。设备身份是单向哈希，自动识别运营商时只保留 ISP / 地区 / ASN，<b>不上报 IP</b>；API 密钥只在运行时读取，不写入报告、也不随上报发出。</p>
        <p style="margin-top:.9rem">上报内容经过 HMAC 签名、时间窗校验、按探针限速与去重，聚合后才公开到本页。</p>
        <div class="hero__cta" style="justify-content:flex-start;margin-top:1.25rem">
          <?php $probeRepo = (string) ($probeConfig['repo'] ?? ''); ?>
          <?php if ($probeRepo !== ''): ?>
            <a class="btn btn--primary" href="<?= e($probeRepo) ?>" rel="noopener" target="_blank">获取探针</a>
          <?php endif; ?>
          <a class="btn btn--ghost" href="/api/v1/metrics/latest?hours=24" rel="nofollow">原始 JSON 接口</a>
          <a class="btn btn--ghost" href="/guide/">怎么选线路</a>
        </div>
      </div>
      <div class="split__panel">
        <h3 style="font-size:.95rem;margin-bottom:.9rem">接入三步</h3>
        <div class="status-steps">
          <div class="status-step"><span class="status-step__num">1</span><div><b>安装并试跑</b><p><code>aixianlu-probe run</code>　本地查看结果，不外发。</p></div></div>
          <div class="status-step"><span class="status-step__num">2</span><div><b>申请上报 token</b><p>后台或 <code>bin/probe_token.php issue</code> 发放，明文只显示一次。</p></div></div>
          <div class="status-step"><span class="status-step__num">3</span><div><b>开启上报</b><p>配置 <code>report.enabled: true</code>，加 <code>--isp 电信 --region 广东</code> 标注线路。</p></div></div>
        </div>
        <p style="margin:1rem 0 0;font-size:.74rem;color:var(--muted);line-height:1.6">本页只做连通性测量，不代理、不加速、不存储任何账号信息。</p>
      </div>
    </div>
  </section>
</div>
