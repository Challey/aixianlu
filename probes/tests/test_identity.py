"""Device fingerprint tests."""

from __future__ import annotations

import platform
import re

from aixianlu_probe.identity import default_probe_id, device_fingerprint


def test_fingerprint_is_stable() -> None:
    assert device_fingerprint() == device_fingerprint()


def test_fingerprint_shape() -> None:
    assert re.fullmatch(r"[0-9a-f]{16}", device_fingerprint())


def test_probe_id_shape() -> None:
    assert re.fullmatch(r"probe-[0-9a-f]{16}", default_probe_id())


def test_fingerprint_does_not_leak_hostname() -> None:
    node = platform.node()
    if node:
        assert node not in device_fingerprint()
        assert node not in default_probe_id()
