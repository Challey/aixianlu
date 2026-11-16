# AIXianlu Probe

面向中国大陆网络环境的 **AI 服务连通性开源探针**。

测量从你的网络到 OpenAI、Claude、Cursor、Gemini 等关键端点的真实延迟、成功率和错误类型，并支持上报到 AIXianlu 数据平台。

> 设计原则：协议优先、模型清晰，方便未来用 Go 重写核心引擎而保持数据兼容。

## 快速开始

### 1. 安装（推荐可编辑模式）

```bash
cd probes
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -e .
```

### 2. 运行一次探测

```bash
# 使用默认目标，漂亮输出
aixianlu-probe run

# 指定配置文件
aixianlu-probe run -c config.yaml

# 只输出 JSON（方便脚本处理）
aixianlu-probe run --json

# 保存报告到文件
aixianlu-probe run --output report.json

# 同时尝试上报（需要配置 endpoint 与 token）
aixianlu-probe run --upload
```

### 3. 查看默认目标

```bash
aixianlu-probe targets
```

### 4. 生成示例配置

```bash
aixianlu-probe init-config
```

## 默认探测目标

| 名称 | 说明 |
|------|------|
| `openai-api` | OpenAI API (`api.openai.com`) |
| `openai-chat` | Chat Completions 端点可达性 |
| `claude-api` | Anthropic API |
| `claude-web` | claude.ai 主站 |
| `gemini-api` | Google Gemini API |
| `cursor-api` | Cursor 相关后端可达性 |
| `github-api` | GitHub API（对照） |
| `cloudflare` | 1.1.1.1 对照 |

目标列表可在配置文件中自由增删。

## 配置文件示例

```yaml
probe_id: ""                    # 留空则自动生成稳定指纹
timeout_seconds: 12
concurrency: 6

# 本地信息（可选，帮助分析）
location:
  isp: ""                       # 电信 / 联通 / 移动 / 其他
  region: ""                    # 省份或城市
  asn: null

# 上报（可选）
report:
  enabled: false
  endpoint: "https://aixianlu.com/api/v1/metrics/report"
  token: ""                     # 后续发放的上报 token
  timeout_seconds: 10

# 自定义目标（覆盖或追加默认）
targets:
  - name: openai-api
    url: "https://api.openai.com/v1/models"
    method: GET
    headers: {}
    expected_status: [200, 401, 403]   # 401/403 也算「可达」
    category: ai
```

## 设计说明（面向未来 Go 移植）

- **Models**（`models.py`）：纯数据结构 + Pydantic，字段命名与类型刻意保持简单，方便直接映射到 Go struct + JSON tag。
- **Engine**（`engine.py`）：探测逻辑与 IO 分离，核心函数接受明确参数，无全局状态。
- **Protocol**：所有上报载荷严格遵循 `PROTOCOL.md`，保证 Python / Go 探针可互换。
- **CLI**：薄层，只负责参数解析与展示。

后续用 Go 重写时，优先移植 `models` + `engine` + 协议层，CLI 可用 `cobra` 重写。

## 开发

```bash
pip install -e ".[dev]"
ruff check src tests
pytest
```

## 路线图

- [x] v0.1.0  可运行探测 + 本地报告 + 基础 CLI
- [ ] v0.2.0  完善错误分类、重试策略、更细的模型级端点
- [ ] v0.3.0  正式上报通道 + 签名校验
- [ ] v1.0.0  稳定协议 + 文档完善 + 可选 Go 版本并行

## License

MIT
