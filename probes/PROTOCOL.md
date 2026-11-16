# AIXianlu Probe Protocol

> Version: 0.1.0  
> Last updated: 2026-11-16  
> Design goal: language-agnostic, easy to implement in Python **and** Go

## 1. Overview

The probe measures connectivity from a client network to a list of AI-related endpoints and optionally reports the results to a central collector.

All data exchanged over the wire is JSON. Field names use `snake_case`. Timestamps are RFC3339 with timezone.

## 2. Report Payload

`POST /api/v1/metrics/report`

```json
{
  "protocol_version": "0.1.0",
  "probe_id": "string (stable identifier)",
  "timestamp": "2026-11-16T15:30:00+08:00",
  "client": {
    "version": "0.1.0",
    "language": "python",
    "os": "linux"
  },
  "location": {
    "isp": "电信",
    "region": "广东",
    "asn": 4134,
    "country": "CN"
  },
  "results": [
    {
      "name": "openai-api",
      "url": "https://api.openai.com/v1/models",
      "category": "ai",
      "method": "GET",
      "success": true,
      "reachable": true,
      "status_code": 401,
      "latency_ms": 186.4,
      "error_type": null,
      "error_message": null,
      "tls_version": "TLSv1.3",
      "http_version": "HTTP/2",
      "redirect_count": 0,
      "response_size": 1234,
      "checked_at": "2026-11-16T15:30:01+08:00"
    }
  ],
  "signature": "optional hmac-sha256 hex"
}
```

### Field semantics

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | Request completed without transport error **and** status is in expected list |
| `reachable` | bool | TCP/TLS/HTTP layer reached the server (even if 401/403/5xx) |
| `error_type` | string\|null | `timeout`, `dns`, `tls`, `connect`, `http`, `other` |
| `latency_ms` | float | Total time from request start to response headers (or error) |

### Expected status codes

For AI APIs, `401` / `403` usually means the endpoint is **reachable** but authentication failed. The probe treats these as `reachable=true` when configured in `expected_status`.

## 3. Security (future)

- HMAC-SHA256 over the canonical JSON body (excluding `signature` itself)
- Key derived from a probe token issued by the platform
- Timestamp freshness window (±5 minutes)
- Rate limiting per `probe_id`

## 4. Compatibility Rules for Go Port

- Keep all public structs JSON-serializable with identical field names
- Prefer primitive types (`string`, `float64`, `bool`, `int`) over language-specific ones
- No required callbacks or complex inheritance — pure data + functions
- Error types are closed string enums

## 5. Versioning

Breaking changes increment the major version of `protocol_version`.  
Probes should send the version they implement; collectors must accept at least the previous minor version.
