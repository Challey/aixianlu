# AIXianlu.com 开发计划 (Development Plan)

> 版本：v1.0  |  创建：2026-11-16  |  基于架构文档 v1.1 与现有 proxy-atlas MVP

## 总体策略（务实路径）

当前 `apps/proxy-atlas` 已是一个成熟的 Phase 1 产品：
- 119 家机场 + 447 套餐
- 静态 HTML 预构建（SEO 友好、首屏极快）
- 完整后台、Affiliate 点击追踪、AI 工具适配矩阵、新闻系统
- 本地足迹与到期提醒

**不建议立即推倒重写为 Drupal + Flutter。**  
正确路径是：**先上线现有 MVP → 在现有栈上快速验证商业闭环 → 并行准备目标架构组件 → 数据与流量成熟后再平滑迁移/混合。**

---

## 阶段划分

### Phase 0：上线（本周，2026-11-16 当周）

**目标**：`aixianlu.com` 正式对外服务。

| 任务 | 负责人 | 状态 | 说明 |
|------|--------|------|------|
| 购买海外 VPS（推荐新加坡 / 日本 / 美西轻量） | 你 | 待启动 | 见 `apps/proxy-atlas/DEPLOY.md` |
| 域名解析 + HTTPS | 你 | 待启动 | 主域名 + 备用域名 301 |
| 部署 proxy-atlas（Nginx + PHP-FPM + SQLite） | 你 | 待启动 | 生产 `.env`、强密码、IP 限制 /admin |
| 首次构建 + 健康检查 | 你 | 待启动 | `php bin/build.php` + `bin/health.php` |
| 基础监控与备份 | 你 | 待启动 | SQLite 每日备份、简单 uptime |

**成功标准**：站点可访问、点击跳转正常、后台可登录、IndexNow/百度推送可用。

---

### Phase 1：现有栈增强与商业闭环验证（2–4 周）

**核心目标**：用最小成本跑通「流量 → 信任 → Affiliate 转化」漏斗，同时为后续架构铺路。

#### 1.1 产品与数据增强
- [ ] 首页/列表增加「风险雷达」与稳定性提示（基于现有字段 + 人工标注）
- [ ] 优化 `/match/` 智能匹配逻辑（加入运营商偏好、AI 工具权重）
- [ ] 详情页强化「决策信息」：对比表、证据等级、最近更新时间
- [ ] 增加 8–12 篇高意图 SEO 长尾文章（Cursor 连接问题、Claude 稳定方案、电信/联通差异等）
- [ ] 完善 36 家「官网待补录」机场数据

#### 1.2 探针原型（开源）✅ 已完成 v0.2.0
- [x] 设计探针上报协议（JSON Schema）→ `probes/PROTOCOL.md` v0.2.0
- [x] 实现 Python 轻量 CLI 探针（`probes/`，10 个默认目标含 5 个模型级端点；TTFB、TLS 版本、重试、错误双层分类）
- [x] 简单防刷（token 哈希存储 + HMAC-SHA256 规范 JSON 签名 + 时间窗 + 按探针限速 + 去重 + 设备指纹 ID）
- [x] 在 proxy-atlas 增加「探针数据接收」接口：`/api/v1/metrics/report`、`/api/v1/metrics/latest`、后台「探针数据」页、`bin/probe_token.php`、`bin/probe_snapshot.php`
- [x] 开源仓库 + README，引导用户贡献（`probes/README.md`，含隐私与密钥说明）
- [ ] 第一批外部贡献者：社区帖 + 一键安装脚本 + 贡献榜单页面
- [ ] 定时/守护模式与自动上报（v0.3.0）

#### 1.3 运营与转化
- [ ] 批量申请并录入真实 Affiliate 链接
- [ ] Telegram 实时播报频道上线
- [ ] 建立简单转化看板（点击 → 注册估算）
- [ ] V2EX / Linux.do / 即刻 等社区冷启动内容

**成功标准**：日均有效点击可观、有稳定 SEO 入口、探针有第一批外部贡献者。

---

### Phase 2：目标架构落地（并行 / 后续 4–8 周）

当 Phase 1 数据闭环验证后，启动目标架构组件。

#### 2.1 Drupal 自定义模块 `dx_aixianlu`
路径：`web/modules/custom/dx_aixianlu/`（或独立 Drupal 实例）

- [ ] 创建 Merchant 实体（对应现有 providers）
- [ ] 创建 MetricSnapshot 实体（延迟、成功率、丢包、模型级连通性）
- [ ] 创建 Review / Note 实体（人工评测与风险标注）
- [ ] 暴露版本化 JSON:API / REST
- [ ] 数据迁移脚本：SQLite → Drupal 实体
- [ ] Redis 缓存层 + 异步写入设计

#### 2.2 Flutter Web 客户端
路径：`clients/flutter_web/`

- [ ] 初始化 Flutter Web 项目（深色极客风格）
- [ ] 首页实时信誉榜（运营商 + AI 工具双筛选）
- [ ] 详情/决策页
- [ ] 探针下载与贡献引导页
- [ ] 对接当前 proxy-atlas API 或未来 Drupal API（可切换）
- [ ] Cloudflare Pages 部署

#### 2.3 探针正式版
路径：`probes/`

- [ ] 正式上报协议 + 安全机制（当前 v0.2.0 已实现签名 + 限速 + 去重；正式版需加服务端 nonce 挑战防重放与多租户配额）
- [x] 多端点测试（模型级）：OpenAI Chat/Embeddings、Anthropic Messages、Gemini generateContent（可配置自带密钥）
- [ ] 贡献积分与激励设计
- [ ] 与 MetricSnapshot 打通（ interim：`apps/proxy-atlas` 的 `probe_snapshots` 表即为快照模型，迁移时字段一一对应）

---

### Phase 3：规模化与多元变现（后续）

- 透明度认证体系（B 端）
- AI API 智能网关 SaaS
- 白皮书与数据资产化
- 品牌防失联矩阵完善

详见 `docs/architecture/aixianlu_architecture_and_monetization_masterplan_v1.1.md`。

---

## 目录规划（目标状态）

```text
.
├── apps/
│   └── proxy-atlas/              # 当前生产 MVP（Phase 0–1）
├── clients/
│   └── flutter_web/              # Flutter Web 主站（Phase 2）
├── probes/                       # 开源测速探针（Phase 1 原型 → Phase 2 正式）
├── web/                          # Drupal 相关（可选独立实例）
│   └── modules/custom/dx_aixianlu/
├── docs/
│   ├── architecture/             # 架构与商业化文档
│   └── DEVELOPMENT_PLAN.md       # 本文件
└── README.md
```

---

## 立即行动清单（本周）

1. **完成 Phase 0 上线**（最高优先级）。
2. 创建本仓库 Issues，按 Phase 拆解任务。
3. 开始 Phase 1.1 的 SEO 文章与数据补全。
4. ~~设计探针上报协议草稿~~（已完成：`probes/PROTOCOL.md` v0.2.0 + PHP 接收端已联调）。
5. 上线后为探针配 cron：`php bin/probe_snapshot.php --hours=6`（每小时）与发放首个官方探针 token。

---

## 原则

- **信任优先**：公开测试方法、可复现数据、风险标注。
- **决策工具定位**：帮助用户快速做线路选择，而不是纯信息展示。
- **可演进**：现有 PHP 栈继续服务生产，新组件并行生长，数据可迁移。
- **最小可行**：每个阶段都有明确可上线的增量价值。

---

*本文档随项目进展持续更新。*
