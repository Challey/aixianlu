<div class="shell shell--wide">
  <header class="page-head">
    <h1>并排对比</h1>
    <p>最多同时选择 4 家机场，直接比较入门价、月均成本、流量、单位流量价、线路、协议、节点规模与解锁能力。</p>
  </header>

  <div data-compare-page>
    <div class="compare-head">
      <div class="compare-picker">
        <select data-compare-add aria-label="添加机场">
          <option value="">＋ 添加一家机场</option>
        </select>
        <span style="font-size:.78rem;color:var(--muted)">已选机场会保存在本机浏览器，最多 4 家。</span>
      </div>
    </div>

    <div class="compare-empty" data-compare-empty>
      <h2>还没有选择机场</h2>
      <p>从机场列表勾选“对比”，或在上面直接添加。也可以先去<a href="/providers/">浏览全部机场</a>。</p>
      <div class="compare-actions">
        <a class="btn btn--primary" href="/providers/">去挑选机场</a>
        <a class="btn btn--ghost" href="/match/">让系统帮我选</a>
      </div>
    </div>

    <div class="compare-wrap" data-compare-table-wrap hidden></div>

    <p style="margin:1rem 0 2rem;font-size:.78rem;color:var(--muted-2);line-height:1.6">
      对比中的“月均”为人民币折算值，美元按 1:7.2 估算；单位流量价按入门套餐的月均价格 ÷ 月流量计算。
      节点数为不同 IP 入口的数量下限：来源明确披露的按披露值，未披露的按服务器地区数统计（每地区至少 1 个独立 IP）。
      价格与套餐可用性以服务商官网为准。
    </p>
  </div>
</div>
