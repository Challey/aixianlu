"""
HMAC-SHA256 signing over a canonical JSON form (PROTOCOL.md §3).

The canonical form is defined so that Python and PHP (and a future Go port)
produce byte-identical output:

- the top-level ``signature`` field is removed before signing;
- object keys are sorted ascending, arrays keep their order;
- no whitespace between tokens (`,` and `:` separators);
- strings are UTF-8, non-ASCII characters are NOT escaped;
- floats are written with exactly 6 decimal places;
- integers, booleans and null use their JSON spellings.

The signing key is derived from the probe token:
``HMAC-SHA256(key=token, msg="aixianlu-probe/v1/signing")`` (raw 32 bytes).
"""

from __future__ import annotations

import hashlib
import hmac
import json
import math
from typing import Any, Optional

SIGNING_CONTEXT = b"aixianlu-probe/v1/signing"
FLOAT_DECIMALS = 6


def derive_key(token: str) -> bytes:
    """Derive the raw HMAC key from a probe token."""
    return hmac.new(token.encode("utf-8"), SIGNING_CONTEXT, hashlib.sha256).digest()


def _write(value: Any, out: list[str]) -> None:
    if value is None:
        out.append("null")
    elif isinstance(value, bool):
        out.append("true" if value else "false")
    elif isinstance(value, int):
        out.append(str(value))
    elif isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("non-finite numbers cannot be signed")
        out.append(f"{value:.{FLOAT_DECIMALS}f}")
    elif isinstance(value, str):
        out.append(json.dumps(value, ensure_ascii=False))
    elif isinstance(value, dict):
        out.append("{")
        for index, key in enumerate(sorted(value.keys(), key=str)):
            if index:
                out.append(",")
            out.append(json.dumps(str(key), ensure_ascii=False))
            out.append(":")
            _write(value[key], out)
        out.append("}")
    elif isinstance(value, (list, tuple)):
        out.append("[")
        for index, item in enumerate(value):
            if index:
                out.append(",")
            _write(item, out)
        out.append("]")
    else:
        raise TypeError(f"unsupported type for canonical JSON: {type(value)!r}")


def canonical_json(value: Any) -> str:
    out: list[str] = []
    _write(value, out)
    return "".join(out)


def canonical_bytes(payload: dict[str, Any]) -> bytes:
    """Canonical UTF-8 bytes of a report payload, excluding ``signature``."""
    unsigned = {key: value for key, value in payload.items() if key != "signature"}
    return canonical_json(unsigned).encode("utf-8")


def sign_payload(payload: dict[str, Any], token: str) -> str:
    return hmac.new(derive_key(token), canonical_bytes(payload), hashlib.sha256).hexdigest()


def verify_signature(payload: dict[str, Any], token: str, signature: Optional[str]) -> bool:
    if not signature or not token:
        return False
    expected = sign_payload(payload, token)
    return hmac.compare_digest(expected, signature.lower())
