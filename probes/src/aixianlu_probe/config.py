"""Configuration loading."""

from __future__ import annotations

import hashlib
import platform
import uuid
from pathlib import Path
from typing import Any, Optional

import yaml
from pydantic import ValidationError

from aixianlu_probe.models import Location, ProbeConfig, Target
from aixianlu_probe.targets import DEFAULT_TARGETS


def _stable_probe_id() -> str:
    """Generate a reasonably stable ID from machine characteristics."""
    raw = f"{platform.node()}|{platform.system()}|{platform.machine()}"
    digest = hashlib.sha256(raw.encode()).hexdigest()[:16]
    return f"probe-{digest}"


def load_config(path: Optional[Path] = None) -> ProbeConfig:
    data: dict[str, Any] = {}

    if path and path.exists():
        with path.open("r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}

    # Flatten nested report section for the model
    report = data.pop("report", {}) or {}
    location_data = data.pop("location", {}) or {}

    cfg = ProbeConfig(
        probe_id=data.get("probe_id") or "",
        timeout_seconds=float(data.get("timeout_seconds", 12)),
        concurrency=int(data.get("concurrency", 6)),
        user_agent=data.get("user_agent", "aixianlu-probe/0.1.0"),
        location=Location(**location_data),
        report_enabled=bool(report.get("enabled", False)),
        report_endpoint=report.get("endpoint", "https://aixianlu.com/api/v1/metrics/report"),
        report_token=report.get("token", ""),
        report_timeout_seconds=float(report.get("timeout_seconds", 10)),
    )

    if not cfg.probe_id:
        cfg.probe_id = _stable_probe_id()

    # Targets: use config if provided, otherwise defaults
    raw_targets = data.get("targets")
    if raw_targets:
        try:
            cfg.targets = [Target(**t) for t in raw_targets]
        except (ValidationError, TypeError) as e:
            raise SystemExit(f"Invalid targets in config: {e}") from e
    else:
        cfg.targets = list(DEFAULT_TARGETS)

    return cfg


def write_example_config(path: Path) -> None:
    example = Path(__file__).resolve().parents[2] / "config.example.yaml"
    if example.exists():
        path.write_text(example.read_text(encoding="utf-8"), encoding="utf-8")
    else:
        # Fallback minimal
        path.write_text(
            "probe_id: \"\"\ntimeout_seconds: 12\nconcurrency: 6\n",
            encoding="utf-8",
        )
