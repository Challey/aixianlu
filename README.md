# AI 线路实验室（aixianlu.com）

面向国内 AI 开发者的机场（代理服务）对比、线路图谱与 AI 工具网络选型站点。

## 当前状态

- 可运行形态：PHP 8 + SQLite + 预构建静态 HTML，位于 `apps/proxy-atlas/`，是 Phase 1 的过渡实现。
- 数据规模：119 家机场、447 个套餐选项、8 个 AI 工具与每日 AI 新闻。
- 开源探针：`probes/`（Python，v0.2.0）已可测连通性并签名上报，接收端与快照聚合在 `apps/proxy-atlas/`（`/api/v1/metrics/*`），协议见 `probes/PROTOCOL.md`；聚合结果公开在前台 `/status/`（实时状态页）。
- 上线彩排：本地点对点全链路已通过（251 个 sitemap URL、4772 条内链、后台与接口、真实探针上报），结果与待确认项见 `docs/LAUNCH_REHEARSAL.md`。
- 目标架构：Drupal 10/11 内容与数据后端 + Flutter Web 前端，规划见 `docs/architecture/aixianlu_architecture_and_monetization_masterplan_v1.1.md`，阶段拆解见 `docs/DEVELOPMENT_PLAN.md`。

## 目录结构

```
.
├── apps/
│   └── proxy-atlas/        # 当前 MVP：种子数据、导入/构建、后台、模板、部署说明
├── clients/
│   └── flutter_web/        # Flutter Web 主站（Phase 2，规划占位）
├── docs/
│   ├── architecture/       # 架构与商业化规划文档
│   ├── DEVELOPMENT_PLAN.md # 分阶段开发计划与进度
│   └── LAUNCH_REHEARSAL.md # 本地上线彩排报告与待确认清单
├── probes/                 # 开源测速探针（v0.2.0）+ 上报协议
├── web/
│   └── modules/custom/dx_aixianlu/  # Drupal 自定义模块（Phase 2，规划占位）
└── README.md
```

## 本地运行

要求 PHP ≥ 8.1（含 `pdo_sqlite`）；仅重新采集新闻时需要 Python ≥ 3.9 与 `requests`、`beautifulsoup4`。

```bash
cd apps/proxy-atlas
./scripts/dev.sh
```

- 前台：<http://127.0.0.1:8080/>
- 后台：<http://127.0.0.1:8080/admin/>
- 默认账号：`admin / admin`，仅限本地开发，登录后立即修改

手动流程与页面路由见 [apps/proxy-atlas/README.md](apps/proxy-atlas/README.md)，服务器部署见 [apps/proxy-atlas/DEPLOY.md](apps/proxy-atlas/DEPLOY.md)。

### 跑探针（可选）

要求 Python ≥ 3.10。默认只在本机测量、不外发；上报需在配置里填入平台发放的 token。

```bash
cd probes
python -m venv .venv && source .venv/bin/activate && pip install -e ".[dev]"
aixianlu-probe run                 # 本地测量，表格输出
aixianlu-probe run -o report.json  # 保存完整报告
php ../apps/proxy-atlas/bin/probe_token.php issue --label="本机"   # 服务端发放 token
```

协议与防刷规则见 [probes/PROTOCOL.md](probes/PROTOCOL.md)，使用说明见 [probes/README.md](probes/README.md)。

## 数据与合规

- 只整理公开信息，机场详情保留来源链接；无法核实的字段明确标注“待核实”。
- 推广链接与邀请码在页面披露；不使用来源站点的推广链接或评测原文。
- 不提交 `.env`、SQLite 运行时数据库、抓取原文与构建产物。
- 新闻仅保留标题、短摘要、来源名称与链接、发布时间、分类与标签，不转载全文或图片。
- 探针上报只含 ISP / 地区 / ASN / 国家，不含 IP 地址与响应正文；API 密钥运行时读取、不上报、错误信息中脱敏。服务端只保存不可逆 IP 哈希用于防刷。
