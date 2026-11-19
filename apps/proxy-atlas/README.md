# AI 线路 · Proxy Atlas

一个面向国内 AI 用户的机场对比与线路图谱站点：整理公开信息、AI 工具网络要求与每日 AI 事件，并通过推广链接完成注册转化。

- **前台**：纯静态 HTML，预构建输出到 `public/`，适合 SEO、CDN 和快速打开。
- **后台**：PHP 8 + SQLite，用于机场/套餐维护、AI 工具适配、新闻审核、静态站重建、点击统计与代理申请审核。
- **数据**：`data/seed/providers.json` 与 `data/seed/ai_tools.json` 是数据种子；SQLite 是后台与构建的数据源；公共页面运行时**不查数据库**。

当前收录 **119 家机场、447 个套餐选项、8 个 AI 工具与每日 AI 新闻**（含二猫博客公开清单补充；线路未标注的条目在页面显示为“线路待核实”）。

---

## 快速开始（本地）

要求：PHP ≥ 8.1（需 `pdo_sqlite`）、Python ≥ 3.9（仅重新抓取时需要 `requests`、`beautifulsoup4`）。

```bash
cd apps/proxy-atlas

# 一条命令：初始化数据库、构建静态站、启动 PHP 内置服务器
./scripts/dev.sh
```

或手动执行：

```bash
php bin/import.php      # 从 data/seed/providers.json 建库/覆盖机场与套餐
php bin/build.php       # 生成 public/ 静态站
php -S 127.0.0.1:8080 -t public public/router.php
```

打开：

- 前台：<http://127.0.0.1:8080/>
- 后台：<http://127.0.0.1:8080/admin/>
- 默认账号：`admin / admin`（仅本地开发，登录后立即修改）

---

## 页面与路由

| 路径 | 说明 |
| --- | --- |
| `/` | 首页：搜索、数据概览、快速选择、精选机场 |
| `/ai/` | AI 工具索引：网络要求与候选机场入口 |
| `/ai/{slug}/` | AI 工具详情：网络要求、候选机场、证据等级、相关事件 |
| `/news/` | AI 前线：每日公开 RSS 摘要，按分类筛选 |
| `/news/{id}/` | 新闻详情：摘要、影响解读、相关事件、原文链接（NewsArticle JSON-LD） |
| `/news/feed.xml` | AI 前线 RSS 订阅（最近 50 条，链接指向站内详情） |
| `/my/` | 我的足迹：本机浏览器的点击/已购/到期记录与续费提醒（noindex，无账号） |
| `/providers/` | 全部机场表格，支持搜索、线路/协议/地区/预算筛选与排序 |
| `/providers/{slug}/` | 机场详情：套餐、价格、地区、解锁、优惠、AI 工具适配、来源 |
| `/compare/?p=a,b,c` | 最多 4 家并排对比 |
| `/match/` | 三步智能匹配，按预算/流量/偏好筛出 4 家 |
| `/network/` | 上游网络：线路类型、协议、地区聚合 |
| `/status/` | 联合公开探测：稳定度评分、端点 × 运营商矩阵、可用率/可达率/TTFB 明细（静态烘焙 + 只读 API 刷新） |
| `/guide/` | 选择指南：直连 / 中转 / IPLC·IEPL 专线科普、对比表与按场景选型建议 |
| `/partner/` | 代理合作说明与申请表单 |
| `/about/` | 数据口径、来源、隐私与免责声明 |
| `/go/{slug}` | 记录点击并 302 到服务商官网；官网待补录时显示提示页 |
| `/api/agent-apply.php` | 代理申请接口（POST，JSON 或表单） |
| `/api/v1/metrics/report` | 探针上报接口（POST，Bearer token + HMAC 签名） |
| `/api/v1/metrics/latest` | 探针指标只读聚合（GET，`hours`/`isp`/`name`） |
| `/admin/probes.php` | 后台「探针数据」：token 发放/撤销、线路质量快照、最近上报 |
| `/admin/` | 管理后台（登录后访问） |

公共 HTML 都由 `bin/build.php` 生成，**修改后台数据后必须重建**，前台才会更新。

---

## 目录结构

```text
apps/proxy-atlas/
├─ assets/                 设计系统 CSS + 前端/后台 JS（构建时复制到 public/assets）
├─ app/                    bootstrap、DB、helpers、repository、ai_repository、probe_repository、schema.sql、probe_schema.sql
├─ bin/
│  ├─ scrape.py            抓取公开索引与评测页，生成种子（不抓联盟链接/图片/原文）
│  ├─ import.php           种子 → SQLite
│  ├─ import_ai.php        只重建 AI 工具与机场适配
│  ├─ collect_news.py      采集公开 RSS，写入 ai_news（仅标题/摘要/链接）
│  ├─ build.php            SQLite → public/ 静态站
│  ├─ daily.sh             每日流水线：采集 → 构建 → 健康检查 → 搜索推送
│  ├─ health.php           检查官网缺失、新闻过旧、构建过期
│  ├─ probe_token.php      探针 token：issue / list / revoke / activate
│  ├─ probe_snapshot.php   小时快照聚合与明细清理
│  ├─ probe_parity.php     与 Python 探针的签名跨语言一致性检查
│  └─ ping.php             IndexNow / 百度普通收录推送
├─ data/
│  ├─ seed/providers.json  统一后的机场与套餐数据
│  ├─ seed/ai_tools.json   8 个 AI 工具的网络要求与条目
│  ├─ seed/news_sources.json  RSS 采集源与权重
│  ├─ seed/official-urls.json  官网映射（已去除推广参数）
│  └─ raw/                 抓取缓存（gitignored，可删除）
├─ public/                 可部署的静态站 + PHP 入口（go/admin/api）
├─ storage/                SQLite（gitignored）
├─ templates/              静态页模板
└─ scripts/dev.sh          本地一键启动
```

---

## 数据与构建流程

```text
公开页面 ──scrape.py──> data/seed/providers.json ─┐
data/seed/ai_tools.json ─────────────────────────┤
                                                 ▼
                                            import.php
                                                 │
公开 RSS ──collect_news.py──> ai_news        SQLite（后台维护）
                                                 │
                                            build.php
                                                 ▼
                                          public/ 静态 HTML
```

常用命令：

```bash
# 重新抓取公开数据（可选；会读取 official-urls.json 保留官网）
python3 bin/scrape.py
php bin/import.php
php bin/build.php

# 只更新 AI 工具与机场适配
php bin/import_ai.php

# 采集最新 AI 新闻并重建
python3 bin/collect_news.py
php bin/build.php
```

`import.php` 会用种子**覆盖**机场与套餐，但保留代理申请和点击记录。后台手工改过的机场也会被种子覆盖，因此长期维护建议二选一：

1. 以 SQLite 后台为主，不再从种子导入；或
2. 把修改回写到 `data/seed/providers.json`，再导入。

### 官网链接

- 详情页“访问官网”指向服务商官网入口；从公开跳转中解析域名，**去除所有 `code`/`aff`/`partner` 推广参数和优惠码**。
- 当前 119 家中有 83 家已补官网，36 家因来源仅提供联盟中转页或域名暂不可达而留空，页面会显示“官网地址待补录”。
- 补录或修正：后台 → 机场数据 → 编辑 → “官网地址 / 联盟地址”。`/go/{slug}` 优先使用联盟地址，其次官网地址。
- 后续如接入真实返佣链接，必须在页面保留推广披露，并遵守投放平台规则。

### 价格口径

- **入门价**：最低可用套餐原价，保留月/季/半年/年单位。
- **月均**：年付 ÷ 12、季付 ÷ 3；美元按 1:7.2 估算；按量包不参与月均排序。
- **单位流量价**：月均价格 ÷ 月均流量。
- 月付下线的机场会单独标注，避免把年付总价误读成月价。

### 节点口径

- **节点数**指不同 IP 入口的数量下限，统一显示为 `N+`。
- 来源明确披露节点数的（如 ImmTelecom 的“100+”、AmyTelecom 的“50+”）按披露值展示。
- 多数来源只列出服务器地区、不公布每个地区的 IP 数，此时按地区数统计，每个地区至少 1 个独立 IP，实际节点可能更多。
- 后台机场编辑页可手工填写披露值与说明，重建后前台更新。

---

## AI 工具与新闻

### 数据模型

- `ai_tools`：工具条目（slug、名称、厂商、分类、网络要求、常用地区、IP 敏感度、账号风险、官网）。
- `provider_ai_fit`：机场 × 工具的证据矩阵，`UNIQUE(provider_id, tool_id)`。
- `ai_news`：新闻摘要（GUID、标题、短摘要、来源、原文链接、发布时间、分类、标签、关联工具）。

首批 8 个工具：ChatGPT、Claude、Claude Code、Gemini、Cursor、GitHub Copilot、Qoder 国际版、Perplexity。种子在 `data/seed/ai_tools.json`，内容按官方页面与公开资料整理，不声称与任何厂商存在隶属关系。

### 证据等级

| 等级 | 含义 |
| --- | --- |
| `verified` | 本站实测，需人工录入，重建时保留 |
| `source` | 机场公开页面明确提及该工具 |
| `inferred` | 只按线路、地区、节点数和现有 AI 提及推断，未实测 |
| `unknown` | 信息不足 |

`php bin/import_ai.php` 会删除并重建 `source` / `inferred` 行，但保留人工录入的 `verified` 行。页面不写“稳定解锁”等承诺，价格与可用性以官网为准。

### 新闻采集器

`bin/collect_news.py` 只用 Python 标准库，读取 `data/seed/news_sources.json` 中的 RSS/Atom：

- 只保存标题、短摘要、来源名称、原文链接、发布时间、分类与标签；**不保存全文、不下载图片**。
- 按 GUID 与归一化标题去重；默认保留 14 天内内容，自动清理 90 天前数据并限制最多 500 条。
- 规则分类：人物、产品、芯片算力、融资并购、模型、行业、政策监管、研究。
- 按关键词把新闻关联到 AI 工具 slug，前台再关联到候选机场。
- 拒绝带 `DOCTYPE` / `ENTITY` 的 XML 和超过 6MB 的响应。

常用参数：

```bash
python3 bin/collect_news.py --dry-run --limit 5    # 只看结果，不写库
python3 bin/collect_news.py --source "TechCrunch AI"
python3 bin/collect_news.py --limit 20 --prune-days 90
```

每日定时（示例，07:00 采集后重建）：

```cron
0 7 * * * cd /path/to/apps/proxy-atlas && ./bin/daily.sh >> storage/logs/cron.log 2>&1
```

后台 `/admin/news.php` 可搜索、按状态筛选、下线/发布、删除；后台“构建与导入”也提供“采集 AI 新闻 + 构建”一键任务。

### 内容边界

- 新闻版权归原媒体所有，站点只做摘要与链接，原文以 `rel="nofollow noopener"` 外链。
- 人物类热点只保留来源可核验的表述，不做二次演绎，也不把私人传闻写成事实。
- “影响”说明由规则生成，只用于把事件与网络选型关联，不构成投资或采购建议。

---

## 后台

登录 `/admin/` 后可用：

- **仪表盘**：机场/套餐数量、点击、代理申请、构建是否最新。
- **机场数据**：搜索、编辑机场字段与套餐、设置入门套餐；保存后按入门套餐重算价格摘要。列表内可直接修改**排序权重**并点 ✓ 保存，自动重建前台。权重决定首页精选前 6 家与列表「推荐顺序」，越大越靠前；初始推荐权重已写入种子并在后台可随时调整。
- **代理申请**：查看、通过/拒绝、设置佣金比例、删除；生成独立子 ID。
- **推广链接**：批量维护 119 家机场的专属推广链接、邀请码、推广状态与备注，支持筛选和 CSV 导出。
- **佣金**：手动录入或 CSV 导入各机场结算数据，查看累计、待结算与本月收益。
- **AI 新闻**：采集源说明、搜索/筛选、发布/下线、删除。
- **点击记录**：1/7/30/90 天统计、按机场筛选、CSV 导出。
- **站点设置**：站点名称/描述、数据更新时间、联系方式、推广披露、后台密码。
- **构建与导入**：后台执行 `build.php` / `import.php`（需 PHP 未禁用 `exec()`）。

### 点击追踪

`/go/{slug}` 会记录机场、套餐（可选）、子 ID、campaign、时间、设备、来源页和加盐哈希 IP，然后跳转。支持：

```text
/go/wgetcloud?subid=wechat01&c=nov
/go/wgetcloud?a=A1B2C3D4          # 代理子 ID（后台申请通过后生成）
```

不记录原始 IP，不加载第三方广告脚本。

### 推广链接与邀请码

1. 在每家机场后台申请联盟/推广计划，复制**你自己账号**的专属链接。
2. 打开后台“推广链接”，批量粘贴链接、邀请码，状态设为“推广中”。
3. 前台所有“访问官网 / 通过邀请链接注册”按钮都会走 `/go/{slug}`，优先跳转 `affiliate_url`，并记录点击；机场详情页会展示邀请码与复制按钮。
4. 结算后到“佣金”页手动录入或 CSV 导入，查看累计与待结算；支持 postback 的机场可推到 `/api/postback.php`（需配置 `POSTBACK_TOKEN`）。

不要把机场后台密码写进仓库、`.env` 或后台备注。不同机场的联盟后台大多没有统一 API，链接录入和佣金结算是必要的人工步骤。

### 本地足迹与到期提醒（无账号）

- 数据只存浏览器 `localStorage`（键 `atlas.footprint.v1`），不上传、不记名；`/my/` 页 `noindex,follow`，不进 sitemap。
- 点击任意 `/go/{slug}` 链接自动记一条足迹；机场详情页“标记已购 / 记到期”打开 `<dialog>`，选周期自动算到期日，可填备注。
- 到期前 7 天或已过期：全站顶部出现续费提醒条（当天可关闭，`sessionStorage` 记关闭），导航“我的足迹”出现数量角标。
- `/my/` 支持改到期、删除、清空；状态排序：已过期 → 即将到期 → 使用中 → 已购 → 未标记。
- 策略结论（2026-11-09 用户认可）：**不做用户登录、不做统一代售结算**——联盟后台无统一 API，代售涉支付合规风险；仅当某服务商提供官方 reseller API + 书面协议时再评估。

### 一键预填购买深链

- 后台 → 站点设置 → “购买深链模板”：JSON，`slug => URL`，URL 支持 `{invite}`/`{coupon}` 占位符（自动 urlencode 替换为该机场的邀请码/优惠码）。
- 配置后机场页购买按钮变为“一键购买 · 预填邀请码”，走 `/go/{slug}?src=buy`：302 到模板 URL，点击记录 `campaign='buy'`。
- 模板需要占位符但对应码为空、或 URL 非 http(s) 时自动回退到联盟/官网地址，不会跳去缺码的结算页。
- 清空模板即恢复普通跳转；修改后需重建静态站。

---

## 探针数据接收

开源探针（`probes/`，协议 v0.2.0，见 `probes/PROTOCOL.md`）把中国大陆到 AI 端点的连通性结果上报到这里。

- 接收：`POST /api/v1/metrics/report`（`public/api/metrics-report.php`）→ `probe_reports` + `probe_results`。
- 只读聚合：`GET /api/v1/metrics/latest?hours=24&isp=电信&name=openai-chat`（`public/api/metrics-latest.php`，60 秒缓存，`noindex`）。返回 `summary`（窗口内综合稳定度、可用率、可达率、平均 TTFB、最差 P95、端点/运营商数）、`matrix`（端点 × 运营商稳定度矩阵，含每格样本量）、`rows`（逐 `name/model/isp` 明细）、`scoring`（评分公式、A/B/C/D 阈值、最小可信样本数）；**不返回探针 ID、IP 或原始载荷**。
- 后台：「探针数据」页发放/撤销 token（token 明文只在生成后那一次显示，经会话传递，不进 URL）、看窗口内质量快照与最近上报。
- 前台：`/status/`（`templates/status.php`）主视觉是「联合公开探测节点」的综合稳定度（大字评分 + A/B/C/D 档 + 可用率 / 可达率 / 平均 TTFB / 最差 P95 四个 KPI），下面依次是端点 × 运营商稳定度矩阵、逐端点明细证据、算法与样本量说明、透明化购买引导，开发者接入信息折叠在最底部；可按统计窗口 / 运营商筛选。
- 评分只在 PHP 侧算（`app/probe_repository.php` 的 `probe_stability()` / `probe_status_summary()` / `probe_status_matrix()`），静态烘焙与只读 API 用同一套函数，前台 JS 只搬运服务端给出的数字，不在浏览器里重算，避免“页面一套、接口一套”。
- 页面本身是静态烘焙的，「刷新」与切换筛选走 `GET /api/v1/metrics/latest`，首屏不依赖数据库也能拿到最新一小时的聚合；窗口内没有样本时矩阵与明细都会换成明确的空状态（不会残留上一窗口的数字）。

### 防刷与签名

| 机制 | 实现 |
| --- | --- |
| 身份 | `Authorization: Bearer <token>`，库内只存 SHA-256；`probe_id` 必须与 token 绑定 |
| 完整性 | HMAC-SHA256 覆盖规范 JSON（去 `signature`、键排序、无空白、非 ASCII 不转义、浮点固定 6 位小数），密钥 = `HMAC(token, "aixianlu-probe/v1/signing")` |
| 新鲜度 | `timestamp` 与服务器时间差需 ≤ `PROBE_TIMESTAMP_SKEW_SECONDS`（默认 300） |
| 限速 | 每探针最小间隔 `PROBE_MIN_INTERVAL_SECONDS`（60）+ 每小时上限 `PROBE_HOURLY_LIMIT`（120），超限返回 `429` 与 `Retry-After` |
| 去重 | `dedupe_key = sha256(probe_id|timestamp|canonical_hash)` 唯一索引，重复返回 `409` |
| 载荷 | 结果条数 ≤ `PROBE_MAX_RESULTS`（200）、请求体 ≤ `PROBE_MAX_BODY_BYTES`（256 KB）、枚举白名单校验（`422`） |
| 兼容 | `PROBE_ALLOW_UNSIGNED=1` 时仅对 0.1.x 载荷放行未签名上报，并在记录中标 `signature_ok=0` |

跨语言一致性用共享测试向量校验：`php bin/probe_parity.php`（对照 `probes/tests/fixtures/signing_vector.json`）。

### 常用命令

```bash
php bin/probe_token.php issue --label="电信-广东-VPS-01"   # 发放（明文只打印一次）
php bin/probe_token.php list / revoke <probe_id> / activate <probe_id>
php bin/probe_snapshot.php --hours=48                     # 小时快照聚合
php bin/probe_snapshot.php --hours=6 --prune-days=30      # 聚合并清理明细（建议每小时 cron）
```

表结构在 `app/probe_schema.sql`，由 `ensure_schema()` 自动建表；时间统一按 UTC 存储与分桶。公开展示只出现在 `/status/`：口径与「风险雷达」保持一致——必须同时给出样本量、覆盖小时与采样来源，未配置密钥的模型级目标只说明“网络可达”，不写成“服务可用”。稳定度 = 可用率 50% + 可达率 20% + 延迟一致性 30%（`1 − (最差P95 − 平均) / 平均`），区域拦截每次 −10（上限 −20）、限流每次 −5（上限 −10），A ≥ 90 / B ≥ 75 / C ≥ 60 / D < 60；不足 5 次的分组带 `*`，窗口不足 20 次时页面把整体评级标为“仅供方向性参考”。公式与阈值同时写在 `probes/PROTOCOL.md`，任何人可用同一支探针复现。注意默认目标表把无凭证时的 401/403 列入 `expected_status`，因此这里的“可用率”是“按预期应答率”，不是“账号可用率”。前台快照的新鲜度取决于 `bin/probe_snapshot.php` 与 `bin/build.php` 的 cron 频率（建议每小时），页面上的“数据生成”时间即为烘焙时间。

---

## 部署（国际出口 / 非备案）

完整的主机选型、Nginx、备份、安全与中国访问说明见 [DEPLOY.md](DEPLOY.md)。

推荐：**一台海外 VPS + Nginx + PHP-FPM + SQLite**，站点根目录指向 `public/`，采集器与构建任务跑在同一台机器。静态 HTML 由 Nginx 直接返回，只有 `/go/`、`/admin/`、`/api/` 进入 PHP。

1. 上传代码，确保 `storage/` 可写；生产环境建议把 SQLite 放到 Web 根目录之外。
2. 复制 `.env.example` 为 `.env`，至少设置：
   ```ini
   PROXY_SITE_URL=https://aixianlu.com
   PROXY_TZ=Asia/Shanghai
   PROXY_IP_SALT=随机长字符串
   PROXY_ADMIN_USER=admin
   PROXY_ADMIN_PASSWORD=一个强密码
   # 探针接收（可选，有贡献者上报时再调）
   PROBE_MIN_INTERVAL_SECONDS=60
   PROBE_HOURLY_LIMIT=120
   PROBE_ALLOW_UNSIGNED=0
   ```
3. 首次部署：`php bin/import.php && php bin/build.php`
4. 后台 → 站点设置，确认 `site_url`、站点名称、合作邮箱（默认 `partner@example.com`）无误，再构建一次。
5. 用 HTTPS；限制 `/admin/` 访问（IP 白名单或 HTTP Basic Auth）；定期备份 `storage/proxy-atlas.sqlite`。

Nginx 参考：

```nginx
server {
    listen 443 ssl http2;
    server_name aixianlu.com www.aixianlu.com;
    root /var/www/proxy-atlas/public;
    index index.html index.php;

    error_page 404 /404.html;

    # 静态页优先
    location / {
        try_files $uri $uri/ =404;
    }

    location = /admin { return 301 /admin/; }

    # /go/{slug} → go.php（go.php 会解析 REQUEST_URI）
    location ~ ^/go/([a-z0-9-]+)/?$ {
        rewrite ^/go/([a-z0-9-]+)/?$ /go.php last;
    }

    # 探针接口版本化路径 → PHP 入口
    location = /api/v1/metrics/report { rewrite ^ /api/metrics-report.php last; }
    location = /api/v1/metrics/latest { rewrite ^ /api/metrics-latest.php last; }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(env|git) { deny all; }
}
```

`/admin/`、`/api/` 与 `/go/{slug}` 会进入 PHP；其余公共页面全部由 Nginx 直接返回静态文件。部署后务必访问 `/go/{slug}` 验证 302 与点击记录。

如果使用 Apache，需开启 `mod_rewrite`：

```apache
RewriteEngine On
RewriteRule ^go/([a-z0-9-]+)/?$ go.php [L,QSA]
RewriteRule ^api/v1/metrics/report/?$ api/metrics-report.php [L,QSA]
RewriteRule ^api/v1/metrics/latest/?$ api/metrics-latest.php [L,QSA]
```

并把站点根目录指向 `public/`，禁止访问 `storage/` 与 `.env`。Apache 默认会剥离 `Authorization`，需加 `RewriteCond %{HTTP:Authorization} .` + `RequestHeader set`（或 `CGIPassAuth On`）；否则让探针改用等价的 `X-Probe-Token` 头，接收端两者都支持。

探针数据建议加一条 cron（详见「探针数据接收」）：

```cron
5 * * * * cd /var/www/proxy-atlas && php bin/probe_snapshot.php --hours=6 >> storage/logs/probe.log 2>&1
```

### 为什么不用 Drupal

当前形态是“数据表 + 静态详情页 + 轻后台”，页面数量可控、SEO 与首屏速度优先。Drupal 适合复杂内容工作流与多角色编辑，但会引入运行时渲染、缓存层和更高的运维成本；本站用 PHP/SQLite + 预构建已覆盖需求。未来如果出现多编辑、多语言、复杂权限或广告订单，再评估迁移。

---

## 当前边界

- 代理合作目前是**申请与人工审核**，独立代理后台、转化回传、结算与发票在第二阶段。
- 点击统计只到“跳转”，不包含服务商侧的注册/付费转化。
- 数据来自公开页面，价格与解锁能力可能滞后；页面已标注“以服务商官网为准”。
- 36 家官网待补录；后台补录后需重建静态站。

---

## 会话恢复 / 当前状态（2026-11-10）

- 本地服务：systemd 用户服务 `proxy-atlas-dev`（0.0.0.0:8080，已 enabled + linger，重启自恢复）。PC 用 <http://127.0.0.1:8080/>，手机用 <http://192.168.6.128:8080/>。
- 已完成：苹果风 UI 重构（首页/新闻列表/新闻详情）、本地足迹与到期提醒、购买深链与后台配置；端到端验证 15/15 通过（Playwright），深链 302 与 `campaign=buy` 已验证。
- 已完成（2026-11-10）：后台可调排序权重——机场列表内快捷保存并自动重建；默认推荐排序、首页精选前 6 均按权重（初始权重已写入 `data/seed/providers.json`，重导入不会丢）；机场列表筛选收纳为可展开面板（角标计数、重置、URL 参数自动展开）；首页与详情页去掉重复入口。端到端验证 15/15 通过。
- 已完成（2026-11-10）：`/guide/` 选择指南文章——直连 / BGP 中转 / IPLC·IEPL 专线科普、对比表、本站线路分布、常见误区、按场景选型与术语表；Article + Breadcrumb JSON-LD，已进 sitemap 与顶部导航/页脚；network 页与机场列表均有入口。全站 6870 条内链检查 0 失效。
- 待办：**下周上线**（2026-11-16 当周）——购买海外 VPS、部署 `aixianlu.com`（`jichangditu.com` 301 备用），见 [DEPLOY.md](DEPLOY.md)。用户说“开始上线”即启动。
- 全局约定：会话重启后先查中断任务并自动继续（所有项目、所有任务），见 `~/.qoder/memory/user-behavior-14709ac0.md`。
- 约束：生产 Drupal 根目录 `/home/wwwroot/drupalX` 不动，只在 `.worktrees/proxy-atlas` 内工作；未经要求不提交 git。

---

## 验收清单

```bash
# 语法检查
php -l bin/import.php && php -l bin/build.php && php -l bin/import_ai.php
python3 -m py_compile bin/scrape.py bin/collect_news.py

# 重建并检查关键页面
php bin/build.php
curl -s -o /dev/null -w '%{http_code} /\n' http://127.0.0.1:8080/
curl -s -o /dev/null -w '%{http_code} /providers/\n' http://127.0.0.1:8080/providers/
curl -s -o /dev/null -w '%{http_code} /ai/\n' http://127.0.0.1:8080/ai/
curl -s -o /dev/null -w '%{http_code} /ai/chatgpt/\n' http://127.0.0.1:8080/ai/chatgpt/
curl -s -o /dev/null -w '%{http_code} /news/\n' http://127.0.0.1:8080/news/
curl -s -o /dev/null -w '%{http_code} /news/feed.xml\n' http://127.0.0.1:8080/news/feed.xml
curl -s -o /dev/null -w '%{http_code} /compare/\n' http://127.0.0.1:8080/compare/
curl -sI 'http://127.0.0.1:8080/go/wgetcloud' | head -5

# 后台
curl -s -o /dev/null -w '%{http_code} /admin/\n' http://127.0.0.1:8080/admin/
```

上线前请确认：`site_url` 已改为正式域名、后台密码已修改、`storage/` 不可公网访问、`/go/` 可正常 302、sitemap 域名正确。
