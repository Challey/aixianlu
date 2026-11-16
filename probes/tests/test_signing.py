"""Signing and canonical JSON tests, including the cross-language fixture."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from aixianlu_probe.signing import canonical_json, sign_payload, verify_signature

FIXTURE = json.loads((Path(__file__).parent / "fixtures" / "signing_vector.json").read_text("utf-8"))


def test_canonical_matches_fixture() -> None:
    unsigned = {k: v for k, v in FIXTURE["payload"].items() if k != "signature"}
    assert canonical_json(unsigned) == FIXTURE["canonical"]


def test_signature_matches_fixture() -> None:
    assert sign_payload(FIXTURE["payload"], FIXTURE["token"]) == FIXTURE["signature"]


def test_verify_signature_accepts_fixture() -> None:
    assert verify_signature(FIXTURE["payload"], FIXTURE["token"], FIXTURE["signature"])


def test_key_order_does_not_matter() -> None:
    a = {"b": 1, "a": {"d": 2, "c": 3}}
    b = {"a": {"c": 3, "d": 2}, "b": 1}
    assert canonical_json(a) == canonical_json(b)


def test_signature_field_is_excluded() -> None:
    payload = dict(FIXTURE["payload"])
    payload["signature"] = "deadbeef"
    assert sign_payload(payload, FIXTURE["token"]) == FIXTURE["signature"]


def test_floats_use_six_decimals() -> None:
    assert canonical_json({"latency_ms": 186.4}) == '{"latency_ms":186.400000}'
    assert canonical_json({"latency_ms": 0.0}) == '{"latency_ms":0.000000}'


def test_unicode_is_not_escaped() -> None:
    assert canonical_json({"isp": "电信"}) == '{"isp":"电信"}'


def test_tampered_payload_fails_verification() -> None:
    payload = json.loads(json.dumps(FIXTURE["payload"]))
    payload["results"][0]["latency_ms"] = 1.0
    assert not verify_signature(payload, FIXTURE["token"], FIXTURE["signature"])


def test_wrong_token_fails_verification() -> None:
    assert not verify_signature(FIXTURE["payload"], "other-token", FIXTURE["signature"])


def test_missing_signature_fails_verification() -> None:
    assert not verify_signature(FIXTURE["payload"], FIXTURE["token"], None)
    assert not verify_signature(FIXTURE["payload"], FIXTURE["token"], "")


def test_non_finite_numbers_are_rejected() -> None:
    with pytest.raises(ValueError):
        canonical_json({"latency_ms": float("nan")})
