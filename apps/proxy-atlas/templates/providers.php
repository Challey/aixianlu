<?php
/** @var array $providers */
$lines = [];
$protocols = [];
$regions = [];
foreach ($providers as $p) {
    $lines[$p['line_type']] = ($lines[$p['line_type']] ?? 0) + 1;
    foreach ($p['protocols'] as $protocol) {
        $protocols[$protocol] = ($protocols[$protocol] ?? 0) + 1;
    }
    foreach ($p['regions'] as $region) {
        $regions[$region] = ($regions[$region] ?? 0) + 1;
    }
}
arsort($lines);
arsort($protocols);
arsort($regions);
$featureTags = ['免费试用', '不限时流量包', '通用订阅', '不限设备', '定制客户端', '加密货币', '家宽/原生 IP', '团队/企业', '邀请制'];
?>

<div class="shell shell--wide">
  <header class="page-head">
    <h1>机场对比</h1>
    <p><?= count($providers) ?> 家机场放在一张表里：入门价、月均成本、流量、线路与节点规模。搜索、筛选、排序、对比，一页完成。</p>
  </header>

  <div class="toolbar">
    <div class="toolbar__row toolbar__row--main">
      <label class="toolbar__search">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="m13.5 13.5 4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input type="search" placeholder="搜索名称、协议、地区、特性" aria-label="搜索机场" data-filter-search>
      </label>
      <select data-filter-sort aria-label="排序">
        <option value="default">推荐顺序</option>
        <option value="price-asc">月均从低到高</option>
        <option value="price-desc">月均从高到低</option>
        <option value="unit-asc">单位流量价最低</option>
        <option value="traffic-desc">月流量最大</option>
        <option value="regions-desc">地区最多</option>
        <option value="nodes-desc">节点最多</option>
        <option value="founded-asc">成立最早</option>
      </select>
      <button class="toolbar__toggle" type="button" data-filter-toggle aria-expanded="false" aria-controls="filter-panel">
        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 5h14M5.5 10h9M8.5 15h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        筛选
        <span class="toolbar__badge" data-filter-badge hidden>0</span>
      </button>
      <span class="toolbar__count" data-filter-count><?= count($providers) ?> / <?= count($providers) ?> 家</span>
    </div>
    <div class="toolbar__row toolbar__row--filters" id="filter-panel" data-filter-panel hidden>
      <select data-filter-line aria-label="线路类型">
        <option value="">全部线路</option>
        <?php foreach ($lines as $line => $n): ?>
          <option value="<?= e($line) ?>"><?= e(line_label($line)) ?>（<?= (int) $n ?>）</option>
        <?php endforeach; ?>
      </select>
      <select data-filter-protocol aria-label="协议">
        <option value="">全部协议</option>
        <?php foreach ($protocols as $protocol => $n): ?>
          <option value="<?= e($protocol) ?>"><?= e($protocol) ?>（<?= (int) $n ?>）</option>
        <?php endforeach; ?>
      </select>
      <select data-filter-region aria-label="地区">
        <option value="">全部地区</option>
        <?php foreach ($regions as $region => $n): ?>
          <option value="<?= e($region) ?>"><?= e($region) ?>（<?= (int) $n ?>）</option>
        <?php endforeach; ?>
      </select>
      <select data-filter-price aria-label="月均预算">
        <option value="">不限预算</option>
        <option value="20">月均 ≤ ￥20</option>
        <option value="30">月均 ≤ ￥30</option>
        <option value="50">月均 ≤ ￥50</option>
        <option value="100">月均 ≤ ￥100</option>
        <option value="200">月均 ≤ ￥200</option>
      </select>
      <div class="filter-group">
        <span class="filter-label">特性</span>
        <?php foreach ($featureTags as $tag): ?>
          <button class="tag" type="button" data-filter-feature="<?= e($tag) ?>"><?= e($tag) ?></button>
        <?php endforeach; ?>
      </div>
      <button class="filter-reset" type="button" data-filter-reset>重置</button>
    </div>
  </div>

  <p class="table-hint">左右滑动查看完整对比 → <span class="table-hint__sep">·</span> 不懂线路？<a href="/guide/">看选择指南</a></p>
  <div class="table-wrap">
    <table class="data-table" data-provider-table>
      <colgroup>
        <col style="width:2.2rem">
        <col style="width:21%">
        <col style="width:4.2rem">
        <col style="width:5.6rem">
        <col style="width:8.2rem">
        <col style="width:4.2rem">
        <col style="width:4.4rem">
        <col style="width:5.6rem">
        <col style="width:4.8rem">
        <col style="width:4.6rem">
        <col style="width:5.8rem">
        <col style="width:6rem">
        <col style="width:4rem">
      </colgroup>
      <thead>
        <tr>
          <th class="col-check"><span class="visually-hidden">对比</span></th>
          <th class="col-name">机场</th>
          <th>试用</th>
          <th>线路</th>
          <th>协议</th>
          <th class="num">地区</th>
          <th class="num" title="不同 IP 入口数量的下限；来源明确披露的按披露值">节点</th>
          <th class="num">入门价</th>
          <th class="num">月均</th>
          <th class="num">流量/月</th>
          <th class="num">单价</th>
          <th>解锁</th>
          <th>优惠</th>
        </tr>
      </thead>
      <tbody>
        <?php $order = 0; foreach ($providers as $p):
            $order++;
            $monthly = monthly_equiv_cny($p);
            $unitCny = $p['unit_price_from'] !== null
                ? (float) $p['unit_price_from'] * (($p['price_currency'] ?? 'CNY') === 'USD' ? 7.2 : 1)
                : null;
            $search = implode(' ', array_merge(
                [$p['name'], $p['line_type'], $p['summary']],
                $p['protocols'],
                $p['regions'],
                $p['features'],
                $p['tags']
            ));
        ?>
        <tr
          data-slug="<?= e($p['slug']) ?>"
          data-weight="<?= (int) ($p['weight'] ?? 0) ?>"
          data-order="<?= (int) $order ?>"
          data-search="<?= e($search) ?>"
          data-line="<?= e($p['line_type']) ?>"
          data-protocols="<?= e(implode(' ', $p['protocols'])) ?>"
          data-regions="<?= e(implode(' ', $p['regions'])) ?>"
          data-regions-count="<?= (int) $p['region_count'] ?>"
          data-nodes="<?= (int) $p['node_count'] ?>"
          data-features="<?= e(implode('|', $p['features'])) ?>"
          data-monthly="<?= $monthly !== null ? e((string) round($monthly, 2)) : '999999' ?>"
          data-traffic="<?= e((string) ($p['traffic_from'] ?? 0)) ?>"
          data-unit="<?= $unitCny !== null ? e((string) round($unitCny, 4)) : '999999' ?>"
          data-founded="<?= e((string) ($p['founded'] ?? 9999)) ?>"
        >
          <td class="col-check">
            <input class="js-compare" type="checkbox" value="<?= e($p['slug']) ?>" aria-label="对比 <?= e($p['name']) ?>">
          </td>
          <td>
            <div class="t-name">
              <span class="t-name__avatar" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
              <span class="t-name__text">
                <a href="/providers/<?= e($p['slug']) ?>/"><?= e($p['name']) ?></a>
                <span class="t-name__sub" title="<?= e($p['summary']) ?>"><?= e($p['summary']) ?></span>
              </span>
            </div>
          </td>
          <td class="t-trial">
            <?php if (in_array('免费试用', $p['features'], true)): ?>
              <span class="badge badge--green" title="来源提及支持免费试用；具体规则以官网为准">可试用</span>
            <?php else: ?>
              <span class="t-muted" title="来源未提及免费试用">未提及</span>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= e(line_badge_class($p['line_type'])) ?>"><?= e(line_label($p['line_type'])) ?></span></td>
          <td class="t-protocols" title="<?= e(implode(' · ', $p['protocols'])) ?>">
            <?= e($p['protocols'][0] ?? '—') ?>
            <?php if (count($p['protocols']) > 1): ?><span class="t-more">+<?= count($p['protocols']) - 1 ?></span><?php endif; ?>
          </td>
          <td class="num">
            <?= (int) $p['region_count'] ?>
            <span class="sub"><?= e(implode('、', array_slice($p['regions_common'] ?: $p['regions'], 0, 2))) ?></span>
          </td>
          <td class="num t-nodes" title="<?= e(node_count_title($p)) ?>">
            <b class="<?= empty($p['node_count_disclosed']) ? 't-node-lower' : '' ?>"><?= e(node_count_label($p)) ?></b>
          </td>
          <td class="num">
            <b><?= e(provider_price_label($p)) ?></b>
            <?php if (!empty($p['monthly_offline'])): ?><span class="sub">月付已下线</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($monthly !== null): ?>
              <b>￥<?= format_number($monthly, 2) ?></b>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="num"><?= e(format_traffic($p['traffic_from'])) ?></td>
          <td class="num"><?= e(format_unit_price($p['unit_price_from'], $p['price_currency'])) ?></td>
          <td>
            <?php if ($p['media_unlock']): ?>
              <span class="badge badge--green" title="<?= e(implode('、', $p['media_unlock'])) ?>"><?= e($p['media_unlock'][0]) ?></span>
              <?php if (count($p['media_unlock']) > 1): ?><span class="t-more">+<?= count($p['media_unlock']) - 1 ?></span><?php endif; ?>
            <?php else: ?>
              <span style="color:var(--muted-2)">未提及</span>
            <?php endif; ?>
          </td>
          <td class="t-discount">
            <?php
              $discountShort = '';
              if ($p['discount_label'] !== '') {
                  $discountShort = preg_match('/(\d+(?:\.\d+)?)\s*折/u', $p['discount_label'], $m)
                      ? $m[1] . ' 折'
                      : trim(explode(' · ', $p['discount_label'])[0]);
              } elseif ($p['discount_code'] !== '') {
                  $discountShort = $p['discount_code'];
              }
              $discountFull = $p['discount_label'] !== ''
                  ? $p['discount_label']
                  : ($p['discount_code'] !== '' ? '优惠码 ' . $p['discount_code'] : '');
            ?>
            <?php if ($discountShort !== ''): ?>
              <span class="badge badge--blue" title="<?= e($discountFull) ?>"><?= e($discountShort) ?></span>
            <?php else: ?>
              <span style="color:var(--muted-2)">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state" data-filter-empty hidden>
    <b>没有符合条件的机场</b>
    <p>试试减少一个筛选条件，或<a href="/providers/">查看全部 <?= count($providers) ?> 家</a>。</p>
  </div>

  <p style="margin:1rem 0 2rem;font-size:.78rem;color:var(--muted-2);line-height:1.6">
    价格口径：入门价按最低可用套餐原价展示；月均为按月折算的人民币成本（美元按 1:7.2 估算）。流量为入门套餐的月均额度。解锁信息为评测提及，不构成持续可用承诺。
    节点数为不同 IP 入口的数量下限：来源明确披露的按披露值（如 100+）；未披露的按来源列出的服务器地区数统计（每地区至少 1 个独立 IP），实际可能更多。
  </p>
</div>
