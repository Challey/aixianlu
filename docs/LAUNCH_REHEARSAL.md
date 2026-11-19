# 本地上线彩排报告（Launch Rehearsal）

> 日期：2026-11-19 ｜ 环境：WSL2 + PHP 8.5.12 内置服务器（`0.0.0.0:8080`）+ SQLite WAL
> 数据副本：`apps/proxy-atlas/storage/proxy-atlas.sqlite`（开发库，非生产）
> 结论：**功能链路全部通过，可进入 Phase 0 上线**；上线前有 6 项必须人工确认的配置（见 §5）。

---

## 1. 静态层（前台可被抓取的部分）

| 检查项 | 结果 |
| --- | --- |
| 构建 | `119 机场页 + 8 AI 工具页 + 200 新闻页 + 12 静态路由`，sitemap 337 URL |
| sitemap 全量抓取 | 251/251 → HTTP 200，无 4xx/5xx |
| 内部链接 | 4772 条同站链接全部可解析（含带查询串的筛选链接，`/providers/?line=未标注` 返回 200 且过滤生效：24 条「线路待核实」） |
| 静态资源 | 引用到的 `/assets/*` 全部存在，含 `favicon.svg` |
| SEO 元数据 | 抽样 8 个页面：title 唯一且含数据量、description/og:title 齐全、canonical 全部指向 `https://aixianlu.com` |
| 结构化数据 | 首页/机场页/新闻页/工具页/指南页含 JSON-LD；`/network/`、`/match/` 无（工具型页面，正常） |
| robots / 404 / RSS | `robots.txt` 允许全站、屏蔽 `/admin/`、`/api/`、`/go/`；`404.html` 存在；`news/feed.xml` 50 条 |
| 页面体积 | 平均 18.7 KB；最大 `providers/index.html` 344 KB（未压缩，Nginx 开 gzip 后预计 ~55 KB）、`news/index.html` 140 KB |
| `noindex` | `/my/` = `noindex,follow`；`/go/` 提示页 `noindex` + `X-Robots-Tag: noindex` |

## 2. 转化链路

| 检查项 | 结果 |
| --- | --- |
| `/go/{slug}` | 302 → 官网（`Location: https://wgetcloud.org/`），点击入库：`subid=launchtest`、`campaign=tg`、`device=desktop`、`ip_hash` 为加盐 SHA-256（不可逆） |
| 官网待补录 | `/go/{无官网 slug}` → 200 提示页（标题「官网地址待补录」），不产生 302 到空地址 |
| 关系属性 | 前台外发链接 `rel="nofollow sponsored noopener"`，符合联盟与搜索引擎要求 |
| 一键购买深链 | `checkout_templates` 未配置 → CTA 回退为「访问官网」（预期行为，配置后变「一键购买 · 预填邀请码」） |
| 足迹 | `data-fp-slug` / `data-fp-name` 已埋点，点击自动写 `localStorage` |

## 3. 后台与接口

| 检查项 | 结果 |
| --- | --- |
| 未登录访问 | `/admin/probes.php` → 302 到 `login.php` |
| CSRF | 错误 token 的登录请求不建立会话（留在登录页） |
| 后台页面 | 仪表盘/机场/推广/佣金/新闻/代理/点击/探针/设置/构建 全部 200，页面内 `Fatal/Warning/Notice/Deprecated` 命中 0；`provider-edit.php?slug=wgetcloud` 200（无 slug 时 302，正常） |
| `/api/v1/metrics/latest` | 200，3.4 ms，`Cache-Control: public, max-age=60`、`X-Robots-Tag: noindex` |
| `/api/v1/metrics/report` | GET → 405 `{"ok":false,"error":"method not allowed"}`；空体 POST → 401 `missing bearer token` |
| `/api/postback.php` | 无 token → 403 `invalid token`（`POSTBACK_TOKEN` 尚未配置） |
| `/api/agent-apply.php` | 表单路径：302 `/partner/?sent=1#apply` + 入库并生成代理码；蜜罐字段命中 → 只回成功不落库；非法渠道 → 422；**JSON 路径原为 422，已修复**（`9914490`），现与 README 声明一致 |
| 路径穿越 | `/../storage/proxy-atlas.sqlite`、`/.env` 均 404（内置服务器 + 文档根=public；Nginx 仍需显式 deny） |

## 4. 探针链路（真实上报，非模拟）

```
aixianlu-probe run -c config.yaml --upload
  → 3/3 目标可达，2/3 成功，TLSv1.3 + HTTP/2，TTFB 606–1099 ms，错误分类 http/auth_required
  → Upload: uploaded (200)
```

- 签名、时间窗、限速、去重、非法枚举、超大体、错误方法/内容类型等 24 项协议用例全部通过（详见 `probes/PROTOCOL.md` §4）。
- `probe_reports` / `probe_results` 落库，`bin/probe_snapshot.php` 聚合出小时快照，`/api/v1/metrics/latest` 按 `isp`/`name` 过滤返回正确（ISP 自动识别为「电信/广东」，未上传 IP）。
- 后台「探针数据」页可发 token（明文只在会话内一次显示、不进 URL）、撤销即时生效（`403`）、恢复后可继续上报。

## 5. 上线前必须人工确认的 6 项

| # | 项目 | 当前值 | 需要做的 |
| --- | --- | --- | --- |
| 1 | 合作邮箱 | `partner@example.com`（已烘进 `about/`、`partner/` 静态页） | 后台「站点设置」改成真实邮箱后**重新构建** |
| 2 | `INDEXNOW_KEY` / `BAIDU_PUSH_TOKEN` | 未设置 | 生成 IndexNow key；百度需先验证站点。设置后 `bin/ping.php` 自动暴露 `/<key>.txt` 并推送 |
| 3 | `POSTBACK_TOKEN` | 未设置 | 上线前生成随机值，否则佣金回传接口一直 403（安全默认，不影响站点） |
| 4 | Affiliate 链接 | 119 家全部为空 | 拿到真实推广链接与邀请码后录入；当前 CTA 是「访问官网」，合规但无佣金 |
| 5 | 官网待补录 | 36/119（30%） | 健康检查持续告警；补齐或考虑暂时下架这批机场 |
| 6 | `data_updated_at` | `2026-11-10` | 上线当天更新为实际数据日期（前台显示「数据更新于」） |

另外两条属于**约定**而非缺陷，需要你拍板：

- **统计时区**：点击与探针都按 UTC 存储与分桶（SQLite `CURRENT_TIMESTAMP` / `gmdate`），后台表头已标 UTC，但「今日/24 小时」的日界线是北京时间 08:00。若你希望按北京日切，我可以把查询改成 `datetime('now','localtime')` 口径并统一表头。
- **安全响应头**：`X-Content-Type-Options`、`X-Frame-Options`、HSTS、CSP 需要由 Nginx 添加（PHP 内置服务器没有）；`DEPLOY.md` 已给模板，上线时落进站点配置。

## 6. 复现方式

```bash
cd apps/proxy-atlas
php bin/build.php && php bin/health.php
php bin/probe_parity.php                     # 跨语言签名一致性（5 项）
php bin/probe_token.php issue --label="本机"  # 发 token
# 站点整页抓取：sitemap URL → 127.0.0.1:8080，检查状态/标题/内链/资源
# 转化链路：curl -D - 'http://127.0.0.1:8080/go/<slug>?subid=test&campaign=tg'
cd probes && .venv/bin/aixianlu-probe run -c config.yaml --upload
```

彩排过程中写入的测试数据（2 条代理申请、1 条 `subid=launchtest` 点击）已清理；`/news/` 因彩排采集新增 106 条真实新闻并重建（新闻健康告警已消除）。
