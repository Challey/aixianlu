"""Report building, signing, cooldown and upload tests."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

import httpx

from aixianlu_probe.models import Location, ProbeConfig, ProbeResult
from aixianlu_probe.reporter import (
    build_payload,
    cooldown_remaining,
    save_report,
    sign_report,
    upload_report,
)
from aixianlu_probe.signing import verify_signature


def result_fixture() -> ProbeResult:
    return ProbeResult(
        name="ok",
        url="https://example.com/",
        success=True,
        reachable=True,
        status_code=200,
        latency_ms=12.3,
        checked_at=datetime.now(timezone.utc).astimezone(),
    )


def config_for(tmp_path: Path, **kwargs) -> ProbeConfig:
    defaults = {
        "probe_id": "probe-test",
        "report_enabled": True,
        "report_endpoint": "https://collector.example.com/api/v1/metrics/report",
        "report_token": "token-abc",
        "report_min_interval_seconds": 0.0,
        "report_state_path": str(tmp_path / "state.json"),
        "location": Location(isp="电信", region="广东", asn=4134, country="CN"),
    }
    defaults.update(kwargs)
    return ProbeConfig(**defaults)


def test_build_payload_shape(tmp_path: Path) -> None:
    cfg = config_for(tmp_path)
    payload = build_payload(cfg, [result_fixture()])
    data = payload.to_report_dict()
    assert data["protocol_version"] == "0.2.0"
    assert data["probe_id"] == "probe-test"
    assert data["client"]["language"] == "python"
    assert data["location"]["isp"] == "电信"
    assert len(data["results"]) == 1
    assert data["signature"] is None


def test_sign_report_attaches_valid_signature(tmp_path: Path) -> None:
    cfg = config_for(tmp_path)
    payload = sign_report(cfg, build_payload(cfg, [result_fixture()]))
    assert payload.signature
    assert verify_signature(payload.to_report_dict(), cfg.report_token, payload.signature)


def test_sign_report_without_token_is_unsigned(tmp_path: Path) -> None:
    cfg = config_for(tmp_path, report_token="")
    payload = sign_report(cfg, build_payload(cfg, [result_fixture()]))
    assert payload.signature is None


def test_save_report_writes_json(tmp_path: Path) -> None:
    cfg = config_for(tmp_path)
    payload = sign_report(cfg, build_payload(cfg, [result_fixture()]))
    path = tmp_path / "reports" / "r.json"
    save_report(payload, path)
    data = json.loads(path.read_text("utf-8"))
    assert data["probe_id"] == "probe-test"
    assert data["signature"] == payload.signature


async def test_upload_success_records_cooldown(tmp_path: Path) -> None:
    cfg = config_for(tmp_path, report_min_interval_seconds=60.0)
    payload = build_payload(cfg, [result_fixture()])
    seen: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["auth"] = request.headers.get("Authorization")
        seen["body"] = json.loads(request.content.decode("utf-8"))
        return httpx.Response(200, json={"ok": True})

    ok, message = await upload_report(cfg, payload, transport=httpx.MockTransport(handler))
    assert ok, message
    assert seen["auth"] == "Bearer token-abc"
    assert verify_signature(seen["body"], cfg.report_token, seen["body"]["signature"])
    assert cooldown_remaining(cfg) > 0

    ok2, message2 = await upload_report(cfg, payload, transport=httpx.MockTransport(handler))
    assert not ok2
    assert "cooldown" in message2


async def test_upload_rate_limited_message(tmp_path: Path) -> None:
    cfg = config_for(tmp_path)
    payload = build_payload(cfg, [result_fixture()])

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(429, headers={"Retry-After": "30"}, json={"ok": False})

    ok, message = await upload_report(cfg, payload, transport=httpx.MockTransport(handler))
    assert not ok
    assert "429" in message or "rate limited" in message
    assert "30" in message


async def test_upload_rejected_signature(tmp_path: Path) -> None:
    cfg = config_for(tmp_path)
    payload = build_payload(cfg, [result_fixture()])

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(403, json={"ok": False, "error": "invalid signature"})

    ok, message = await upload_report(cfg, payload, transport=httpx.MockTransport(handler))
    assert not ok
    assert "403" in message


async def test_upload_disabled_without_token(tmp_path: Path) -> None:
    cfg = config_for(tmp_path, report_enabled=False, report_token="")
    payload = build_payload(cfg, [result_fixture()])
    ok, message = await upload_report(cfg, payload)
    assert not ok
    assert "disabled" in message
