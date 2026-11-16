#!/usr/bin/env python3
"""Build a clean, factual provider dataset from the public Clash 爱好者 index.

The script caches raw HTML under data/raw/ (gitignored) and writes a
normalized seed file to data/seed/providers.json. It intentionally does not
copy review prose, images, or affiliate links from the source site.
"""

from __future__ import annotations

import json
import re
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import Request, urlopen

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[1]
RAW = ROOT / "data" / "raw"
SEED = ROOT / "data" / "seed" / "providers.json"
OFFICIAL = ROOT / "data" / "seed" / "official-urls.json"
BASE = "https://clashios.com"
UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36 ProxyAtlasData/1.0"
)

PRICE_RE = re.compile(r"(?:[￥¥$]\s*|(?:USD|CNY)\s*)(\d+(?:\.\d+)?)|(\d+(?:\.\d+)?)\s*(?:元|美元)")
TRAFFIC_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(TB|T|GB|G)\s*(?:流量)?(?=\s*(?:/|，|,|。|；|;|$|\s))", re.I)
SPEED_RE = re.compile(r"限速\s*(\d+)\s*(Mbps|M)", re.I)
DEVICE_RE = re.compile(r"(?:限|至多|支持|在线\s*IP[:：]?\s*)\s*(\d+)\s*(?:台|个)?\s*(?:设备|IP)", re.I)
YEAR_RE = re.compile(r"(?:成立于|创立于|运营始于|始于|开业于)\s*(20\d{2})|(20\d{2})\s*年(?:开业|创立|成立)")
DATE_RE = re.compile(r"(20\d{2}-\d{2}-\d{2})")

# Only explicit, numeric node disclosures are trusted. Most source pages list
# server regions without saying how many IPs each region contains, so callers
# fall back to the region count as a lower bound.
NODE_COUNT_PATTERNS = [
    re.compile(r"(?:翻墙|机场|专线)?节点\s*(\d{1,4})\s*\+"),
    re.compile(r"(\d{1,4})\s*\+\s*(?:个)?\s*(?:深港\s*)?(?:IPLC|IEPL|BGP)?\s*(?:专线)?节点"),
    re.compile(r"(?:实际)?节点(?:数量|数|总数)\s*(?:为|是|约|达到|来到了|有|：|:)?\s*(\d{1,4})"),
]

PERIODS = {
    "月": (1, "月付"),
    "月付": (1, "月付"),
    "季": (3, "季付"),
    "季度": (3, "季付"),
    "季付": (3, "季付"),
    "半年": (6, "半年付"),
    "半年付": (6, "半年付"),
    "年": (12, "年付"),
    "年付": (12, "年付"),
    "年度": (12, "年付"),
}

PROTOCOL_PATTERNS = [
    ("AnyTLS", r"anytls"),
    ("Trojan", r"trojan"),
    ("VLESS", r"vless"),
    ("Vmess", r"vmess|v2ray"),
    ("Shadowsocks", r"shadowsocks|shadowsock|\bss\b|ss-2022"),
    ("SSR", r"shadowsocksr|\bssr\b"),
    ("Hysteria", r"hysteria"),
    ("WireGuard", r"wireguard"),
]

REGION_FLAGS = re.compile(
    "[\U0001F1E6-\U0001F1FF\U0001F3F4\U000E0067-\U000E007F\u2600-\u27BF\uFE0F]",
    re.UNICODE,
)


def fetch(url: str, cache_name: str, delay: float = 1.0) -> str:
    RAW.mkdir(parents=True, exist_ok=True)
    cache = RAW / cache_name
    if cache.exists():
        return cache.read_text(encoding="utf-8", errors="ignore")
    req = Request(url, headers={"User-Agent": UA, "Accept-Language": "zh-CN,zh;q=0.9"})
    with urlopen(req, timeout=40) as resp:
        body = resp.read()
    cache.write_bytes(body)
    time.sleep(delay)
    return body.decode("utf-8", errors="ignore")


def clean(text: str) -> str:
    text = text.replace("\xa0", " ").replace("\u3000", " ")
    text = REGION_FLAGS.sub("", text)
    return re.sub(r"\s+", " ", text).strip(" \t\r\n,，;；|｜·")


def split_list(text: str) -> list[str]:
    if text.strip().upper() in {"N/A", "NA", "暂无", "无"}:
        return []
    parts = [clean(x) for x in re.split(r"[、,，/]", text) if clean(x)]
    return [x for x in parts if x.upper() not in {"N/A", "NA", "暂无", "无", "未标注", "-", "—"}]


def path_slug(url: str | None) -> str | None:
    if not url:
        return None
    path = urlparse(url).path.strip("/")
    if not path:
        return None
    slug = path.split("/")[-1]
    if slug.startswith("how-about-") or slug in {"", "recommend", "reviews"}:
        return None
    return slug


def parse_period(segment: str, traffic_period: str | None) -> tuple[int, str, str]:
    """Return (months, label, source). Prefix wins over price suffix."""
    prefix = segment.split("：", 1)[0] if "：" in segment else segment[:12]
    for token, (months, label) in PERIODS.items():
        if token in prefix:
            return months, label, "prefix"
    suffix = segment.split("，", 1)[0]
    for token, (months, label) in PERIODS.items():
        if f"/{token}" in suffix:
            return months, label, "price"
    if traffic_period:
        for token, (months, label) in PERIODS.items():
            if token == traffic_period:
                return months, label, "traffic"
    return 1, "月付", "default"


def parse_plans(lines: list[tuple[str, str]]) -> list[dict]:
    """Parse (group, text) plan lines into grouped plan options."""
    groups: list[dict] = []
    group_index: dict[str, dict] = {}

    for group_name, raw in lines:
        raw = clean(raw)
        if not raw:
            continue
        prices = list(PRICE_RE.finditer(raw))
        if not prices:
            continue

        first = prices[0]
        name = clean(raw[: first.start()])
        name = re.sub(r"^(套餐价格|价格)[:：]?", "", name).strip(" ：:")
        name = re.sub(r"(月付|季付|半年付|年付)$", "", name).strip()
        name = re.sub(r"[:：]$", "", name).strip()
        explicit_name = bool(name)

        rest_start = first.start()
        segments = [s for s in re.split(r"[。；;]", raw[rest_start:]) if s.strip()]
        options: list[dict] = []
        for seg in segments:
            m = PRICE_RE.search(seg)
            if not m:
                continue
            price = float(m.group(1) or m.group(2))
            currency = "USD" if "$" in seg[: m.end()] or "美元" in seg[: m.end()] else "CNY"
            period_months, period_label, _ = parse_period(seg, None)

            tm = TRAFFIC_RE.search(seg)
            traffic_gb = None
            traffic_period = "month"
            if tm:
                amount = float(tm.group(1))
                unit = tm.group(2).upper()
                traffic_gb = amount * 1024 if unit.startswith("T") else amount
                tail = seg[tm.end() : tm.end() + 8]
                if re.search(r"/\s*年|每年", tail):
                    traffic_period = "year"
                elif re.search(r"/\s*季", tail):
                    traffic_period = "quarter"
                elif re.search(r"/\s*月|每月", tail):
                    traffic_period = "month"

            # If traffic is annual but price is monthly, normalise to monthly quota.
            monthly_traffic = traffic_gb
            if traffic_gb is not None:
                if traffic_period == "year":
                    monthly_traffic = traffic_gb / 12
                elif traffic_period == "quarter":
                    monthly_traffic = traffic_gb / 3

            # One-time traffic packs have no recurring period.
            one_time = bool(re.search(r"一次性|按量|不限时长|流量包", seg)) and "/" not in seg[: m.end()]
            if one_time:
                period_months = 0
                period_label = "按量"

            monthly_price = price / period_months if period_months else price
            unit_price = (
                monthly_price / monthly_traffic
                if monthly_price and monthly_traffic and not one_time
                else None
            )

            sm = SPEED_RE.search(seg)
            dm = DEVICE_RE.search(seg)
            notes = seg
            for rm in (PRICE_RE, TRAFFIC_RE, SPEED_RE, DEVICE_RE):
                notes = rm.sub("", notes)
            notes = re.sub(r"(月付|季付|半年付|年付|一次性|按量|不限时长)[:：]?", "", notes)
            notes = re.sub(r"[/／]\s*(月|季度|季|年|天|日)", "", notes)
            notes = clean(notes.replace("流量", "").replace("在线IP", ""))
            notes = notes.strip(" ，,；;、·/／")
            if notes in {"", "/", "／", "元", "美元", "人民币", "美金", "半年", "月", "季", "季度", "年", "天", "日"}:
                notes = ""

            option = {
                "label": period_label
                + (f" · {int(monthly_traffic)}G/月" if monthly_traffic and not one_time else ""),
                "price": round(price, 2),
                "currency": currency,
                "period": period_label,
                "period_months": period_months,
                "traffic_gb": round(traffic_gb, 2) if traffic_gb is not None else None,
                "traffic_period": traffic_period,
                "monthly_price": round(monthly_price, 2),
                "monthly_traffic_gb": round(monthly_traffic, 2) if monthly_traffic is not None else None,
                "unit_price": round(unit_price, 4) if unit_price is not None else None,
                "speed_limit": f"{sm.group(1)}{sm.group(2)}" if sm else None,
                "device_limit": int(dm.group(1)) if dm else None,
                "notes": notes,
            }
            options.append(option)

        if not options:
            continue
        if not explicit_name:
            first_opt = options[0]
            traffic = first_opt.get("monthly_traffic_gb")
            name = first_opt["label"] if not traffic else f"{first_opt['period']} · {int(traffic)}G"
        group_name = re.sub(r"[:：]$", "", clean(group_name))
        display_name = f"{group_name} · {name}" if group_name else name
        bucket = group_index.get(display_name)
        if bucket is None:
            bucket = {"name": display_name, "options": []}
            group_index[display_name] = bucket
            groups.append(bucket)
        bucket["options"].extend(options)

    # De-duplicate identical options and mark the cheapest recurring option.
    for group in groups:
        seen = set()
        unique = []
        for option in group["options"]:
            key = (
                option["price"],
                option["currency"],
                option["period_months"],
                option["traffic_gb"],
                option["traffic_period"],
            )
            if key in seen:
                continue
            seen.add(key)
            unique.append(option)
        group["options"] = unique
        recurring = [o for o in unique if o["period_months"] > 0]
        pool = recurring or unique
        group["entry"] = min(
            pool,
            key=lambda o: (o["monthly_price"] or o["price"], o["price"]),
        )

    return groups


def infer_line_type(text: str) -> str:
    low = text.lower()
    has_zhuanxian = "专线" in text or "iplc" in low or "iepl" in low
    has_zhongzhuan = "中转" in text or "隧道" in text
    has_zhilian = "直连" in text
    if has_zhuanxian and (has_zhongzhuan or has_zhilian):
        return "专线+中转"
    if has_zhuanxian:
        return "专线"
    if has_zhongzhuan:
        return "中转"
    if has_zhilian:
        return "直连"
    return "未标注"


def detect_protocols(text: str) -> list[str]:
    found = []
    low = text.lower()
    for name, pattern in PROTOCOL_PATTERNS:
        if re.search(pattern, low) and name not in found:
            found.append(name)
    # SSR implies Shadowsocks family; keep SSR but don't double-list SS unless explicit.
    if "SSR" in found and "Shadowsocks" in found and "shadowsocks" not in low:
        found.remove("Shadowsocks")
    return found


def detect_node_count(text: str) -> int | None:
    for pattern in NODE_COUNT_PATTERNS:
        m = pattern.search(text)
        if m:
            count = int(m.group(1))
            if 1 <= count <= 5000:
                return count
    return None


def detect_payments(text: str) -> list[str]:
    table = [
        ("支付宝", r"支付宝"),
        ("微信支付", r"微信支付|微信"),
        ("信用卡", r"信用卡|Visa|Mastercard"),
        ("PayPal", r"paypal"),
        ("加密货币", r"加密货币|USDT|USDC|TRC20|ERC20|Metamask"),
    ]
    return [name for name, pattern in table if re.search(pattern, text, re.I)]


def detect_clients(text: str) -> list[str]:
    found = []
    for name, pattern in [
        ("Windows", r"Windows"),
        ("macOS", r"Mac\b|macOS"),
        ("Android", r"Android|安卓"),
        ("iOS", r"iOS|iPhone|iPad"),
        ("Linux", r"Linux"),
        ("TV", r"TV\b|电视"),
    ]:
        if re.search(pattern, text, re.I):
            found.append(name)
    return found


def parse_review(html: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    article = soup.find("article") or soup
    text = article.get_text("\n", strip=True)
    flat = clean(text)

    common: list[str] = []
    other: list[str] = []
    for table in article.find_all("table"):
        for tr in table.find_all("tr"):
            cells = [clean(td.get_text(" ", strip=True)) for td in tr.find_all(["th", "td"])]
            if len(cells) < 2:
                continue
            if cells[0] == "常用":
                common = split_list(cells[1])
            elif cells[0] == "其他":
                other = split_list(cells[1])

    founded = None
    m = YEAR_RE.search(flat)
    if m:
        year = int(m.group(1) or m.group(2))
        if 2000 <= year <= datetime.now().year:
            founded = year

    updated = None
    m = re.search(r"本文于\s*(20\d{2}-\d{2}-\d{2})", flat)
    if m:
        updated = m.group(1)
    else:
        m = DATE_RE.search(flat)
        if m:
            updated = m.group(1)

    media = [name for name, pattern in [
        ("Netflix", r"Netflix|奈飞"),
        ("Disney+", r"Disney\+|迪士尼"),
        ("YouTube", r"YouTube"),
        ("TikTok", r"TikTok"),
        ("HBO", r"HBO"),
    ] if re.search(pattern, flat, re.I)]
    ai = [name for name, pattern in [
        ("ChatGPT", r"ChatGPT"),
        ("Gemini", r"Gemini|Bard"),
        ("Claude", r"Claude"),
        ("Sora", r"Sora"),
    ] if re.search(pattern, flat, re.I)]

    return {
        "founded": founded,
        "updated_at": updated,
        "regions_common": common,
        "regions_other": other,
        "media_unlock": media,
        "ai_unlock": ai,
        "trial": bool(re.search(r"免费试用|免费体验", flat)),
        "node_count": detect_node_count(flat),
        "crypto": bool(re.search(r"加密货币|USDT|USDC|Metamask|Web3", flat, re.I)),
        "custom_client": bool(re.search(r"定制客户端|一键客户端|专属客户端|定制三端|定制一键", flat)),
        "invite_only": bool(re.search(r"邀请制|邀请注册", flat)),
        "residential": bool(re.search(r"家宽|原生\s*IP|住宅\s*IP", flat)),
        "payments": detect_payments(flat),
        "clients": detect_clients(flat),
    }


def parse_promo(items: list[str]) -> tuple[str, str]:
    """Return (code, human label) from source promo snippets."""
    joined = "；".join(items)
    code = ""
    m = re.search(r"(?:优惠码|折扣码)[:：]?\s*([A-Za-z0-9_-]{2,})", joined)
    if not m:
        m = re.search(r"[:：]\s*([A-Za-z0-9_-]{2,})\s*$", joined)
    if m:
        code = m.group(1)
    else:
        # The source often puts a bare code in its own list item.
        for item in items:
            token = item.strip()
            if re.fullmatch(r"[A-Za-z0-9_-]{2,}", token) and not re.fullmatch(r"\d+折", token):
                code = token
                break
    discount = ""
    m = re.search(r"(\d+(?:\.\d+)?)\s*折", joined)
    if m:
        discount = f"{m.group(1)} 折"
    if code and discount:
        label = f"{discount} · 优惠码 {code}"
    elif code:
        label = f"优惠码 {code}"
    elif discount:
        label = f"{discount}优惠"
    else:
        label = joined
    return code, label


def refresh_entry(plan: dict) -> None:
    recurring = [o for o in plan["options"] if o["period_months"] > 0]
    pool = recurring or plan["options"]
    plan["entry"] = min(pool, key=lambda o: (o["monthly_price"] or o["price"], o["price"])) if pool else None


def provider_summary(p: dict) -> str:
    parts = []
    if p.get("founded"):
        parts.append(f"{p['founded']} 年成立")
    if p.get("line_type") and p["line_type"] != "未标注":
        parts.append(f"{p['line_type']}线路")
    if p.get("protocols"):
        parts.append("支持 " + " / ".join(p["protocols"]))
    if p.get("regions"):
        parts.append(f"覆盖 {len(p['regions'])} 个地区")
    entry = p.get("monthly_entry") or p.get("entry")
    if entry:
        symbol = "$" if entry["currency"] == "USD" else "￥"
        traffic = entry.get("monthly_traffic_gb")
        traffic_text = f"、{int(traffic)}GB/月" if traffic else ""
        if entry["period_months"] > 1:
            parts.append(
                f"入门约 {symbol}{entry['price']:g}/{entry['period']}（月均 {symbol}{entry['monthly_price']:g}）{traffic_text}"
            )
        else:
            parts.append(f"月付约 {symbol}{entry['price']:g}{traffic_text}")
    unlock = list(dict.fromkeys((p.get("media_unlock") or []) + (p.get("ai_unlock") or [])))
    if unlock:
        parts.append("评测提及 " + "、".join(unlock[:4]) + " 解锁")
    if p.get("monthly_offline"):
        parts.append("月付已下线，季付起步")
    if not parts:
        return "信息整理自公开评测，购买前请以服务商官网为准。"
    return "，".join(parts) + "。"


def main() -> None:
    html = fetch(BASE + "/", "home.html")
    soup = BeautifulSoup(html, "html.parser")
    article = soup.find("article") or soup
    siblings = article.find_all(recursive=False)
    headings = []
    for idx, node in enumerate(siblings):
        if node.name == "h3":
            title = node.get_text(" ", strip=True)
            if re.match(r"^\d+\.", title):
                headings.append((idx, node))

    providers = []
    used_slugs = set()
    official_map: dict[str, str] = {}
    if OFFICIAL.is_file():
        try:
            official_map = json.loads(OFFICIAL.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            print(f"warning: invalid {OFFICIAL.relative_to(ROOT)}, official URLs will be empty")

    for order, (idx, h3) in enumerate(headings, start=1):
        end = headings[order][0] if order < len(headings) else len(siblings)
        block = siblings[idx:end]

        raw_title = re.sub(r"^\d+\.\s*", "", h3.get_text(" ", strip=True))
        title = clean(raw_title.split("—")[0])
        title = re.split(r"\s+[–-]\s+", title)[0]
        title = re.sub(r"[\U0001F300-\U0001FAFF\u2600-\u27BF\uFE0F]", "", title).strip()

        tags: list[str] = []
        promo: list[str] = []
        description = ""
        regions: list[str] = []
        plan_lines: list[tuple[str, str]] = []
        official_url = None
        review_url = None
        group = ""

        seen_plan_header = False
        for node in block[1:]:
            if node.name in {"h3", "h2"}:
                break
            if node.name == "figure":
                continue
            if node.name == "ul":
                items = [clean(li.get_text(" ", strip=True)) for li in node.find_all("li", recursive=False)]
                items = [i for i in items if i]
                if not tags and not seen_plan_header:
                    for item in items:
                        if re.search(r"折|优惠码|优惠|New2025|boost|BYG10OFFAN|clashios|^999$", item, re.I):
                            promo.append(item)
                        else:
                            tags.append(item)
                else:
                    for li in node.find_all("li", recursive=False):
                        nested = li.find("ul")
                        if nested:
                            label = clean("".join(li.find_all(string=True, recursive=False))).rstrip("：:")
                            for sub in nested.find_all("li", recursive=False):
                                plan_lines.append((label, clean(sub.get_text(" ", strip=True))))
                        else:
                            plan_lines.append((group, clean(li.get_text(" ", strip=True))))
                continue
            if node.name != "p":
                continue
            text = clean(node.get_text(" ", strip=True))
            if not text:
                continue
            if text.startswith("服务器"):
                region_text = re.sub(r"^服务器[:：]?", "", text)
                region_text = re.sub(r"机场节点.*$", "", region_text)
                regions = split_list(region_text)
                continue
            if "套餐价格" in text or text.rstrip("：:") in {"套餐", "价格", "套餐价格"}:
                seen_plan_header = True
                continue
            if "怎么样" in text or "机场官网" in text or "官网" in text:
                for a in node.find_all("a"):
                    label = clean(a.get_text(" ", strip=True))
                    href = a.get("href")
                    if not href:
                        continue
                    if "怎么样" in label or "/how-about-" in href:
                        review_url = href
                    elif official_url is None:
                        official_url = href
                continue
            if "套餐价格" in text:
                continue
            if seen_plan_header:
                if PRICE_RE.search(text):
                    plan_lines.append((group, text))
                elif text.endswith("套餐") or text.endswith("套餐："):
                    group = text.rstrip("：:")
                continue
            if not description and len(text) > 25:
                description = text

        prev = h3.find_previous_sibling()
        fig_id = clean(prev.get("id", "")) if prev is not None and prev.name == "figure" else ""
        slug = path_slug(official_url) or path_slug(review_url) or fig_id
        if not slug:
            slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-") or f"provider-{order}"
        base_slug = slug
        n = 2
        while slug in used_slugs:
            slug = f"{base_slug}-{n}"
            n += 1
        used_slugs.add(slug)

        review = {}
        if review_url:
            review_slug = urlparse(review_url).path.strip("/").split("/")[-1]
            try:
                review_html = fetch(review_url, f"{review_slug}.html")
                review = parse_review(review_html)
            except Exception as exc:  # noqa: BLE001 - keep the dataset build resilient
                print(f"  ! review fetch failed for {title}: {exc}")
            # This page is a multi-provider roundup; its node counts belong to
            # other airports and must not be attached to Just My Socks.
            if review_slug == "just-my-socks-alternative-providers":
                review["node_count"] = None

        all_regions = review.get("regions_common", []) + review.get("regions_other", [])
        region_list = list(dict.fromkeys(all_regions or regions))

        block_text = " ".join(clean(n.get_text(" ", strip=True)) for n in block if n.name in {"p", "li"})
        monthly_offline = bool(re.search(r"月付下线|取消月付|月付.{0,6}(?:下线|取消)|季付起步", block_text))
        plans = parse_plans(plan_lines)
        if monthly_offline:
            for plan in plans:
                plan["options"] = [o for o in plan["options"] if o["period_months"] != 1]
                refresh_entry(plan)
            plans = [plan for plan in plans if plan["options"]]

        entry = None
        monthly_entry = None
        for plan in plans:
            candidate = plan.get("entry")
            if not candidate:
                continue
            if entry is None or (candidate["monthly_price"] or candidate["price"]) < (
                entry["monthly_price"] or entry["price"]
            ):
                entry = dict(candidate, plan_name=plan["name"])
            for option in plan["options"]:
                if option["period_months"] != 1:
                    continue
                if monthly_entry is None or option["monthly_price"] < monthly_entry["monthly_price"]:
                    monthly_entry = dict(option, plan_name=plan["name"])

        source_text = " ".join(tags + [description, " ".join(regions)])
        line_type = infer_line_type(source_text)
        protocols = detect_protocols(" ".join(tags) + " " + description)
        media = review.get("media_unlock", [])
        ai = review.get("ai_unlock", [])
        features = []
        if review.get("trial"):
            features.append("免费试用")
        if review.get("custom_client"):
            features.append("定制客户端")
        if review.get("crypto"):
            features.append("加密货币")
        if review.get("invite_only"):
            features.append("邀请制")
        if review.get("residential"):
            features.append("家宽/原生 IP")
        if "团队" in description or "企业" in description:
            features.append("团队/企业")

        discount_code, discount_label = parse_promo(promo)

        explicit_nodes = review.get("node_count") or detect_node_count(block_text)
        node_count = explicit_nodes if explicit_nodes is not None else len(region_list)
        node_count_note = (
            f"来源明确披露 {explicit_nodes}+ 个节点"
            if explicit_nodes is not None
            else f"来源列出 {len(region_list)} 个服务器地区，按每地区至少 1 个独立 IP 计下限"
        )

        p = {
            "slug": slug,
            "name": title,
            "name_en": "",
            "source_order": order,
            "founded": review.get("founded"),
            "tags": tags[:6],
            "promo": promo,
            "line_type": line_type,
            "protocols": protocols,
            "regions": region_list,
            "regions_common": review.get("regions_common", []),
            "regions_other": review.get("regions_other", []),
            "node_count": int(node_count),
            "node_count_disclosed": 1 if explicit_nodes is not None else 0,
            "node_count_note": node_count_note,
            "media_unlock": media,
            "ai_unlock": ai,
            "features": features,
            "payments": review.get("payments", []),
            "clients": review.get("clients", []),
            "plans": plans,
            "entry": entry,
            "monthly_entry": monthly_entry,
            "monthly_offline": monthly_offline,
            "plan_note": "月付已下线，季付起步" if monthly_offline else "",
            "discount_code": discount_code,
            "discount_label": discount_label,
            "official_url": official_map.get(slug),
            "source_review_url": review_url or "",
            "source_home_url": BASE + "/",
            "source_official_redirect": official_url or "",
            "source_updated_at": review.get("updated_at") or "2026-11-08",
            "status": "published",
            "weight": 0,
        }
        p["summary"] = provider_summary(p)
        providers.append(p)
        print(f"{order:02d} {title} -> {slug} | {len(plans)} plans | {len(region_list)} regions")

    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "source": {
            "name": "Clash 爱好者",
            "homepage": BASE + "/",
            "updated_at": "2026-11-08",
            "note": "仅整理公开事实信息，不复制原文、图片或联盟链接。",
        },
        "providers": providers,
    }
    SEED.parent.mkdir(parents=True, exist_ok=True)
    SEED.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"wrote {len(providers)} providers -> {SEED.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
