"""Local saving and remote reporting."""

from __future__ import annotations

import json
import platform
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

import httpx

from aixianlu_probe import __protocol_version__, __version__
from aixianlu_probe.models import ClientInfo, ProbeConfig, ProbeResult, ReportPayload


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
        signature=None,  # future HMAC
    )


def save_report(payload: ReportPayload, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        json.dump(payload.to_report_dict(), f, ensure_ascii=False, indent=2)


async def upload_report(config: ProbeConfig, payload: ReportPayload) -> tuple[bool, str]:
    """Attempt to upload. Returns (ok, message)."""
    if not config.report_enabled and not config.report_token:
        return False, "reporting disabled (no token / not enabled)"

    headers = {
        "Content-Type": "application/json",
        "User-Agent": config.user_agent,
    }
    if config.report_token:
        headers["Authorization"] = f"Bearer {config.report_token}"

    try:
        async with httpx.AsyncClient(timeout=config.report_timeout_seconds) as client:
            resp = await client.post(
                config.report_endpoint,
                json=payload.to_report_dict(),
                headers=headers,
            )
            if 200 <= resp.status_code < 300:
                return True, f"uploaded ({resp.status_code})"
            return False, f"server returned {resp.status_code}: {resp.text[:200]}"
    except Exception as exc:
        return False, f"upload failed: {exc}"
