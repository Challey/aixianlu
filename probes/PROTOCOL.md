# AIXianlu Probe Protocol

> Version: 0.2.0
> Last updated: 2026-11-17
> Design goal: language-agnostic, easy to implement in Python **and** Go **and** verified against a second implementation (PHP receiver in `apps/proxy-atlas`)

Reference implementations:

| Role | Path |
| --- | --- |
| Client (Python) | `probes/src/aixianlu_probe/` |
| Receiver (PHP / current production MVP) | `apps/proxy-atlas/public/api/metrics-report.php`, `app/probe_repository.php` |
| Cross-language test vector | `probes/tests/fixtures/signing_vector.json` (checked by `php apps/proxy-atlas/bin/probe_parity.php`) |

## 1. Overview

The probe measures connectivity from a client network to a list of AI-related endpoints and optionally reports the results to a central collector.

All data exchanged over the wire is JSON. Field names use `snake_case`. Timestamps are RFC3339 with an explicit timezone offset; receivers normalise to UTC for storage.

## 2. Report Payload

`POST /api/v1/metrics/report`

```json
{
  "protocol_version": "0.2.0",
  "probe_id": "probe-0adcb035f3ba1a9e",
  "timestamp": "2026-11-16T15:30:00+08:00",
  "client": {
    "version": "0.2.0",
    "language": "python",
    "os": "Linux 6.6"
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
      "model": null,
      "success": false,
      "reachable": true,
      "status_code": 401,
      "latency_ms": 186.4,
      "error_type": null,
      "error_class": "auth_required",
      "error_message": null,
      "tls_version": "TLSv1.3",
      "http_version": "HTTP/2",
      "redirect_count": 0,
      "response_size": 1234,
      "attempts": 1,
      "checked_at": "2026-11-16T15:30:01+08:00"
    }
  ],
  "signature": "41e81b4547b0292e5aef5545d151a92ad055744899b2b889aae5661fe522d06d"
}
```

### Field semantics

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | HTTP status is in the target's `expected_status` list |
| `reachable` | bool | **Any** HTTP response arrived (even 401/403/404/5xx); false only for transport-level failures |
| `latency_ms` | float\|null | TTFB: request start → response headers received, redirects included. Measured even when the status code is an error. Null when nothing answered |
| `error_type` | string\|null | Transport layer: `timeout`, `dns`, `tls`, `connect`, `http`, `other` |
| `error_class` | string\|null | Application layer, closed enum: `region_block`, `rate_limit`, `auth_required`, `forbidden`, `not_found`, `bad_request`, `server_error`, `http_error` |
| `model` | string\|null | Model probed (e.g. `gpt-4o-mini`); the aggregation key together with `name` + `isp` |
| `attempts` | int | Total tries including retries (retries only happen on transport errors) |
| `tls_version` | string\|null | Negotiated TLS, e.g. `TLSv1.3` |
| `http_version` | string\|null | `HTTP/1.1`, `HTTP/2`, … |

`error_type` and `error_class` are mutually exclusive in practice: a transport failure sets `error_type`, a responded request sets `error_class`. `error_class` stays null when the response is in the expected range.

### Expected status codes

For AI APIs, `401` / `403` usually means the endpoint is **reachable** but authentication failed, so unauthenticated model targets treat them as reachable-but-not-successful. Receivers must not interpret `success=false` + `error_class=auth_required` as an outage — it only proves network reachability, not account usability.

## 3. Authentication and Signing

Required headers on the report request:

| Header | Value |
| --- | --- |
| `Authorization` | `Bearer <probe token>` (alternatively `X-Probe-Token: <token>`) |
| `Content-Type` | `application/json` |

The token is issued by the platform (`php bin/probe_token.php issue` or 后台 → 探针数据). Only its SHA-256 hash is stored server-side; the token is displayed once.

### Signature

```
key       = HMAC-SHA256(key = token, msg = "aixianlu-probe/v1/signing")   // raw 32 bytes
signature = hex( HMAC-SHA256(key = key, msg = canonical_bytes(payload)) )
```

`signature` is lowercase hex and is part of the payload, but is **excluded** from the bytes being signed.

### Canonical JSON (must be byte-identical across languages)

1. Remove the top-level `signature` member (even when its value is `null`).
2. Object keys sorted ascending by their UTF-8 byte string; arrays keep order.
3. Separators `,` and `:` with no whitespace anywhere.
4. Strings encoded as JSON strings with non-ASCII characters left unescaped (UTF-8, not `\uXXXX`).
5. Floats written with **exactly 6 decimal places** (`186.4` → `186.400000`, `0` given as float → `0.000000`). Non-finite floats are rejected.
6. Integers, booleans and null use their plain JSON spellings; `true`/`false` are never `1`/`0`.
7. An empty map and an empty list must stay distinguishable — implementations sign the parsed tree (`stdClass` in PHP, `dict`/`list` in Python), not a re-encoded copy, so that `{}` never becomes `[]`.

Test vector: `probes/tests/fixtures/signing_vector.json`. If you change the canonicaliser, run:

```bash
php apps/proxy-atlas/bin/probe_parity.php
cd probes && pytest tests/test_signing.py
```

## 4. Receiver Responsibilities

Receivers MUST:

- accept `POST` only (otherwise `405` with `Allow: POST`);
- enforce a body size limit (`413`, default 262144 bytes) and JSON content type (`415`);
- reject unknown/revoked tokens (`403`) and payloads whose `probe_id` does not belong to the token (`403`);
- verify the signature (`403` on mismatch); unsigned payloads are only accepted while `protocol_version` is 0.1.0/0.1.1 **and** the operator explicitly enables the compatibility switch;
- validate the payload shape and enum values (`422`);
- apply a freshness window on `timestamp` (±300 s by default, part of validation → `422`);
- rate limit per probe: minimum interval between reports (default 60 s) and an hourly cap (default 120), answered with `429` and `Retry-After`;
- deduplicate by `sha256(probe_id | timestamp | sha256(canonical_bytes))` → `409` on repeat;
- store the raw report plus per-result rows, then aggregate hourly snapshots keyed by `hour, name, model, isp`.

Responses:

```json
{"ok": true, "report_id": 123, "results": 10, "received_at": "2026-11-16 23:20:49"}
{"ok": false, "error": "invalid signature"}
```

Public read endpoint (no probe identifiers, no IP addresses):

```
GET /api/v1/metrics/latest?hours=24&isp=电信&name=openai-api
```

returns aggregated `samples`, `success_rate`, `reachable_rate`, `avg_latency_ms`, `worst_p95_latency_ms`, `region_block_count`, `rate_limit_count`, `hours_covered`, `last_hour` per `name` + `model` + `isp`.

The reference deployment renders this at `/status/` (static bake of the last 24 h plus an in-page refresh against the same endpoint). Any public rendering must keep `samples` and `hours_covered` visible next to the rates, and must not present `reachable` without `success` as "the service works".

### Limits summary (receiver defaults)

| Setting | Default | Meaning |
| --- | --- | --- |
| `PROBE_TIMESTAMP_SKEW_SECONDS` | 300 | accepted clock skew |
| `PROBE_MIN_INTERVAL_SECONDS` | 60 | minimum gap between reports of one probe |
| `PROBE_HOURLY_LIMIT` | 120 | maximum reports per probe per UTC hour |
| `PROBE_MAX_RESULTS` | 200 | results per report |
| `PROBE_MAX_BODY_BYTES` | 262144 | request body size |
| `PROBE_ALLOW_UNSIGNED` | 0 | set to 1 only during a 0.1.x migration window |

## 5. Privacy Rules

- Device identity is `probe-<16 hex>` derived from `sha256(machine-id + hostname + os + arch)` truncated; the raw values are never transmitted.
- Location auto-detection keeps only `isp`, `region`, `asn`, `country`. The client IP address is never stored in the report nor uploaded.
- Receivers store an irreversible IP hash (salted SHA-256) for abuse control only.
- API keys are read from the environment at run time, sent only to the target being probed, redacted from error messages and never written into reports.
- Reports carry no URL query strings beyond the configured target, and no response bodies.

## 6. Compatibility Rules for Go Port

- Keep all public structs JSON-serialisable with identical field names and `omitempty` off for signed fields (absent vs. null matters).
- Prefer primitive types (`string`, `float64`, `bool`, `int`) over language-specific ones; timestamps as RFC3339 strings.
- Error enums are closed string sets — a Go port must reject unknown values client-side rather than invent spellings.
- Serialise floats through the same 6-decimal rule; do not rely on `encoding/json` defaults for the signature bytes.
- Sign the decoded tree (e.g. `map[string]any` produced with `json.Decoder` + `UseNumber`, or a dedicated type) so key sorting is explicit.

## 7. Versioning

Breaking changes increment the major version of `protocol_version`. Additive fields (new optional keys, new enum members) bump the minor version and must remain ignorable by collectors.

The 0.1.0 → 0.2.0 step is additive: `error_class`, `model`, `attempts` were introduced, `latency_ms` semantics were clarified to TTFB, `success`/`reachable` definitions were separated, and signing moved from "future" to required. Receivers accept `protocol_version` in `{0.1.0, 0.1.1, 0.2.0}`; probes must send the version they implement.
