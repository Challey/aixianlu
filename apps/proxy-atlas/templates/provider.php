<?php
/** @var array $p */
/** @var array $related */
$plans = $p['plans'] ?? [];
$groups = grouped_plans($plans);
$hasOfficial = !empty($p['official_url']) || !empty($p['affiliate_url']);
$affiliateCode = trim((string) ($p['affiliate_code'] ?? ''));
$affiliateActive = ($p['affiliate_status'] ?? '') === 'active';
$affiliateNote = trim((string) ($p['affiliate_note'] ?? ''));
$regionsCommon = $p['regions_common'] ?: array_slice($p['regions'], 0, 6);
$regionsOther = $p['regions_other'];
$mediaText = implode('、', $p['media_unlock']);
if ($mediaText === '' && in_array('流媒体', $p['features'], true)) {
    $mediaText = '来源提及，未列明平台';
}
$aiText = implode('、', $p['ai_unlock']);
if ($aiText === '' && in_array('AI 工具', $p['features'], true)) {
    $aiText = '来源提及，未列明工具';
}
$sourceUrl = $p['source_review_url'] ?: $p['source_home_url'];
$hasCheckout = provider_has_checkout($p);
$buyHref = '/go/' . $p['slug'] . ($hasCheckout ? '?src=buy' : '');
$buyLabel = $hasCheckout ? '一键购买 · 预填邀请码' : ($affiliateActive ? '通过邀请链接注册' : '访问官网');
?>

<div class="shell">
  <nav class="crumbs" aria-label="面包屑">
    <a href="/">首页</a>
    <span aria-hidden="true">›</span>
    <a href="/providers/">机场对比</a>
    <span aria-hidden="true">›</span>
    <span><?= e($p['name']) ?></span>
  </nav>

  <section class="detail-hero">
    <div>
      <div class="detail-hero__top">
        <span class="detail-hero__avatar" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
        <div>
          <h1><?= e($p['name']) ?></h1>
          <p class="detail-hero__sub">
            <?= e(line_label($p['line_type'])) ?>
            <?php if (!empty($p['founded'])): ?> · <?= (int) $p['founded'] ?> 年成立<?php endif; ?>
            <?php if (!empty($p['region_count'])): ?> · <?= (int) $p['region_count'] ?> 个地区<?php endif; ?>
            · <span title="<?= e(node_count_title($p)) ?>">节点 <?= e(node_count_label($p)) ?></span>
            · <?= (int) $p['plan_count'] ?> 个套餐
          </p>
        </div>
      </div>

      <p class="detail-hero__summary"><?= e($p['summary']) ?></p>

      <div class="badge-row" style="margin-bottom:1rem">
        <?php foreach ($p['protocols'] as $protocol): ?>
          <span class="badge"><?= e($protocol) ?></span>
        <?php endforeach; ?>
        <?php foreach (array_slice($p['features'], 0, 3) as $feature): ?>
          <span class="badge badge--line"><?= e($feature) ?></span>
        <?php endforeach; ?>
        <?php if (!empty($p['monthly_offline'])): ?>
          <span class="badge badge--amber">月付已下线</span>
        <?php endif; ?>
      </div>

      <div class="detail-hero__actions">
        <button class="btn btn--ghost" type="button" data-compare-toggle="<?= e($p['slug']) ?>" aria-pressed="false">加入对比</button>
        <?php if ($hasOfficial): ?>
          <a class="btn btn--primary" href="<?= e($buyHref) ?>" rel="nofollow sponsored noopener" target="_blank" data-fp-slug="<?= e($p['slug']) ?>" data-fp-name="<?= e($p['name']) ?>"><?= e($buyLabel) ?></a>
        <?php endif; ?>
        <button class="btn btn--ghost" type="button" data-fp-mark="<?= e($p['slug']) ?>" data-fp-name="<?= e($p['name']) ?>">标记已购 / 记到期</button>
      </div>
      <p class="detail-hero__fp"><a href="/my/">我的足迹</a> 记录只保存在本机浏览器，用于续费提醒，不需要注册账号。</p>
    </div>

    <aside class="detail-hero__aside">
      <div class="detail-hero__price"><?= e(provider_price_label($p)) ?></div>
      <div class="detail-hero__price-note"><?= e(provider_price_note($p) ?: '价格以官网为准') ?></div>
      <?php if (!$hasOfficial): ?>
        <div style="margin-top:.75rem"><span class="badge badge--amber">官网地址待补录</span></div>
      <?php endif; ?>
    </aside>
  </section>

  <div class="facts">
    <div class="fact">
      <span class="fact__label">线路类型</span>
      <span class="fact__value"><?= e(line_label($p['line_type'])) ?></span>
    </div>
    <div class="fact">
      <span class="fact__label">协议</span>
      <span class="fact__value"><?= e(implode('、', $p['protocols']) ?: '未标注') ?></span>
    </div>
    <div class="fact">
      <span class="fact__label">覆盖地区</span>
      <span class="fact__value">
        <?php if ((int) $p['region_count'] > 0): ?>
          <?= (int) $p['region_count'] ?> 个 <small><?= e(implode('、', array_slice($regionsCommon, 0, 3))) ?></small>
        <?php else: ?>
          待核实 <small>来源未列明</small>
        <?php endif; ?>
      </span>
    </div>
    <div class="fact">
      <span class="fact__label">成立年份</span>
      <span class="fact__value"><?= !empty($p['founded']) ? (int) $p['founded'] . ' 年' : '未标注' ?></span>
    </div>
  </div>

  <div class="detail-grid detail-section">
    <div>
      <div class="detail-section__title">
        <h2>套餐与价格</h2>
        <span>共 <?= (int) $p['plan_count'] ?> 个选项 · 数据更新 <?= e($p['source_updated_at'] ?: setting('data_updated_at', '')) ?></span>
      </div>

      <?php if (!empty($p['plan_note'])): ?>
        <div class="plan-note">
          <span aria-hidden="true">!</span>
          <span><?= e($p['plan_note']) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!$groups): ?>
        <div class="empty-state">该机场暂未整理到套餐数据。</div>
      <?php else: ?>
        <div class="plan-groups">
          <?php foreach ($groups as $group): ?>
            <div class="plan-group">
              <div class="plan-group__head">
                <h3><?= e($group['name']) ?></h3>
                <span class="badge"><?= count($group['options']) ?> 个选项</span>
              </div>
              <table class="plan-options">
                <thead>
                  <tr>
                    <th>选项</th>
                    <th class="num">实付</th>
                    <th class="num">月均</th>
                    <th class="num">流量/月</th>
                    <th class="num">单价</th>
                    <th>备注</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($group['options'] as $option):
                      $isEntry = !empty($option['is_entry']);
                      $monthlyTraffic = $option['monthly_traffic_gb'] ?? $option['traffic_gb'];
                      $note = trim((string) ($option['notes'] ?? ''));
                      if ($option['speed_limit']) {
                          $note = trim($note . ' 限速 ' . $option['speed_limit']);
                      }
                      if ($option['device_limit']) {
                          $note = trim($note . ' ' . $option['device_limit'] . ' 台设备');
                      }
                  ?>
                  <tr class="<?= $isEntry ? 'is-entry' : '' ?>">
                    <td>
                      <?= e($option['option_label'] ?: $option['period']) ?>
                      <?php if ($isEntry): ?><span class="badge badge--blue plan-badge">入门</span><?php endif; ?>
                    </td>
                    <td class="num"><b><?= e(format_price($option['price'], $option['currency'])) ?></b></td>
                    <td class="num"><?= e(format_price($option['monthly_price'], $option['currency'])) ?></td>
                    <td class="num"><?= e(format_traffic($monthlyTraffic !== null ? (float) $monthlyTraffic : null)) ?></td>
                    <td class="num"><?= e(format_unit_price($option['unit_price'], $option['currency'])) ?></td>
                    <td class="note"><?= e($note ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="detail-section">
        <div class="detail-section__title">
          <h2>覆盖地区</h2>
          <span><?= (int) $p['region_count'] > 0 ? (int) $p['region_count'] . ' 个' : '待核实' ?></span>
        </div>
        <div class="panel">
          <?php if ($regionsCommon): ?>
            <p style="font-size:.76rem;color:var(--muted);margin-bottom:.45rem">常用地区</p>
            <div class="chips" style="margin-bottom:.9rem">
              <?php foreach ($regionsCommon as $region): ?>
                <span class="chip"><?= e($region) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($regionsOther): ?>
            <p style="font-size:.76rem;color:var(--muted);margin-bottom:.45rem">其他地区</p>
            <div class="chips">
              <?php foreach ($regionsOther as $region): ?>
                <span class="chip"><?= e($region) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!$regionsCommon && !$regionsOther): ?>
            <p style="color:var(--muted);font-size:.85rem;margin:0">暂未整理到地区信息。</p>
          <?php endif; ?>
        </div>
      </div>

      <?php $aiFits = $p['ai_fits'] ?? []; ?>
      <?php if ($aiFits): ?>
        <div class="detail-section">
          <div class="detail-section__title">
            <h2>AI 工具适配</h2>
            <a class="text-link" href="/ai/">查看全部工具</a>
          </div>
          <div class="panel">
            <div class="ai-fit-grid">
              <?php foreach ($aiFits as $fit): ?>
                <?php $evidence = ai_evidence_meta((string) ($fit['evidence_level'] ?? 'unknown')); ?>
                <a class="ai-fit" href="/ai/<?= e($fit['tool_slug']) ?>/" title="<?= e((string) ($fit['evidence_note'] ?? '')) ?>">
                  <span>
                    <b><?= e($fit['tool_name']) ?></b>
                    <small><?= e(ai_tool_category_label((string) $fit['tool_category'])) ?></small>
                  </span>
                  <span class="badge <?= e($evidence['class']) ?>"><?= e($evidence['label']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
            <p class="ai-footnote">“来源提及”只表示公开页面写过；“条件推断”只基于线路、地区和现有 AI 提及，均不构成持续可用承诺。</p>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <aside>
      <div class="side-card">
        <h3>解锁与特性</h3>
        <ul class="side-list">
          <li><span>流媒体</span><span><?= e($mediaText ?: '未提及') ?></span></li>
          <li><span>AI 工具</span><span><?= e($aiText ?: '未提及') ?></span></li>
          <li><span>特性</span><span><?= e(implode('、', $p['features']) ?: '未标注') ?></span></li>
          <li><span>支付方式</span><span><?= e(implode('、', $p['payments']) ?: '未标注') ?></span></li>
        </ul>
        <p style="margin-top:.7rem">“解锁”为评测提及，受节点、地区与平台策略影响，不构成持续可用承诺。</p>
      </div>

      <?php if ($p['discount_code'] || $p['discount_label']): ?>
        <div class="side-card">
          <h3>优惠信息</h3>
          <?php if ($p['discount_label']): ?><p><?= e($p['discount_label']) ?></p><?php endif; ?>
          <?php if ($p['discount_code']): ?>
            <button class="discount-code" type="button" data-copy="<?= e($p['discount_code']) ?>">
              <code><?= e($p['discount_code']) ?></code>
              <span>点击复制</span>
            </button>
          <?php endif; ?>
          <p style="margin-top:.6rem;font-size:.72rem">优惠码可能过期或限量，以官网结算页为准。</p>
        </div>
      <?php endif; ?>

      <?php if ($hasOfficial && ($affiliateCode !== '' || $affiliateActive)): ?>
        <div class="side-card side-card--invite">
          <h3>注册与邀请</h3>
          <p>通过本站链接进入注册页，价格不变；我们可能获得少量佣金，用于维持站点与更新。</p>
          <?php if ($affiliateCode !== ''): ?>
            <button class="discount-code" type="button" data-copy="<?= e($affiliateCode) ?>">
              <code><?= e($affiliateCode) ?></code>
              <span>点击复制邀请码</span>
            </button>
            <p style="margin-top:.5rem;font-size:.72rem">如果注册页需要邀请码，请填写上面的代码；不需要时直接点下方按钮即可。</p>
          <?php endif; ?>
          <?php if ($affiliateNote !== ''): ?>
            <p style="margin-top:.4rem;font-size:.72rem"><?= e($affiliateNote) ?></p>
          <?php endif; ?>
          <a class="btn btn--primary btn--small" style="margin-top:.6rem" href="<?= e($buyHref) ?>" rel="nofollow sponsored noopener" target="_blank" data-fp-slug="<?= e($p['slug']) ?>" data-fp-name="<?= e($p['name']) ?>"><?= $hasCheckout ? '一键注册 · 预填邀请码' : '前往注册' ?></a>
        </div>
      <?php endif; ?>

      <div class="side-card">
        <h3>数据说明</h3>
        <ul class="side-list">
          <li><span>收录序号</span><span>#<?= (int) $p['source_order'] ?></span></li>
          <li><span>更新时间</span><span><?= e($p['source_updated_at'] ?: '—') ?></span></li>
          <li><span>官网</span><span><?= $hasOfficial ? '已收录' : '待补录' ?></span></li>
        </ul>
        <p style="margin-top:.7rem">
          本站只整理公开事实字段，不转载评测原文与图片。
          <?php if ($sourceUrl): ?>
            事实核对可参考<a href="<?= e($sourceUrl) ?>" rel="nofollow noopener" target="_blank">来源页面</a>。
          <?php endif; ?>
        </p>
      </div>
    </aside>
  </div>

  <?php if ($related): ?>
    <section class="section section--tight" style="padding-inline:0">
      <div class="section__head">
        <div>
          <p class="section__eyebrow">同类参考</p>
          <h2 class="section__title">线路相同，可以一起看</h2>
        </div>
        <a class="text-link" href="/providers/?line=<?= e(urlencode($p['line_type'])) ?>">更多<?= e(line_label($p['line_type'])) ?>机场</a>
      </div>
      <div class="cards cards--4">
        <?php foreach ($related as $r): ?>
          <?= partial('partials/provider-card', ['p' => $r, 'compare' => false]) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php if ($hasOfficial): ?>
  <div class="buy-bar" role="region" aria-label="快速注册">
    <div class="buy-bar__meta">
      <b><?= e(provider_price_label($p)) ?></b>
      <span><?= e($p['name']) ?><?= $affiliateCode !== '' ? ' · 邀请码 ' . e($affiliateCode) : '' ?></span>
    </div>
    <a class="btn btn--primary" href="<?= e($buyHref) ?>" rel="nofollow sponsored noopener" target="_blank" data-fp-slug="<?= e($p['slug']) ?>" data-fp-name="<?= e($p['name']) ?>"><?= $hasCheckout ? '一键购买' : '访问官网' ?></a>
  </div>
<?php endif; ?>
