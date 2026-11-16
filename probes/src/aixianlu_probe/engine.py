"""
Core probing engine.

Kept deliberately pure and side-effect free where possible so that
a future Go implementation can mirror the same logic closely.

Measurement rules (PROTOCOL.md):
- ``latency_ms`` is the time from request start to response headers (TTFB),
  including any redirects; the body is read afterwards only for its size.
- ``reachable`` is true whenever an HTTP response arrived, even 401/403/5xx.
- ``success`` is true when the status is in the target's ``expected_status``.
- ``error_type`` describes the transport failure; ``error_class`` describes
  the application-level meaning of an HTTP error status.
"""

from __future__ import annotations

import asyncio
import json
import os
import ssl
import time
from datetime import datetime, timezone
from typing import Any, Optional

import httpx

from aixianlu_probe.models import ProbeConfig, ProbeResult, Target

_RETRYABLE_ERRORS = ("timeout", "connect", "dns", "other")

_DNS_MARKERS = (
    "name or service not known",
    "getaddrinfo",
    "nodename nor servname",
    "temporary failure in name resolution",
    "no address associated with hostname",
)

_TLS_MARKERS = ("ssl", "tls", "certificate", "handshake")

_REGION_MARKERS = (
    "unsupported_country_region_territory",
    "country, region, or territory not supported",
    "user location is not supported",
    "region not supported",
    "not available in your region",
    "not supported in your country",
    "access denied",
)


def _classify_error(exc: Exception) -> tuple[str, str]:
    """Map exception → (error_type, message)."""
    msg = str(exc) or type(exc).__name__
    low = msg.lower()
    if isinstance(exc, httpx.TimeoutException):
        return "timeout", msg
    if isinstance(exc, ssl.SSLError):
        return "tls", msg
    if isinstance(exc, httpx.ConnectError):
        cause = exc.__cause__
        if isinstance(cause, ssl.SSLError) or any(marker in low for marker in _TLS_MARKERS):
            return "tls", msg
        if any(marker in low for marker in _DNS_MARKERS):
            return "dns", msg
        return "connect", msg
    if isinstance(exc, httpx.ProxyError):
        return "connect", msg
    if isinstance(exc, httpx.TransportError):
        return "other", msg
    return "other", msg


def _extract_error_code(body: str) -> Optional[str]:
    """Pull a short machine-readable code out of an API error body."""
    try:
        data = json.loads(body)
    except (ValueError, TypeError):
        return None
    if not isinstance(data, dict):
        return None
    error = data.get("error")
    candidates: list[Any] = []
    if isinstance(error, dict):
        candidates.extend([error.get("code"), error.get("type"), error.get("status")])
    elif isinstance(error, str):
        candidates.append(error)
    candidates.extend([data.get("code"), data.get("type"), data.get("status")])
    for candidate in candidates:
        if isinstance(candidate, str) and candidate.strip():
            return candidate.strip()[:80]
    return None


def _classify_http(status: int, body: str) -> tuple[Optional[str], Optional[str]]:
    """Map an HTTP error status → (error_class, detail code)."""
    detail = _extract_error_code(body)
    low = (body or "").lower()
    if any(marker in low for marker in _REGION_MARKERS):
        return "region_block", detail
    if status == 429:
        return "rate_limit", detail
    if status == 401:
        return "auth_required", detail
    if status == 403:
        return "forbidden", detail
    if status == 404:
        return "not_found", detail
    if status in (400, 422):
        return "bad_request", detail
    if 500 <= status <= 599:
        return "server_error", detail
    if status >= 400:
        return "http_error", detail
    return None, detail


def _tls_version(resp: httpx.Response) -> Optional[str]:
    try:
        stream = resp.extensions.get("network_stream")
        if stream is None:
            return None
        ssl_object = stream.get_extra_info("ssl_object")
        version = getattr(ssl_object, "version", None)
        return version() if callable(version) else None
    except Exception:
        return None


def _redact(text: str, secret: Optional[str]) -> str:
    if secret and secret in text:
        return text.replace(secret, "***")
    return text


def _request_parts(target: Target) -> tuple[dict[str, str], Optional[bytes], Optional[str]]:
    headers = dict(target.headers or {})
    secret: Optional[str] = None
    if target.auth_env:
        secret = (os.environ.get(target.auth_env) or "").strip() or None
        if secret:
            value = f"{target.auth_scheme} {secret}".strip() if target.auth_scheme else secret
            headers[target.auth_header] = value

    content: Optional[bytes] = None
    if target.method.upper() in ("POST", "PUT", "PATCH"):
        payload = target.body if target.body is not None else {}
        content = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers.setdefault("Content-Type", "application/json")
    return headers, content, secret


async def probe_one(
    client: httpx.AsyncClient,
    target: Target,
    default_timeout: float,
    retries: int = 0,
    backoff_seconds: float = 0.5,
) -> ProbeResult:
    timeout = target.timeout_seconds or default_timeout
    max_attempts = max(1, (target.retries if target.retries is not None else retries) + 1)
    headers, content, secret = _request_parts(target)
    method = target.method.upper()

    checked_at = datetime.now(timezone.utc).astimezone()
    attempts = 0
    failure: Optional[tuple[str, str, float]] = None

    for attempt in range(max_attempts):
        attempts = attempt + 1
        started = time.perf_counter()
        try:
            request = client.build_request(
                method=method,
                url=target.url,
                headers=headers or None,
                content=content,
                timeout=timeout,
            )
            resp = await client.send(request, stream=True, follow_redirects=True)
            latency_ms = (time.perf_counter() - started) * 1000.0
            try:
                body = await resp.aread()
            finally:
                await resp.aclose()

            status = resp.status_code
            success = status in target.expected_status
            body_text = body.decode("utf-8", "replace")[:4000] if status >= 400 else ""
            error_class, detail = _classify_http(status, body_text)
            message = None
            if not success:
                message = f"status {status}" + (f": {detail}" if detail else "")

            return ProbeResult(
                name=target.name,
                url=target.url,
                category=target.category,
                method=method,
                success=success,
                reachable=True,
                status_code=status,
                latency_ms=round(latency_ms, 1),
                error_type=None if success else "http",
                error_class=error_class,
                error_message=_redact(message, secret) if message else None,
                tls_version=_tls_version(resp),
                http_version=resp.http_version,
                redirect_count=len(resp.history),
                response_size=len(body),
                attempts=attempts,
                model=target.model,
                checked_at=checked_at,
            )
        except Exception as exc:
            elapsed_ms = (time.perf_counter() - started) * 1000.0
            error_type, error_message = _classify_error(exc)
            failure = (error_type, _redact(error_message, secret)[:300], elapsed_ms)
            if attempt + 1 < max_attempts and error_type in _RETRYABLE_ERRORS:
                await asyncio.sleep(backoff_seconds * (2**attempt))
                continue
            break

    error_type, error_message, elapsed_ms = failure or ("other", "unknown error", 0.0)
    return ProbeResult(
        name=target.name,
        url=target.url,
        category=target.category,
        method=method,
        success=False,
        reachable=False,
        status_code=None,
        latency_ms=round(elapsed_ms, 1),
        error_type=error_type,
        error_class=None,
        error_message=error_message,
        attempts=attempts,
        model=target.model,
        checked_at=checked_at,
    )


async def run_probes(
    config: ProbeConfig,
    transport: Optional[httpx.AsyncBaseTransport] = None,
) -> list[ProbeResult]:
    """Run all targets concurrently with a semaphore."""
    concurrency = max(1, config.concurrency)
    sem = asyncio.Semaphore(concurrency)
    limits = httpx.Limits(max_connections=concurrency + 2, max_keepalive_connections=4)

    async with httpx.AsyncClient(
        headers={"User-Agent": config.user_agent},
        limits=limits,
        http2=True,
        verify=True,
        transport=transport,
    ) as client:

        async def _wrapped(target: Target) -> ProbeResult:
            async with sem:
                return await probe_one(
                    client,
                    target,
                    config.timeout_seconds,
                    retries=config.retries,
                    backoff_seconds=config.retry_backoff_seconds,
                )

        results = await asyncio.gather(*[_wrapped(target) for target in config.targets])

    return list(results)
