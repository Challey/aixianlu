"""Location detection tests (parsers + HTTP fallback chain)."""

from __future__ import annotations

import httpx

from aixianlu_probe.geo import (
    _parse_ipapi,
    _parse_ipip,
    _parse_ipsb,
    detect_location,
    merge_location,
    normalize_isp,
)
from aixianlu_probe.models import Location


def test_parse_ipip_text() -> None:
    location = _parse_ipip("当前 IP：14.220.248.116  来自于：中国 广东 深圳  电信")
    assert location is not None
    assert location.isp == "电信"
    assert location.region == "广东"
    assert location.country == "CN"
    assert location.asn is None


def test_parse_ipip_never_keeps_ip() -> None:
    location = _parse_ipip("当前 IP：1.2.3.4  来自于：中国 北京 北京 联通")
    assert location is not None
    assert "1.2.3.4" not in location.model_dump_json()
    assert location.isp == "联通"


def test_parse_ipsb_json() -> None:
    location = _parse_ipsb(
        {"isp": "China Telecom", "asn": 4134, "region": "Guangdong", "country_code": "CN"}
    )
    assert location is not None
    assert location.isp == "电信"
    assert location.asn == 4134
    assert location.region == "Guangdong"


def test_parse_ipapi_json() -> None:
    location = _parse_ipapi(
        {"org": "AS9808 China Mobile", "asn": "AS9808", "region": "Beijing", "country_code": "CN"}
    )
    assert location is not None
    assert location.isp == "移动"
    assert location.asn == 9808


def test_normalize_isp_falls_back_to_asn() -> None:
    assert normalize_isp("Some Unknown Network", 4837) == "联通"
    assert normalize_isp("Some Unknown Network", 56040) == "移动"
    assert normalize_isp("Some Unknown Network", 12345) == "其他"
    assert normalize_isp("") == ""


def test_merge_location_manual_wins() -> None:
    manual = Location(isp="电信", region="", asn=None, country="CN")
    detected = Location(isp="联通", region="广东", asn=4837, country="CN")
    merged = merge_location(manual, detected)
    assert merged.isp == "电信"
    assert merged.region == "广东"
    assert merged.asn == 4837


async def test_detect_location_uses_first_working_provider() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        if "ipip.net" in str(request.url):
            return httpx.Response(200, text="当前 IP：1.2.3.4  来自于：中国 广东 深圳  电信")
        return httpx.Response(500)

    async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
        location = await detect_location(2.0, client=client)
    assert location.isp == "电信"
    assert location.region == "广东"


async def test_detect_location_falls_back_to_json_provider() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        if "ipip.net" in str(request.url):
            return httpx.Response(403)
        if "ip.sb" in str(request.url):
            return httpx.Response(
                200, json={"isp": "China Unicom", "asn": 4837, "region": "Shanghai", "country_code": "CN"}
            )
        return httpx.Response(500)

    async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
        location = await detect_location(2.0, client=client)
    assert location.isp == "联通"
    assert location.asn == 4837


async def test_detect_location_returns_empty_on_total_failure() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("no route to host")

    async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
        location = await detect_location(2.0, client=client)
    assert location.isp == ""
    assert location.region == ""
