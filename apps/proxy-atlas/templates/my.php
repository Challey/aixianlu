<?php
/** Local-only footprint page; all records live in the visitor's browser. */
?>
<div class="page-bar">
  <div class="shell shell--wide">
    <a class="page-back" href="/">← 返回首页</a>
  </div>
</div>

<section class="section section--tight">
  <div class="shell shell--wide">
    <div class="section__head">
      <div>
        <p class="section__eyebrow">本机数据 · 无需账号</p>
        <h1 class="section__title">我的足迹</h1>
        <p class="section__lead">记录你点过、买过的机场，并在到期前提醒续费。所有数据只保存在当前浏览器，不上传、不需要注册。</p>
      </div>
      <div class="fp-head-actions">
        <button class="btn btn--ghost btn--small" type="button" data-fp-clear>清空记录</button>
        <a class="btn btn--primary btn--small" href="/providers/">去挑机场</a>
      </div>
    </div>

    <div class="fp-summary" data-fp-summary hidden></div>

    <div class="panel fp-panel">
      <div class="fp-list" data-fp-list></div>
      <div class="fp-empty" data-fp-empty>
        <p class="fp-empty__title">还没有记录</p>
        <p>在机场详情页点“一键购买 / 访问官网”会自动记一笔；也可以点“标记已购 / 记到期”手动添加订阅与到期日。</p>
        <a class="btn btn--primary" href="/providers/">浏览机场</a>
      </div>
    </div>

    <div class="grid grid--3 fp-how">
      <div class="feature">
        <span class="feature__num">1</span>
        <h3>自动记点击</h3>
        <p>每次从本站跳转到机场官网，都会在本机留下一条足迹，方便回头找。</p>
      </div>
      <div class="feature">
        <span class="feature__num">2</span>
        <h3>手动记到期</h3>
        <p>买完后标记周期与到期日；到期前 7 天内，站内会出现续费提醒。</p>
      </div>
      <div class="feature">
        <span class="feature__num">3</span>
        <h3>只在本机</h3>
        <p>数据存在浏览器 localStorage，清缓存或换设备会丢失；不涉及任何账号与个人身份。</p>
      </div>
    </div>
  </div>
</section>
