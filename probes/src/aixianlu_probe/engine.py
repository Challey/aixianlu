"""
Core probing engine.

Kept deliberately pure and side-effect free where possible so that
a future Go implementation can mirror the same logic closely.
"""

from __future__ import annotations

import asyncio
import time
from datetime import datetime, timezone
from typing import Optional

import httpx

from aixianlu_probe.models import ProbeConfig, ProbeResult, Target


def _classify_error(exc: Exception) -> tuple[str, str]:
    """Map exception → (error_type, message)."""
    msg = str(exc) or type(exc).__name__
    if isinstance(exc, httpx.TimeoutException):
        return "timeout", msg
    if isinstance(exc, httpx.ConnectError):
        # Could be DNS or TCP
        low = msg.lower()
        if "name or service not known" in low or "getaddrinfo" in low or "nodename" in low:
            return "dns", msg
        return "connect", msg
    if isinstance(exc, httpx.ConnectTimeout):
        return "timeout", msg
    if isinstance(exc, (httpx.ReadTimeout, httpx.WriteTimeout, httpx.PoolTimeout)):
        return "timeout", msg
    if isinstance(exc, httpx.ProxyError):
        return "connect", msg
    if isinstance(exc, httpx.TransportError):
        return "other", msg
    return "other", msg


async def probe_one(
    client: httpx.AsyncClient,
    target: Target,
    default_timeout: float,
) -> ProbeResult:
    timeout = target.timeout_seconds or default_timeout
    started = time.perf_counter()
    checked_at = datetime.now(timezone.utc).astimezone()

    try:
        # For POST targets we send a minimal empty body so servers that require
        # Content-Type still respond (usually with 401/400).
        kwargs: dict = {
            "method": target.method.upper(),
            "url": target.url,
            "headers": target.headers or None,
            "timeout": timeout,
            "follow_redirects": True,
        }
        if target.method.upper() == "POST":
            kwargs["content"] = b"{}"

        resp = await client.request(**kwargs)
        elapsed_ms = (time.perf_counter() - started) * 1000.0

        status = resp.status_code
        reachable = True
        success = status in target.expected_status

        tls_version = None
        http_version = None
        try:
            # httpx exposes these on the stream / connection when available
            if resp.http_version:
                http_version = resp.http_version
        except Exception:
            pass

        return ProbeResult(
            name=target.name,
            url=str(resp.url),
            category=target.category,
            method=target.method.upper(),
            success=success,
            reachable=reachable,
            status_code=status,
            latency_ms=round(elapsed_ms, 1),
            error_type=None if success else "http",
            error_message=None if success else f"status {status}",
            tls_version=tls_version,
            http_version=http_version,
            redirect_count=len(resp.history),
            response_size=len(resp.content) if resp.content else 0,
            checked_at=checked_at,
        )

    except Exception as exc:
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        err_type, err_msg = _classify_error(exc)
        return ProbeResult(
            name=target.name,
            url=target.url,
            category=target.category,
            method=target.method.upper(),
            success=False,
            reachable=False,
            status_code=None,
            latency_ms=round(elapsed_ms, 1),
            error_type=err_type,
            error_message=err_msg[:300],
            checked_at=checked_at,
        )


async def run_probes(config: ProbeConfig) -> list[ProbeResult]:
    """Run all targets concurrently with a semaphore."""
    sem = asyncio.Semaphore(config.concurrency)
    results: list[ProbeResult] = []

    limits = httpx.Limits(max_connections=config.concurrency + 2, max_keepalive_connections=4)
    headers = {"User-Agent": config.user_agent}

    async with httpx.AsyncClient(
        headers=headers,
        limits=limits,
        http2=True,
        verify=True,
    ) as client:

        async def _wrapped(t: Target) -> ProbeResult:
            async with sem:
                return await probe_one(client, t, config.timeout_seconds)

        tasks = [_wrapped(t) for t in config.targets]
        results = await asyncio.gather(*tasks)

    return list(results)
