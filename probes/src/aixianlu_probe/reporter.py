"""Local saving, signing and remote reporting."""

from __future__ import annotations

import json
import os
import platform
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional

import httpx

from aixianlu_probe import __protocol_version__, __version__
from aixianlu_probe.models import ClientInfo, ProbeConfig, ProbeResult, ReportPayload
from aixianlu_probe.signing import sign_payload


def build_payload(config: ProbeConfig, results: list[ProbeResult]) -> ReportPayload:
    return ReportPayload(
        protocol_version=__protocol_version__,
        probe_id=config.probe_id,
        timestamp=datetime.now(timezone.utc).astimezone(),
        client=ClientInfo(
            version=__version__,
            language="python",
            os=f"{platform.system()} {platform.release()}".strip(),
        ),
        location=config.location,
        results=results,
        signature=None,
    )


def sign_report(config: ProbeConfig, payload: ReportPayload) -> ReportPayload:
    """Attach an HMAC signature when a report token is configured."""
    if config.report_token:
        payload.signature = sign_payload(payload.to_report_dict(), config.report_token)
    return payload


def save_report(payload: ReportPayload, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        json.dump(payload.to_report_dict(), f, ensure_ascii=False, indent=2)


def default_state_path() -> Path:
    base = os.environ.get("XDG_CACHE_HOME") or str(Path.home() / ".cache")
    return Path(base) / "aixianlu-probe" / "state.json"


def _state_path(config: ProbeConfig) -> Path:
    return Path(config.report_state_path).expanduser() if config.report_state_path else default_state_path()


def _read_state(path: Path) -> dict[str, Any]:
    try:
        with path.open("r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def _write_state(path: Path, data: dict[str, Any]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False)
        tmp.replace(path)
    except OSError:
        pass


def cooldown_remaining(config: ProbeConfig) -> float:
    """Seconds left before the next upload is allowed (client-side rate limit)."""
    interval = config.report_min_interval_seconds
    if interval <= 0:
        return 0.0
    state = _read_state(_state_path(config))
    last_upload = state.get("last_upload", {})
    if not isinstance(last_upload, dict):
        return 0.0
    try:
        last = float(last_upload.get(config.report_endpoint, 0))
    except (TypeError, ValueError):
        return 0.0
    return max(0.0, interval - (time.time() - last))


async def upload_report(
    config: ProbeConfig,
    payload: ReportPayload,
    transport: Optional[httpx.AsyncBaseTransport] = None,
) -> tuple[bool, str]:
    """Attempt to upload. Returns (ok, message)."""
    if not config.report_enabled and not config.report_token:
        return False, "reporting disabled (no token / not enabled)"

    remaining = cooldown_remaining(config)
    if remaining > 0:
        return False, f"skipped: upload cooldown, {remaining:.0f}s remaining"

    sign_report(config, payload)

    headers = {
        "Content-Type": "application/json",
        "User-Agent": config.user_agent,
    }
    if config.report_token:
        headers["Authorization"] = f"Bearer {config.report_token}"

    try:
        async with httpx.AsyncClient(
            timeout=config.report_timeout_seconds, transport=transport
        ) as client:
            resp = await client.post(
                config.report_endpoint,
                json=payload.to_report_dict(),
                headers=headers,
            )
            if 200 <= resp.status_code < 300:
                state = _read_state(_state_path(config))
                last_upload = state.get("last_upload")
                if not isinstance(last_upload, dict):
                    last_upload = {}
                last_upload[config.report_endpoint] = time.time()
                state["last_upload"] = last_upload
                _write_state(_state_path(config), state)
                return True, f"uploaded ({resp.status_code})"
            if resp.status_code == 429:
                retry_after = resp.headers.get("Retry-After", "?")
                return False, f"rate limited by server (retry after {retry_after}s)"
            return False, f"server returned {resp.status_code}: {resp.text[:200]}"
    except Exception as exc:
        return False, f"upload failed: {exc}"
