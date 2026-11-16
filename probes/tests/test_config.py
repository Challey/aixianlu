"""Configuration loading tests."""

from __future__ import annotations

from pathlib import Path

import pytest

from aixianlu_probe.config import load_config
from aixianlu_probe.targets import DEFAULT_TARGETS


def test_defaults_without_file() -> None:
    cfg = load_config(None)
    assert cfg.probe_id.startswith("probe-")
    assert len(cfg.probe_id) == len("probe-") + 16
    assert [t.name for t in cfg.targets] == [t.name for t in DEFAULT_TARGETS]
    assert cfg.retries == 1
    assert cfg.report_min_interval_seconds == 60
    assert cfg.auto_detect_location is False


def test_yaml_overrides(tmp_path: Path) -> None:
    path = tmp_path / "config.yaml"
    path.write_text(
        "\n".join(
            [
                'probe_id: "probe-custom"',
                "timeout_seconds: 3",
                "concurrency: 2",
                "retries: 0",
                "location:",
                "  auto_detect: true",
                '  isp: "联通"',
                '  region: "上海"',
                "  asn: 4837",
                "report:",
                "  enabled: true",
                '  endpoint: "http://127.0.0.1:8090/api/v1/metrics/report"',
                '  token: "t-123"',
                "  min_interval_seconds: 0",
                "targets:",
                "  - name: local",
                '    url: "http://127.0.0.1:8090/"',
                "    expected_status: [200]",
            ]
        ),
        encoding="utf-8",
    )
    cfg = load_config(path)
    assert cfg.probe_id == "probe-custom"
    assert cfg.timeout_seconds == 3
    assert cfg.retries == 0
    assert cfg.auto_detect_location is True
    assert cfg.location.isp == "联通"
    assert cfg.location.asn == 4837
    assert cfg.report_enabled is True
    assert cfg.report_token == "t-123"
    assert cfg.report_min_interval_seconds == 0
    assert [t.name for t in cfg.targets] == ["local"]
    # auto_detect must not leak into the report payload
    assert "auto_detect" not in cfg.location.model_dump()


def test_extend_targets_keeps_defaults(tmp_path: Path) -> None:
    path = tmp_path / "config.yaml"
    path.write_text(
        "extend_targets: true\ntargets:\n  - name: extra\n    url: https://example.com/\n",
        encoding="utf-8",
    )
    cfg = load_config(path)
    names = [t.name for t in cfg.targets]
    assert names[0] == "extra"
    assert "openai-api" in names
    assert len(names) == len(DEFAULT_TARGETS) + 1


def test_override_by_name_when_extending(tmp_path: Path) -> None:
    path = tmp_path / "config.yaml"
    path.write_text(
        "extend_targets: true\n"
        "targets:\n"
        "  - name: openai-api\n"
        "    url: https://mirror.example.com/v1/models\n",
        encoding="utf-8",
    )
    cfg = load_config(path)
    assert len(cfg.targets) == len(DEFAULT_TARGETS)
    assert cfg.targets[0].url == "https://mirror.example.com/v1/models"


def test_invalid_targets_exit(tmp_path: Path) -> None:
    path = tmp_path / "config.yaml"
    path.write_text("targets:\n  - url: https://example.com/\n", encoding="utf-8")
    with pytest.raises(SystemExit):
        load_config(path)


def test_model_target_fields(tmp_path: Path) -> None:
    path = tmp_path / "config.yaml"
    path.write_text(
        "targets:\n"
        "  - name: chat\n"
        "    url: https://example.com/v1/chat/completions\n"
        "    method: POST\n"
        "    model: gpt-4o-mini\n"
        "    auth_env: OPENAI_API_KEY\n"
        "    auth_header: x-api-key\n"
        '    auth_scheme: ""\n'
        "    body:\n"
        "      model: gpt-4o-mini\n",
        encoding="utf-8",
    )
    target = load_config(path).targets[0]
    assert target.model == "gpt-4o-mini"
    assert target.auth_env == "OPENAI_API_KEY"
    assert target.auth_header == "x-api-key"
    assert target.auth_scheme == ""
    assert target.body == {"model": "gpt-4o-mini"}
