# AIXianlu Probe

面向中国大陆网络环境的 **AI 服务连通性开源探针**。

测量从你的网络到 OpenAI、Claude、Gemini、Cursor 等关键端点的真实 TTFB、可达率、可用率与错误类型，并可选上报到 AIXianlu 数据平台。

> 设计原则：协议优先、模型清晰，方便未来用 Go 重写核心引擎而保持数据兼容。协议细节见 [PROTOCOL.md](PROTOCOL.md)。

## 快速开始

### 1. 安装

需要 Python ≥ 3.10。推荐使用可编辑模式（或 `uv`）：

```bash
cd probes
python -m venv .venv && source .venv/bin/activate     # Windows: .venv\Scripts\activate
pip install -e ".[dev]"
```

没有系统新版 Python 时可用 `uv`（不影响系统）：

```bash
uv python install 3.12
uv venv --python 3.12 .venv
VIRTUAL_ENV=$PWD/.venv uv pip install -e ".[dev]"
```

### 2. 运行一次探测

```bash
aixianlu-probe run                      # 默认目标，彩色表格
aixianlu-probe run -c config.yaml       # 指定配置
aixianlu-probe run --json               # 仅输出 JSON，便于脚本处理
aixianlu-probe run -o report.json       # 保存完整报告
aixianlu-probe run --upload             # 同时上报（需配置 endpoint + token）

# 运营商标注：手动值优先于自动识别
aixianlu-probe run --isp 电信 --region 广东
aixianlu-probe run --auto-detect-location
```

表格列含义：`Reach` 是否有 HTTP 响应、`OK` 状态码是否落在预期范围、`TTFB` 请求到响应头的时间、`Error` 为 `error_type/error_class (xN)`。

### 3. 其它命令

```bash
aixianlu-probe targets        # 查看内置目标与模型
aixianlu-probe init-config    # 生成 config.yaml 模板
aixianlu-probe id             # 查看本机稳定探针 ID
aixianlu-probe version        # 客户端与协议版本
```

## 默认探测目标

| 名称 | 方法 | 说明 |
|------|------|------|
| `openai-api` | GET | `api.openai.com/v1/models` |
| `openai-chat` | POST | Chat Completions（`gpt-4o-mini`），配置 `OPENAI_API_KEY` 后为真实模型级检测 |
| `openai-embeddings` | POST | Embeddings（`text-embedding-3-small`） |
| `claude-api` | POST | Messages（`claude-sonnet-4-5`），配置 `ANTHROPIC_API_KEY` |
| `claude-web` | GET | claude.ai 主站 |
| `gemini-api` | GET | Generative Language API 模型列表 |
| `gemini-flash` | POST | `gemini-2.0-flash:generateContent`，配置 `GEMINI_API_KEY` |
| `cursor-api` | GET | `api2.cursor.sh` |
| `github-api` | GET | 对照（应当始终可达） |
| `cloudflare` | GET | `1.1.1.1` 对照 |

未配置密钥时，模型级目标只会返回 `401 auth_required` / `403 forbidden`：默认目标表把它们列入 `expected_status`，因此 `success` 的含义是「端点按无凭证时的正确方式应答」，**不代表任何账号可用**。配上 `auth_env` 对应的环境变量后，同一目标只接受 `200`，此时 `success` 才是真正的模型可用证据。平台数据始终区分 `reachable`（传输层）与 `success`（应答层），并用 `error_class` 单独标记区域拦截与限流。

密钥通过环境变量在运行时读取，不会写入配置文件、报告或上报载荷，错误信息中也会被脱敏。

## 配置

见 [config.example.yaml](config.example.yaml)（`config.yaml` 已被 git 忽略）。要点：

```yaml
retries: 1                    # 传输层错误才重试
retry_backoff_seconds: 0.5    # 指数退避基数

location:
  auto_detect: false          # 自动识别只保留 ISP/地区/ASN，不上传 IP

report:
  enabled: false
  endpoint: "https://aixianlu.com/api/v1/metrics/report"
  token: ""                   # 平台发放；同时用于 Bearer 鉴权与 HMAC 签名
  min_interval_seconds: 60    # 客户端侧冷却，服务端另有强制限速

extend_targets: true          # 保留内置目标，按 name 覆盖或追加
```

## 隐私

- 探针 ID = `sha256(machine-id + hostname + os + arch)` 的前 16 位，原始值永不上传。
- 自动识别运营商时，只保留 ISP / 地区 / ASN / 国家；**IP 地址不写入报告、不上报**。
- 报告不含响应正文，目标 URL 仅为你配置的端点。
- 上报可完全关闭（默认 `enabled: false`），本地 `--output` 不产生任何外发请求。

## 参与上报（平台侧）

平台部署（当前为 `apps/proxy-atlas`）后：

```bash
# 服务端发放 token（明文只显示一次，库内只存 SHA-256）
php apps/proxy-atlas/bin/probe_token.php issue --label="电信-广东-VPS-01"
php apps/proxy-atlas/bin/probe_token.php list
php apps/proxy-atlas/bin/probe_token.php revoke probe-xxxxxxxx

# 聚合小时快照（建议 cron 每小时）+ 清理原始明细
php apps/proxy-atlas/bin/probe_snapshot.php --hours=48 --prune-days=30
```

后台「探针数据」页可发 token、看快照与最近上报；聚合结果公开在前台 `/status/`（实时状态页，可按窗口 / 运营商 / 端点筛选），只读接口：

```
GET /api/v1/metrics/latest?hours=24&isp=电信&name=openai-chat
```

上报会被签名校验、时间窗校验、按探针限速与去重；未通过时返回 `403 / 422 / 429 / 409`，语义见 PROTOCOL.md §4。

## 开发与测试

```bash
ruff check src tests
pytest -q                     # 单元与协议测试
php apps/proxy-atlas/bin/probe_parity.php   # Python/PHP 签名跨语言一致性
```

## 路线图

- [x] v0.1.0  可运行探测 + 本地报告 + 基础 CLI
- [x] v0.2.0  错误分类（传输层 + 应用层）、重试、模型级端点、TTFB 计时、TLS 版本、自动运营商识别、HMAC 签名上报、服务端接收与快照聚合
- [ ] v0.3.0  守护进程/定时模式、更多端点（Azure OpenAI、DeepSeek、xAI）、贡献积分与激励、签名挑战值防重放升级
- [ ] v1.0.0  稳定协议 + 文档完善 + 可选 Go 版本并行

## License

MIT
