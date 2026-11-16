<?php
/** @var array $tool */
/** @var array $providers */
/** @var array $news */
/** @var array $relatedTools */
$sourceCount = 0;
$inferredCount = 0;
$verifiedCount = 0;
foreach ($providers as $p) {
    $level = (string) ($p['evidence_level'] ?? 'unknown');
    $sourceCount += $level === 'source' ? 1 : 0;
    $inferredCount += $level === 'inferred' ? 1 : 0;
    $verifiedCount += $level === 'verified' ? 1 : 0;
}
$isCoding = ($tool['category'] ?? '') === 'coding';
?>
<div class="page-bar">
  <div class="shell shell--wide">
    <a class="page-back" href="/ai/">← 全部 AI 工具</a>
  </div>
</div>
<section class="hero hero--ai hero--compact">
  <div class="hero__inner">
    <span class="hero__eyebrow">
      <span class="badge badge--blue"><?= e(ai_tool_category_label($tool['category'])) ?></span>
      <?= e($tool['vendor']) ?> · 最后核验 <?= e($tool['last_verified'] ?: '—') ?>
    </span>
    <h1 class="hero__title"><?= e($tool['name']) ?><em>用什么网络？</em></h1>
    <p class="hero__lead"><?= e($tool['tagline']) ?>。先看网络要求，再看候选机场与证据等级。</p>
    <div class="hero__cta">
      <a class="btn btn--primary" href="#airports">查看候选机场</a>
      <a class="btn btn--ghost" href="<?= e($tool['official_url']) ?>" rel="nofollow noopener" target="_blank">官方入口 ↗</a>
    </div>
    <p class="hero__note">独立整理，非官方说明。可用地区、账号政策与价格以官方页面为准。</p>
    <div class="hero__stats">
      <div class="hero__stat"><b><?= count($providers) ?></b><span>候选机场</span></div>
      <div class="hero__stat"><b><?= $sourceCount ?></b><span>来源提及</span></div>
      <div class="hero__stat"><b><?= $inferredCount ?></b><span>条件推断</span></div>
      <div class="hero__stat"><b><?= $verifiedCount ?></b><span>已实测</span></div>
    </div>
  </div>
</section>

<section class="section section--tight">
  <div class="shell">
    <div class="callout">
      <strong>先读这段：</strong><?= e($tool['summary']) ?>
    </div>
  </div>
</section>

<section class="section section--tight" id="airports">
  <div class="shell shell--wide">
    <div class="detail-grid">
      <div>
        <div class="detail-section">
          <div class="detail-section__title">
            <h2>网络要求</h2>
            <span><?= count($tool['requirements']) ?> 条</span>
          </div>
          <div class="panel">
            <ul class="ai-requirement-list">
              <?php foreach ($tool['requirements'] as $requirement): ?>
                <li><?= e($requirement) ?></li>
              <?php endforeach; ?>
            </ul>
            <div class="ai-traits">
              <div>
                <p class="ai-label">常用出口地区</p>
                <div class="chips">
                  <?php foreach ($tool['regions'] as $region): ?>
                    <span class="chip"><?= e($region) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
              <div>
                <p class="ai-label">网络特征</p>
                <div class="chips">
                  <?php foreach ($tool['network_traits'] as $trait): ?>
                    <span class="chip"><?= e($trait) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="detail-section">
          <div class="detail-section__title">
            <h2>候选机场</h2>
            <span><?= count($providers) ?> 家</span>
          </div>
          <?php if (!$providers): ?>
            <div class="panel">
              <p style="margin:0;color:var(--muted);font-size:.88rem">暂未整理到符合条件的机场。可以先去<a href="/providers/">机场对比</a>按线路和地区筛选。</p>
            </div>
          <?php else: ?>
            <p class="table-hint">左右滑动查看完整表格</p>
            <div class="table-wrap">
              <table class="data-table data-table--ai">
                <colgroup>
                  <col style="width:30%">
                  <col style="width:5rem">
                  <col style="width:5rem">
                  <col style="width:5.2rem">
                  <col style="width:5.6rem">
                  <col style="width:6rem">
                </colgroup>
                <thead>
                  <tr>
                    <th>机场</th>
                    <th class="num">地区</th>
                    <th class="num">节点</th>
                    <th>试用</th>
                    <th class="num">入门价</th>
                    <th>证据</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($providers as $p): ?>
                    <?php $evidence = ai_evidence_meta((string) ($p['evidence_level'] ?? 'unknown')); ?>
                    <tr>
                      <td class="col-name">
                        <a class="t-name" href="/providers/<?= e($p['slug']) ?>/">
                          <span class="t-name__avatar" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
                          <span class="t-name__text">
                            <?= e($p['name']) ?>
                            <span class="sub"><?= e($p['line_type']) ?></span>
                          </span>
                        </a>
                      </td>
                      <td class="num"><?= (int) $p['region_count'] ?></td>
                      <td class="num"><b><?= e(node_count_label($p)) ?></b></td>
                      <td>
                        <?php if (in_array('免费试用', $p['features'], true)): ?>
                          <span class="badge badge--green">可试用</span>
                        <?php else: ?>
                          <span class="t-muted">未提及</span>
                        <?php endif; ?>
                      </td>
                      <td class="num"><?= e(provider_price_label($p)) ?></td>
                      <td>
                        <span class="badge <?= e($evidence['class']) ?>" title="<?= e((string) ($p['evidence_note'] ?? '')) ?>"><?= e($evidence['label']) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="ai-footnote">“来源提及”只表示公开页面写过；“条件推断”只基于线路、地区、节点与现有 AI 提及，未做独立实测。下单前请先用短周期套餐验证。</p>
          <?php endif; ?>
        </div>

        <div class="detail-section">
          <div class="detail-section__title">
            <h2>常见问题</h2>
          </div>
          <div class="panel">
            <div class="ai-faq">
              <details open>
                <summary>这些机场一定能用 <?= e($tool['name']) ?> 吗？</summary>
                <p>不一定。来源提及只表示公开页面写过，条件推断只表示网络特征匹配。工具的地区策略、账号状态和节点表现都会影响结果。</p>
              </details>
              <details>
                <summary>更看重延迟还是稳定性？</summary>
                <p><?= $isCoding ? '编程类工具通常有长任务和连续请求，稳定性、丢包率和节点一致性比最低延迟更重要。' : '对话和搜索类工具对流式输出更敏感，首屏延迟、丢包和峰值时段稳定性都值得关注。' ?></p>
              </details>
              <details>
                <summary>价格和可用性以哪里为准？</summary>
                <p>以 <?= e($tool['name']) ?> 官方页面和机场官网结算页为准。本站价格是历史整理，可能滞后。</p>
              </details>
            </div>
          </div>
        </div>

        <?php if ($news): ?>
          <div class="detail-section">
            <div class="detail-section__title">
              <h2>相关 AI 事件</h2>
              <a class="text-link" href="/news/">全部新闻</a>
            </div>
            <?= partial('partials/news-list', ['news' => $news, 'compact' => true]) ?>
          </div>
        <?php endif; ?>
      </div>

      <aside>
        <div class="side-card">
          <h3>账号与 IP 敏感度</h3>
          <p><?= e($tool['ip_sensitivity']) ?></p>
          <p><?= e($tool['account_risk']) ?></p>
        </div>

        <div class="side-card">
          <h3>官方入口</h3>
          <p><a href="<?= e($tool['official_url']) ?>" rel="nofollow noopener" target="_blank"><?= e($tool['official_url']) ?> ↗</a></p>
          <p><?= e($tool['official_note']) ?></p>
        </div>

        <div class="side-card">
          <h3>相关工具</h3>
          <ul class="side-list">
            <?php foreach ($relatedTools as $related): ?>
              <li>
                <span><?= e(ai_tool_category_label($related['category'])) ?></span>
                <a href="/ai/<?= e($related['slug']) ?>/"><?= e($related['name']) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="side-card">
          <h3>继续选型</h3>
          <p>不确定选哪家？按预算、流量和线路偏好做一次匹配，再用短周期套餐验证。</p>
          <div class="hero__cta" style="justify-content:flex-start;margin-top:.6rem">
            <a class="btn btn--primary btn--small" href="/match/">智能匹配</a>
            <a class="btn btn--ghost btn--small" href="/providers/">全部机场</a>
          </div>
        </div>
      </aside>
    </div>
  </div>
</section>
