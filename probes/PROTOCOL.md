# 探针上报协议草案 (Draft)

> 版本：0.1  |  2026-11-16

## 上报端点（规划）

`POST /api/v1/metrics/report`（未来 Drupal 或临时 PHP 入口）

## 请求体示例

```json
{
  "probe_id": "uuid-or-fingerprint",
  "timestamp": "2026-11-16T15:00:00+08:00",
  "location": {
    "isp": "电信",
    "region": "广东",
    "asn": 4134
  },
  "targets": [
    {
      "name": "openai-api",
      "url": "https://api.openai.com/v1/models",
      "latency_ms": 186,
      "success": true,
      "status_code": 200,
      "error_type": null,
      "packet_loss": 0.0
    },
    {
      "name": "claude",
      "url": "https://api.anthropic.com/...",
      "latency_ms": 0,
      "success": false,
      "status_code": 0,
      "error_type": "timeout",
      "packet_loss": 1.0
    }
  ],
  "signature": "hmac-sha256-of-payload"
}
```

## 安全要求

- 时间窗口 token 或 HMAC 签名
- 设备指纹 / probe_id 限流
- 拒绝异常高频或异常结果

## 后续

- 模型级端点细化
- 贡献积分机制
- 与 MetricSnapshot 实体打通
