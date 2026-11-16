"""
Optional ISP / region detection (off by default).

When enabled, the probe asks a public IP information service for the current
network's ISP, province and ASN. Only those fields are kept: the IP address
itself is never stored, logged or uploaded.

Manual values in the config always win over auto-detection.
"""

from __future__ import annotations

import re
from typing import Any, Callable, Optional

import httpx

from aixianlu_probe.models import Location

IPIP_URL = "https://myip.ipip.net"
IPSB_URL = "https://api.ip.sb/geoip"
IPAPI_URL = "https://ipapi.co/json/"

_ISP_KEYWORDS: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("电信", ("电信", "telecom", "chinanet", "ctcc")),
    ("联通", ("联通", "unicom", "cncgroup", "cucc", "cnCG")),
    ("移动", ("移动", "mobile", "cmcc", "cmnet", "tietong", "铁通")),
)

_ASN_ISP: dict[int, str] = {
    4134: "电信", 4809: "电信", 4812: "电信", 4811: "电信", 4835: "电信", 17638: "电信",
    4837: "联通", 9929: "联通", 10099: "联通", 4808: "联通", 9800: "联通",
    9808: "移动", 24400: "移动", 24547: "移动",
    56040: "移动", 56041: "移动", 56042: "移动", 56044: "移动",
    56046: "移动", 56047: "移动", 56048: "移动",
}

_COUNTRY_MAP = {
    "中国": "CN", "美国": "US", "日本": "JP", "新加坡": "SG", "韩国": "KR",
    "德国": "DE", "英国": "GB", "法国": "FR", "荷兰": "NL", "加拿大": "CA",
}


def normalize_isp(text: str, asn: Optional[int] = None) -> str:
    low = (text or "").lower()
    for label, keywords in _ISP_KEYWORDS:
        if any(keyword.lower() in low for keyword in keywords):
            return label
    if asn is not None and asn in _ASN_ISP:
        return _ASN_ISP[asn]
    return "其他" if text else ""


def _parse_ipip(text: str) -> Optional[Location]:
    match = re.search(r"来自于[:：]\s*(.+)", text or "")
    if not match:
        return None
    parts = match.group(1).split()
    if len(parts) < 2:
        return None
    country = _COUNTRY_MAP.get(parts[0], parts[0])
    region = parts[1] if len(parts) > 1 else ""
    isp_raw = parts[-1] if len(parts) >= 3 else ""
    return Location(isp=normalize_isp(isp_raw), region=region, asn=None, country=country)


def _parse_ipsb(data: Any) -> Optional[Location]:
    if not isinstance(data, dict):
        return None
    asn_raw = data.get("asn")
    asn = int(asn_raw) if isinstance(asn_raw, (int, float)) else None
    isp_raw = str(data.get("isp") or data.get("organization") or data.get("asn_organization") or "")
    region = str(data.get("region") or data.get("city") or "")
    country = str(data.get("country_code") or data.get("country") or "")
    if not isp_raw and not region:
        return None
    return Location(isp=normalize_isp(isp_raw, asn), region=region, asn=asn, country=country)


def _parse_ipapi(data: Any) -> Optional[Location]:
    if not isinstance(data, dict):
        return None
    asn_raw = str(data.get("asn") or "").upper().removeprefix("AS")
    asn = int(asn_raw) if asn_raw.isdigit() else None
    isp_raw = str(data.get("org") or "")
    region = str(data.get("region") or data.get("city") or "")
    country = str(data.get("country_code") or data.get("country") or "")
    if not isp_raw and not region:
        return None
    return Location(isp=normalize_isp(isp_raw, asn), region=region, asn=asn, country=country)


_PROVIDERS: tuple[tuple[str, Callable[[Any], Optional[Location]], bool], ...] = (
    (IPIP_URL, _parse_ipip, True),
    (IPSB_URL, _parse_ipsb, False),
    (IPAPI_URL, _parse_ipapi, False),
)


async def detect_location(
    timeout: float = 5.0,
    client: Optional[httpx.AsyncClient] = None,
) -> Location:
    """Best-effort location detection; returns an empty Location on failure."""
    owns_client = client is None
    if owns_client:
        client = httpx.AsyncClient(
            timeout=timeout,
            follow_redirects=True,
            headers={"User-Agent": "aixianlu-probe/0.2.0"},
        )
    assert client is not None
    try:
        for url, parser, is_text in _PROVIDERS:
            try:
                resp = await client.get(url, timeout=timeout)
                if resp.status_code != 200:
                    continue
                location = parser(resp.text if is_text else resp.json())
            except Exception:
                continue
            if location is not None and (location.isp or location.region):
                return location
    finally:
        if owns_client:
            await client.aclose()
    return Location()


def merge_location(manual: Location, detected: Location) -> Location:
    """Manual config values take precedence over detected ones."""
    return Location(
        isp=manual.isp or detected.isp,
        region=manual.region or detected.region,
        asn=manual.asn if manual.asn is not None else detected.asn,
        country=manual.country or detected.country,
    )
