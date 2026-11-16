<div class="shell">
  <header class="page-head">
    <h1>智能匹配</h1>
    <p>回答三个问题，按预算、月流量和线路偏好给 <?= (int) (dataset_updates()['providers'] ?? 0) ?> 家机场打分，筛出更接近你需求的 4 家。</p>
  </header>

  <div class="quiz" data-quiz>
    <div class="quiz__progress" data-quiz-progress aria-hidden="true">
      <span class="is-done"></span>
      <span></span>
      <span></span>
    </div>

    <section class="quiz__step is-active" data-quiz-step data-key="budget">
      <h2>你的月均预算是多少？</h2>
      <p class="quiz__hint">按人民币月均成本计算，年付、季付会折算成月均。</p>
      <div class="quiz__options">
        <button class="quiz__option" type="button" data-quiz-option data-value="20"><b>￥20 以内</b><span>轻量使用、备用线路</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="35"><b>￥20–35</b><span>日常浏览与视频</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="60"><b>￥35–60</b><span>重度使用、多人共享</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="200"><b>￥60 以上</b><span>高端专线、企业场景</span></button>
      </div>
    </section>

    <section class="quiz__step" data-quiz-step data-key="traffic">
      <h2>每月大概需要多少流量？</h2>
      <p class="quiz__hint">按入门套餐的月均流量估算，实际用量因人而异。</p>
      <div class="quiz__options">
        <button class="quiz__option" type="button" data-quiz-option data-value="60"><b>60GB 以内</b><span>网页、聊天、偶尔视频</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="150"><b>60–150GB</b><span>常规视频与下载</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="300"><b>150–300GB</b><span>高频视频、多设备</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="500"><b>300GB 以上</b><span>团队、4K、重度下载</span></button>
      </div>
    </section>

    <section class="quiz__step" data-quiz-step data-key="priority">
      <h2>你最在意什么？</h2>
      <p class="quiz__hint">只能选一个，系统会把它作为主要加分项。</p>
      <div class="quiz__options">
        <button class="quiz__option" type="button" data-quiz-option data-value="stable"><b>稳定优先</b><span>专线、低延迟、少折腾</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="value"><b>性价比</b><span>单位流量价格更低</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="unlock"><b>流媒体 / AI 解锁</b><span>Netflix、Disney+、ChatGPT</span></button>
        <button class="quiz__option" type="button" data-quiz-option data-value="regions"><b>地区覆盖</b><span>节点国家与地区更多</span></button>
      </div>
    </section>

    <div class="quiz__nav">
      <button class="btn btn--ghost" type="button" data-quiz-back hidden>上一步</button>
      <span style="font-size:.74rem;color:var(--muted-2);align-self:center">选择后自动进入下一步</span>
    </div>

    <div class="quiz__results" data-quiz-results></div>
  </div>

  <div class="panel" style="margin-top:1.5rem">
    <h3 style="font-size:.95rem;margin-bottom:.45rem">评分逻辑</h3>
    <p style="margin:0;color:var(--muted);font-size:.8rem;line-height:1.65">
      预算和流量是硬条件，超出越多扣分越多；线路偏好按专线、单位流量价、解锁或地区覆盖加分。
      结果只用于缩小范围，不构成购买建议。价格与可用性以服务商官网为准。
    </p>
  </div>
</div>
