"""Engine tests using httpx.MockTransport (no real network)."""

from __future__ import annotations

import json
import ssl

import httpx
import pytest

from aixianlu_probe.engine import run_probes
from aixianlu_probe.models import ProbeConfig, Target


def config_for(target: Target, **kwargs) -> ProbeConfig:
    defaults = {"retries": 0, "retry_backoff_seconds": 0.0, "concurrency": 2}
    defaults.update(kwargs)
    return ProbeConfig(targets=[target], **defaults)


async def run_once(target: Target, handler, **kwargs):
    cfg = config_for(target, **kwargs)
    results = await run_probes(cfg, transport=httpx.MockTransport(handler))
    return results[0]


async def test_success_records_latency_and_size() -> None:
    result = await run_once(
        Target(name="ok", url="https://example.com/health"),
        lambda request: httpx.Response(200, content=b"ok"),
    )
    assert result.success and result.reachable
    assert result.status_code == 200
    assert result.latency_ms is not None and result.latency_ms >= 0
    assert result.response_size == 2
    assert result.attempts == 1
    assert result.error_type is None and result.error_class is None


async def test_expected_401_is_success_but_auth_required() -> None:
    result = await run_once(
        Target(name="api", url="https://example.com/v1/models", expected_status=[200, 401]),
        lambda request: httpx.Response(401, json={"error": {"code": "invalid_api_key"}}),
    )
    assert result.reachable and result.success
    assert result.error_class == "auth_required"
    assert result.error_type is None


async def test_region_block_is_classified() -> None:
    body = {"error": {"code": "unsupported_country_region_territory", "message": "blocked"}}
    result = await run_once(
        Target(name="api", url="https://example.com/", expected_status=[200, 403]),
        lambda request: httpx.Response(403, json=body),
    )
    assert result.reachable
    assert result.error_class == "region_block"


async def test_rate_limit_is_classified() -> None:
    result = await run_once(
        Target(name="api", url="https://example.com/", expected_status=[200]),
        lambda request: httpx.Response(429, json={"error": {"type": "rate_limit_exceeded"}}),
    )
    assert result.reachable and not result.success
    assert result.error_type == "http"
    assert result.error_class == "rate_limit"
    assert "rate_limit_exceeded" in (result.error_message or "")


async def test_server_error_is_classified() -> None:
    result = await run_once(
        Target(name="api", url="https://example.com/"),
        lambda request: httpx.Response(503, text="unavailable"),
    )
    assert result.reachable and not result.success
    assert result.error_class == "server_error"


async def test_http_errors_are_not_retried() -> None:
    result = await run_once(
        Target(name="api", url="https://example.com/"),
        lambda request: httpx.Response(500),
        retries=2,
    )
    assert result.attempts == 1


async def test_timeout_is_retried() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("timed out")

    result = await run_once(
        Target(name="api", url="https://example.com/"), handler, retries=1
    )
    assert not result.reachable and not result.success
    assert result.error_type == "timeout"
    assert result.attempts == 2


async def test_dns_error_is_classified() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("[Errno -2] Name or service not known")

    result = await run_once(Target(name="api", url="https://example.invalid/"), handler)
    assert result.error_type == "dns"


async def test_tls_error_is_classified_from_message() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("SSL: CERTIFICATE_VERIFY_FAILED")

    result = await run_once(Target(name="api", url="https://example.com/"), handler)
    assert result.error_type == "tls"


async def test_tls_error_is_classified_from_cause() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        try:
            raise ssl.SSLError("handshake failure")
        except ssl.SSLError as exc:
            raise httpx.ConnectError("connection failed") from exc

    result = await run_once(Target(name="api", url="https://example.com/"), handler)
    assert result.error_type == "tls"


async def test_connect_error_is_classified() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("Connection refused")

    result = await run_once(Target(name="api", url="https://example.com/"), handler)
    assert result.error_type == "connect"


async def test_model_and_body_are_sent_and_reported() -> None:
    seen: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["body"] = json.loads(request.content.decode("utf-8"))
        seen["method"] = request.method
        return httpx.Response(401, json={"error": {"code": "invalid_api_key"}})

    target = Target(
        name="openai-chat",
        url="https://example.com/v1/chat/completions",
        method="POST",
        model="gpt-4o-mini",
        body={"model": "gpt-4o-mini", "messages": [{"role": "user", "content": "ping"}]},
        expected_status=[200, 401],
    )
    result = await run_once(target, handler)
    assert seen["method"] == "POST"
    assert seen["body"]["model"] == "gpt-4o-mini"
    assert result.model == "gpt-4o-mini"


async def test_auth_env_header_and_redaction(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AIXIANLU_TEST_KEY", "sk-super-secret")
    seen: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["auth"] = request.headers.get("Authorization")
        raise httpx.ConnectError("upstream rejected sk-super-secret")

    target = Target(
        name="api",
        url="https://example.com/",
        auth_env="AIXIANLU_TEST_KEY",
    )
    result = await run_once(target, handler)
    assert seen["auth"] == "Bearer sk-super-secret"
    assert "sk-super-secret" not in (result.error_message or "")
    assert "***" in (result.error_message or "")


async def test_missing_auth_env_sends_no_header(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("AIXIANLU_TEST_KEY", raising=False)
    seen: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["auth"] = request.headers.get("Authorization")
        return httpx.Response(200)

    await run_once(
        Target(name="api", url="https://example.com/", auth_env="AIXIANLU_TEST_KEY"), handler
    )
    assert seen["auth"] is None


async def test_redirects_are_counted() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/":
            return httpx.Response(302, headers={"Location": "https://example.com/final"})
        return httpx.Response(200, content=b"done")

    result = await run_once(Target(name="web", url="https://example.com/"), handler)
    assert result.redirect_count == 1
    assert result.status_code == 200
