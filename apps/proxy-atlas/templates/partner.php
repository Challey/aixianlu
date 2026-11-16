<?php
$contact = setting('partner_contact', 'partner@example.com');
?>
<div class="shell">
  <header class="page-head">
    <h1>代理合作</h1>
    <p>把机场对比页变成你的选品与转化入口。统一展示、独立子 ID、点击留痕；适合社群主、内容站、企业 IT 与跨境团队。</p>
  </header>

  <div class="grid grid--4" style="margin-bottom:2.5rem">
    <div class="feature">
      <span class="feature__num">01</span>
      <h3>数据化选品</h3>
      <p>价格、线路、流量与解锁能力放在同一张表，先比较再推荐，减少售后扯皮。</p>
    </div>
    <div class="feature">
      <span class="feature__num">02</span>
      <h3>独立子 ID</h3>
      <p>每个渠道独立标识，点击、转化分别统计，不和其他渠道混在一起。</p>
    </div>
    <div class="feature">
      <span class="feature__num">03</span>
      <h3>结算透明</h3>
      <p>按实际成交与约定比例结算，提供明细；具体条款以签约协议为准。</p>
    </div>
    <div class="feature">
      <span class="feature__num">04</span>
      <h3>素材支持</h3>
      <p>提供对比表、套餐说明与合规话术，避免夸大解锁与稳定性承诺。</p>
    </div>
  </div>

  <section class="section section--tight" style="padding-top:0">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">合作流程</p>
        <h2 class="section__title">四步开始。</h2>
      </div>
    </div>
    <div class="flow">
      <div class="flow__item">
        <h4>提交申请</h4>
        <p>填写渠道类型、受众规模与主要场景。</p>
      </div>
      <div class="flow__item">
        <h4>人工核对</h4>
        <p>确认渠道真实性与合规风险，必要时补充材料。</p>
      </div>
      <div class="flow__item">
        <h4>开通子 ID</h4>
        <p>生成独立推广标识与专属入口。</p>
      </div>
      <div class="flow__item">
        <h4>素材与结算</h4>
        <p>按约定周期对账，提供可核验的转化明细。</p>
      </div>
    </div>
  </section>

  <section class="section section--tight">
    <div class="split">
      <div class="split__copy">
        <p class="section__eyebrow">合作层级</p>
        <h2>按规模与质量分层。</h2>
        <p>以下为展示用示例政策，不构成报价承诺。实际比例、结算周期与考核门槛以双方签署的协议为准。</p>
        <div class="callout callout--amber" style="margin-top:1rem">
          当前为第一阶段：申请信息进入候选名单，代理后台、子 ID 与结算系统将在第二阶段开放。
        </div>
      </div>
      <div class="split__panel">
        <ul class="side-list">
          <li><span>标准代理</span><span>示例 10%</span></li>
          <li><span>认证代理</span><span>示例 12%</span></li>
          <li><span>金牌代理</span><span>示例 15%</span></li>
          <li><span>企业采购</span><span>单独报价</span></li>
        </ul>
        <p style="margin:1rem 0 0;font-size:.75rem;color:var(--muted);line-height:1.6">
          结算基数、退款扣减、税务与发票处理方式，均在协议中约定。禁止以虚假折扣、冒充官方或夸大解锁作为推广手段。
        </p>
      </div>
    </div>
  </section>

  <section class="section section--tight" id="apply">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">申请</p>
        <h2 class="section__title">留下你的渠道信息。</h2>
        <p class="section__lead">我们会在人工核对后联系你。当前不收取任何申请费用。</p>
      </div>
    </div>

    <form class="panel" action="/api/agent-apply.php" method="post" data-agent-form novalidate>
      <div class="form-grid">
        <div class="field">
          <label for="agent-name">称呼 / 渠道名</label>
          <input id="agent-name" name="name" type="text" maxlength="80" required placeholder="例如：某某测评 / 企业 IT">
        </div>
        <div class="field">
          <label for="agent-email">联系邮箱</label>
          <input id="agent-email" name="email" type="email" maxlength="160" required placeholder="name@example.com">
        </div>
        <div class="field">
          <label for="agent-channel">渠道类型</label>
          <select id="agent-channel" name="channel" required>
            <option value="">请选择</option>
            <option value="社群">社群 / Telegram / Discord</option>
            <option value="内容站">内容站 / 博客 / 测评</option>
            <option value="视频">视频 / YouTube / Bilibili</option>
            <option value="企业客户">企业客户 / 跨境团队</option>
            <option value="资源供给">线路或机场资源供给</option>
            <option value="其他">其他</option>
          </select>
        </div>
        <div class="field">
          <label for="agent-scale">受众或月流量规模</label>
          <select id="agent-scale" name="scale">
            <option value="">不确定 / 暂不填写</option>
            <option value="1k">1,000 人以内</option>
            <option value="10k">1,000–10,000 人</option>
            <option value="50k">10,000–50,000 人</option>
            <option value="50k+">50,000 人以上</option>
            <option value="enterprise">企业客户为主</option>
          </select>
        </div>
        <div class="field field--full">
          <label for="agent-note">补充说明</label>
          <textarea id="agent-note" name="note" maxlength="800" placeholder="主要受众、推广方式、希望合作的机场类型，或你能提供的线路资源。"></textarea>
        </div>
        <input type="hidden" name="intent" value="agent">
        <div class="hp-field" aria-hidden="true">
          <label>请勿填写<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label>
        </div>
        <p class="form-note">提交即表示同意我们通过邮箱与你联系。我们不会公开你的联系方式；点击统计仅保存 IP 哈希，不保存原始 IP。</p>
        <div class="form-status" data-form-status hidden role="status"></div>
        <div class="field--full">
          <button class="btn btn--primary" type="submit">提交申请</button>
          <span style="margin-left:.75rem;font-size:.76rem;color:var(--muted-2)">或邮件联系 <?= e($contact) ?></span>
        </div>
      </div>
    </form>
  </section>
</div>
