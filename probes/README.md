# AIXianlu Open Source Probes

分布式众包测速探针（Phase 1 原型 → Phase 2 正式版）。

## 目标

- 轻量 CLI（Go 或 Python）
- 测试关键 AI 端点连通性（OpenAI、Claude、Cursor 相关等）
- 安全上报延迟、丢包、成功率、错误类型
- 防刷与设备指纹

## 当前状态

协议设计与原型实现中。详见仓库 Issues 与 `docs/DEVELOPMENT_PLAN.md`。

## 快速开始（待完善）

```bash
# 示例（未来）
go run . --config config.yaml
```

## 协议

见 [PROTOCOL.md](./PROTOCOL.md)（即将添加）。
