# dx_aixianlu Drupal Custom Module

Drupal 10/11 自定义模块（Phase 2）。

## 规划实体

- **Merchant**：商家/机场资料（对应现有 providers）
- **MetricSnapshot**：测速指标快照（延迟、成功率、丢包、模型级连通性）
- **Review / Note**：人工评测与风险标注

## 功能

- 版本化 REST / JSON:API
- 与众包探针上报对接
- Redis 缓存 + 异步写入支持
- 数据迁移路径（从 proxy-atlas SQLite）

## 当前状态

模块骨架待创建。详见 `docs/DEVELOPMENT_PLAN.md` 与架构文档。
