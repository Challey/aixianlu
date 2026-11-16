"""
Stable device fingerprint.

Only a truncated SHA-256 digest ever leaves the machine; host names,
machine IDs and hardware identifiers are never included in reports.
"""

from __future__ import annotations

import hashlib
import platform
from pathlib import Path


def _machine_id() -> str:
    for candidate in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
        try:
            value = Path(candidate).read_text(encoding="utf-8").strip()
        except OSError:
            continue
        if value:
            return value

    if platform.system() == "Windows":
        try:
            import winreg  # type: ignore[import-not-found]

            with winreg.OpenKey(  # type: ignore[attr-defined]
                winreg.HKEY_LOCAL_MACHINE,  # type: ignore[attr-defined]
                r"SOFTWARE\Microsoft\Cryptography",
            ) as key:
                value, _ = winreg.QueryValueEx(key, "MachineGuid")  # type: ignore[attr-defined]
                return str(value)
        except Exception:
            return ""

    return ""


def device_fingerprint() -> str:
    """16 hex chars, stable across runs on the same machine."""
    raw = "|".join(
        [
            _machine_id(),
            platform.node(),
            platform.system(),
            platform.machine(),
        ]
    )
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:16]


def default_probe_id() -> str:
    return f"probe-{device_fingerprint()}"
