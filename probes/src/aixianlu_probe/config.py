"""Configuration loading."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Optional

import yaml
from pydantic import ValidationError

from aixianlu_probe import __version__
from aixianlu_probe.identity import default_probe_id
from aixianlu_probe.models import Location, ProbeConfig, Target
from aixianlu_probe.targets import DEFAULT_TARGETS

DEFAULT_ENDPOINT = "https://aixianlu.com/api/v1/metrics/report"


def load_config(path: Optional[Path] = None) -> ProbeConfig:
    data: dict[str, Any] = {}

    if path and path.exists():
        with path.open("r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}
    if not isinstance(data, dict):
        raise SystemExit("Config root must be a mapping")

    # Flatten nested report / location sections for the model
    report = data.pop("report", {}) or {}
    location_data = dict(data.pop("location", {}) or {})
    auto_detect = bool(location_data.pop("auto_detect", False))

    try:
        cfg = ProbeConfig(
            probe_id=str(data.get("probe_id") or ""),
            timeout_seconds=float(data.get("timeout_seconds", 12)),
            concurrency=max(1, int(data.get("concurrency", 6))),
            retries=max(0, int(data.get("retries", 1))),
            retry_backoff_seconds=max(0.0, float(data.get("retry_backoff_seconds", 0.5))),
            user_agent=str(data.get("user_agent") or f"aixianlu-probe/{__version__}"),
            location=Location(**location_data),
            auto_detect_location=auto_detect,
            geo_timeout_seconds=float(data.get("geo_timeout_seconds", 5)),
            report_enabled=bool(report.get("enabled", False)),
            report_endpoint=str(report.get("endpoint") or DEFAULT_ENDPOINT),
            report_token=str(report.get("token") or ""),
            report_timeout_seconds=float(report.get("timeout_seconds", 10)),
            report_min_interval_seconds=float(report.get("min_interval_seconds", 60)),
            report_state_path=report.get("state_path") or None,
        )
    except (ValidationError, TypeError, ValueError) as exc:
        raise SystemExit(f"Invalid config: {exc}") from exc

    if not cfg.probe_id:
        cfg.probe_id = default_probe_id()

    # Targets: config replaces defaults, or extends them when extend_targets is set
    raw_targets = data.get("targets")
    if raw_targets:
        try:
            custom = [Target(**t) for t in raw_targets]
        except (ValidationError, TypeError) as exc:
            raise SystemExit(f"Invalid targets in config: {exc}") from exc
        if data.get("extend_targets"):
            names = {t.name for t in custom}
            cfg.targets = custom + [t for t in DEFAULT_TARGETS if t.name not in names]
        else:
            cfg.targets = custom
    else:
        cfg.targets = list(DEFAULT_TARGETS)

    return cfg


def write_example_config(path: Path) -> None:
    example = Path(__file__).resolve().parents[2] / "config.example.yaml"
    if example.exists():
        path.write_text(example.read_text(encoding="utf-8"), encoding="utf-8")
    else:
        path.write_text(
            "\n".join(
                [
                    'probe_id: ""',
                    "timeout_seconds: 12",
                    "concurrency: 6",
                    "retries: 1",
                    "retry_backoff_seconds: 0.5",
                    "",
                    "location:",
                    "  auto_detect: false",
                    '  isp: ""',
                    '  region: ""',
                    "  asn: null",
                    '  country: "CN"',
                    "",
                    "report:",
                    "  enabled: false",
                    f'  endpoint: "{DEFAULT_ENDPOINT}"',
                    '  token: ""',
                    "  timeout_seconds: 10",
                    "  min_interval_seconds: 60",
                    "",
                ]
            ),
            encoding="utf-8",
        )
