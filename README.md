# AI 线路实验室（aixianlu.com）

面向国内 AI 开发者的机场（代理服务）对比、线路图谱与 AI 工具网络选型站点。

## 当前状态

- 可运行形态：PHP 8 + SQLite + 预构建静态 HTML，位于 `apps/proxy-atlas/`，是 Phase 1 的过渡实现。
- 数据规模：119 家机场、447 个套餐选项、8 个 AI 工具与每日 AI 新闻。
- 目标架构：Drupal 10/11 内容与数据后端 + Flutter Web 前端，规划见 `docs/architecture/aixianlu_architecture_and_monetization_masterplan_v1.1.md`。

## 目录结构

```
.
├── apps/
│   └── proxy-atlas/        # 当前 MVP：种子数据、导入/构建、后台、模板、部署说明
├── docs/
│   └── architecture/       # 架构与商业化规划文档
└── README.md
```

按规划后续新增（尚未创建）：

| 目录 | 用途 |
| --- | --- |
| `web/modules/custom/dx_aixianlu/` | Drupal 自定义模块：Merchant、MetricSnapshot、Review 实体与版本化 API |
| `clients/flutter_web/` | Flutter Web 主站：信誉榜、详情/决策页、探针下载页 |
| `probes/` | 开源测速探针与上报协议 |

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

## 数据与合规

- 只整理公开信息，机场详情保留来源链接；无法核实的字段明确标注“待核实”。
- 推广链接与邀请码在页面披露；不使用来源站点的推广链接或评测原文。
- 不提交 `.env`、SQLite 运行时数据库、抓取原文与构建产物。
- 新闻仅保留标题、短摘要、来源名称与链接、发布时间、分类与标签，不转载全文或图片。
