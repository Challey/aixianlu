#!/usr/bin/env python3
"""Collect daily AI news from public RSS feeds into SQLite.

Design notes:
- Store only title, a short summary, source name, source URL, publish time,
  category, tags and related tool slugs. Do not store full article text or images.
- Deduplicate by feed guid/link and by normalized title within one run.
- Keep the classifier and product-link rules deterministic; no generated claims.
"""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import re
import sqlite3
import sys
import time
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Optional
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin
from urllib.request import Request, urlopen
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SOURCES_FILE = ROOT / "data" / "seed" / "news_sources.json"
DEFAULT_DB = ROOT / "storage" / "proxy-atlas.sqlite"
SCHEMA_FILE = ROOT / "app" / "schema.sql"
UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36 ProxyAtlasNews/1.0"
)
MAX_FEED_BYTES = 6 * 1024 * 1024

AI_KEYWORDS = [
    # Allow "AI" next to Chinese text, but not inside an English word.
    r"(?<![A-Za-z])AI(?![A-Za-z])", r"人工智能", r"大模型", r"生成式", r"机器学习", r"深度学习",
    r"多模态", r"智能体", r"(?<![A-Za-z])Agent(?![A-Za-z])", r"OpenAI", r"ChatGPT", r"Claude",
    r"Anthropic", r"Gemini", r"DeepMind", r"英伟达", r"NVIDIA", r"黄仁勋",
    r"Jensen Huang", r"算力", r"Copilot", r"Cursor",
    r"Qoder", r"Perplexity", r"Sora", r"Midjourney", r"(?<![A-Za-z])LLM(?![A-Za-z])", r"机器人",
    r"具身智能", r"自动驾驶", r"Robotaxi", r"AI 编程", r"AI编程",
    r"豆包", r"千问", r"通义", r"文心", r"混元", r"Kimi", r"月之暗面",
    r"智谱", r"MiniMax", r"开源模型",
]

CATEGORY_RULES = [
    ("funding", [r"融资", r"估值", r"收购", r"并购", r"\bIPO\b", r"投资", r"募资",
                 r"\braises?\b", r"\braised\b", r"funding", r"valuation", r"acquires?",
                 r"acquisition", r"\bmerger\b", r"\binvest(?:s|ed|ing|ment|ments|or|ors)?\b", r"\bIPO\b"]),
    ("people", [r"\bCEO\b", r"创始人", r"高管", r"离职", r"加入", r"任命", r"接任",
                r"\bfounder\b", r"\bexecs?\b", r"\bexecutive\b", r"resigns?", r"appoints?", r"steps? down"]),
    ("chip", [r"芯片", r"\bGPU\b", r"算力", r"NVIDIA", r"英伟达", r"台积电", r"TSMC",
              r"HBM", r"晶圆", r"半导体", r"数据中心", r"data cent(?:er|re)",
              r"服务器", r"黄仁勋", r"Jensen Huang", r"semiconductor"]),
    ("model", [r"大模型", r"模型", r"参数", r"推理", r"多模态", r"开源", r"基准",
               r"\bAGI\b", r"\bLLM\b", r"训练", r"微调", r"world model", r"inference",
               r"open[- ]source", r"multimodal", r"benchmark", r"parameters"]),
    ("product", [r"发布", r"上线", r"更新", r"功能", r"订阅", r"插件", r"版本",
                 r"应用", r"开放", r"推出", r"搭载", r"亮相", r"开售", r"预售",
                 r"新品", r"新机", r"上市", r"\blaunch", r"\breleas", r"\bupdate", r"feature",
                 r"subscription", r"\bapp\b", r"plugin", r"rolls? out"]),
    ("research", [r"论文", r"研究", r"实验", r"数据集", r"基准测试",
                  r"\bstudy\b", r"\bresearch", r"\bpaper\b", r"experiment",
                  r"breakthrough", r"dataset"]),
    ("policy", [r"监管", r"法规", r"法案", r"版权", r"诉讼", r"隐私", r"禁令",
                r"合规", r"安全", r"反垄断", r"lawsuit", r"\bsues?\b", r"copyright",
                r"regulat", r"antitrust", r"\bban\b", r"privacy", r"policy"]),
]

TAG_RULES = [
    ("OpenAI", [r"OpenAI"]),
    ("ChatGPT", [r"ChatGPT"]),
    ("Anthropic", [r"Anthropic"]),
    ("Claude", [r"Claude"]),
    ("Google", [r"Google", r"谷歌"]),
    ("Gemini", [r"Gemini", r"DeepMind"]),
    ("NVIDIA", [r"NVIDIA", r"英伟达", r"黄仁勋", r"Jensen Huang"]),
    ("Microsoft", [r"Microsoft", r"微软"]),
    ("GitHub", [r"GitHub"]),
    ("Cursor", [r"Cursor"]),
    ("Qoder", [r"Qoder"]),
    ("阿里", [r"阿里", r"Alibaba", r"通义"]),
    ("Perplexity", [r"Perplexity"]),
    ("Meta", [r"Meta", r"Llama"]),
    ("Apple", [r"Apple", r"苹果"]),
    ("字节", [r"字节", r"ByteDance", r"豆包"]),
    ("DeepSeek", [r"DeepSeek", r"深度求索"]),
    ("月之暗面", [r"月之暗面", r"Kimi"]),
    ("智谱", [r"智谱", r"\bGLM\b"]),
    ("MiniMax", [r"MiniMax"]),
    ("xAI", [r"xAI", r"Grok"]),
    ("机器人", [r"机器人", r"具身智能"]),
    ("自动驾驶", [r"自动驾驶", r"Robotaxi"]),
    ("大模型", [r"大模型", r"\bLLM\b"]),
    ("算力", [r"算力", r"\bGPU\b", r"数据中心"]),
    ("融资", [r"融资", r"估值", r"收购"]),
    ("监管", [r"监管", r"法规", r"诉讼"]),
]

TOOL_RULES = [
    ("chatgpt", [r"OpenAI", r"ChatGPT", r"Sora"]),
    ("claude", [r"Anthropic", r"Claude"]),
    ("claude-code", [r"Claude Code", r"Claude.{0,12}(代码|编程|Code)", r"Anthropic.{0,20}(代码|编程|Code)"]),
    ("gemini", [r"Google", r"谷歌", r"Gemini", r"DeepMind"]),
    ("cursor", [r"Cursor"]),
    ("copilot", [r"GitHub", r"Copilot"]),
    ("qoder", [r"Qoder", r"通义.{0,12}(代码|编程)", r"阿里.{0,20}(代码|编程)"]),
    ("perplexity", [r"Perplexity"]),
]


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def clean_text(value: str) -> str:
    if not value:
        return ""
    value = re.sub(r"<script\b.*?</script>", " ", value, flags=re.S | re.I)
    value = re.sub(r"<style\b.*?</style>", " ", value, flags=re.S | re.I)
    value = re.sub(r"<[^>]+>", " ", value)
    value = html.unescape(value)
    return re.sub(r"\s+", " ", value).strip()


def truncate(value: str, limit: int) -> str:
    value = value.strip()
    if len(value) <= limit:
        return value
    cut = value[:limit]
    for sep in ("。", "！", "？", ". ", "! ", "? "):
        index = cut.rfind(sep)
        if index > limit * 0.55:
            return cut[: index + len(sep)].strip()
    return cut.rstrip("，,；;：:、 ") + "…"


def child_text(element: ET.Element, names: set[str]) -> str:
    for child in element:
        if local_name(child.tag) in names:
            text = "".join(child.itertext()).strip()
            if text:
                return text
    return ""


def item_link(element: ET.Element) -> str:
    for child in element:
        if local_name(child.tag) != "link":
            continue
        href = (child.get("href") or "").strip()
        if href:
            return href
        text = "".join(child.itertext()).strip()
        if text:
            return text
    return ""


def parse_date(value: str) -> Optional[datetime]:
    if not value:
        return None
    try:
        dt = parsedate_to_datetime(value)
    except (TypeError, ValueError, OverflowError):
        try:
            dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc).replace(tzinfo=None)


def fetch(url: str, timeout: int = 20) -> bytes:
    request = Request(
        url,
        headers={
            "User-Agent": UA,
            "Accept": "application/rss+xml, application/atom+xml, application/xml, text/xml, */*",
        },
    )
    with urlopen(request, timeout=timeout) as response:
        payload = response.read(MAX_FEED_BYTES + 1)
    if len(payload) > MAX_FEED_BYTES:
        raise ValueError("feed exceeds size limit")
    head = payload[:8192].upper()
    if b"<!DOCTYPE" in head or b"<!ENTITY" in head:
        raise ValueError("DTD/entity declarations are not allowed")
    return payload


def parse_feed(payload: bytes, source_url: str, limit: int) -> list[dict]:
    root = ET.fromstring(payload)
    items = [element for element in root.iter() if local_name(element.tag) in {"item", "entry"}]
    parsed = []
    for element in items[:limit]:
        title = clean_text(child_text(element, {"title"}))
        link = item_link(element)
        if not title or not link:
            continue
        link = urljoin(source_url, link)
        summary = clean_text(child_text(element, {"description", "summary", "encoded"}))
        guid = child_text(element, {"guid", "id"}) or link
        date_text = child_text(element, {"pubDate", "published", "updated", "date"})
        published = parse_date(date_text)
        parsed.append({
            "title": truncate(title, 300),
            "link": link,
            "summary": truncate(summary, 320),
            "guid": guid[:500],
            "published": published,
        })
    return parsed


def matches_any(patterns: list[str], text: str) -> bool:
    return any(re.search(pattern, text, re.I) for pattern in patterns)


def classify(title: str, summary: str) -> str:
    text = title + " " + summary
    # Personal-life stories are filed under people even when the person is a chip CEO.
    if re.search(r"婚外情|出轨|私生活|personal relationship|romantic relationship|personal life", text, re.I):
        return "people"
    # Match the headline first: summaries often mention investors or funding
    # in passing and would otherwise pull unrelated stories into "funding".
    for category, patterns in CATEGORY_RULES:
        if matches_any(patterns, title):
            return category
    for category, patterns in CATEGORY_RULES:
        if matches_any(patterns, text):
            return category
    return "industry"


def tags_for(title: str, summary: str) -> list[str]:
    text = title + " " + summary
    tags = []
    for tag, patterns in TAG_RULES:
        if matches_any(patterns, text):
            tags.append(tag)
        if len(tags) >= 6:
            break
    return tags


def tools_for(title: str, summary: str) -> list[str]:
    text = title + " " + summary
    tools = []
    for slug, patterns in TOOL_RULES:
        if matches_any(patterns, text):
            tools.append(slug)
        if len(tools) >= 4:
            break
    return tools


def normalize_title(title: str) -> str:
    return re.sub(r"[\W_]+", "", title.lower(), flags=re.UNICODE)


def open_db(path: Path) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(str(path), timeout=10)
    connection.execute("PRAGMA journal_mode = WAL")
    connection.execute("PRAGMA busy_timeout = 5000")
    if SCHEMA_FILE.is_file():
        connection.executescript(SCHEMA_FILE.read_text(encoding="utf-8"))
    else:
        connection.executescript(
            """
            CREATE TABLE IF NOT EXISTS ai_news (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              guid TEXT NOT NULL UNIQUE,
              title TEXT NOT NULL,
              summary TEXT NOT NULL DEFAULT '',
              source_name TEXT NOT NULL DEFAULT '',
              source_url TEXT NOT NULL DEFAULT '',
              published_at TEXT NOT NULL DEFAULT '',
              fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
              category TEXT NOT NULL DEFAULT 'industry',
              tags TEXT NOT NULL DEFAULT '[]',
              related_tools TEXT NOT NULL DEFAULT '[]',
              importance INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'published',
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_ai_news_published ON ai_news(status, published_at DESC);
            CREATE INDEX IF NOT EXISTS idx_ai_news_category ON ai_news(category, published_at DESC);
            """
        )
    return connection


def main() -> int:
    parser = argparse.ArgumentParser(description="Collect AI news from public RSS feeds.")
    parser.add_argument("--limit", type=int, default=20, help="max items per source")
    parser.add_argument("--db", default=os.environ.get("PROXY_DB", str(DEFAULT_DB)))
    parser.add_argument("--source", default="", help="only collect sources whose name contains this text")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--prune-days", type=int, default=90)
    args = parser.parse_args()

    if not SOURCES_FILE.is_file():
        print(f"sources file not found: {SOURCES_FILE}", file=sys.stderr)
        return 1
    payload = json.loads(SOURCES_FILE.read_text(encoding="utf-8"))
    sources = payload.get("sources", [])
    sources.sort(key=lambda item: int(item.get("weight", 0)), reverse=True)
    if args.source:
        needle = args.source.lower()
        sources = [item for item in sources if needle in item.get("name", "").lower()]
    if not sources:
        print("no matching sources", file=sys.stderr)
        return 1

    db_path = Path(args.db).expanduser().resolve()
    connection = open_db(db_path)
    insert = connection.cursor()
    cutoff = datetime.now(timezone.utc).replace(tzinfo=None) - timedelta(days=14)
    seen_titles: set[str] = set()
    total_kept = 0
    total_new = 0

    for source in sources:
        name = str(source.get("name", "")).strip() or "unknown"
        url = str(source.get("url", "")).strip()
        weight = int(source.get("weight", 5))
        ai_only = bool(source.get("ai_only"))
        if not url:
            continue
        try:
            raw = fetch(url)
            entries = parse_feed(raw, url, max(1, args.limit))
        except (HTTPError, URLError, TimeoutError, ET.ParseError, OSError, ValueError) as error:
            print(f"[skip] {name}: {error}", file=sys.stderr)
            continue

        kept = 0
        inserted = 0
        for entry in entries:
            published = entry.get("published")
            if published is None:
                published = datetime.now(timezone.utc).replace(tzinfo=None)
            if published < cutoff:
                continue

            title = entry["title"]
            summary = entry["summary"]
            # General feeds: require an AI signal in the headline. Matching only
            # the summary lets phone launches and funding stories slip through.
            if not ai_only and not matches_any(AI_KEYWORDS, title):
                continue

            title_key = normalize_title(title)
            if not title_key or title_key in seen_titles:
                continue

            category = classify(title, summary)
            tags = tags_for(title, summary)
            tools = tools_for(title, summary)
            importance = weight * 10
            if tools:
                importance += 18
            if category in {"chip", "model"}:
                importance += 10
            if (datetime.now(timezone.utc).replace(tzinfo=None) - published) <= timedelta(days=1):
                importance += 12

            row = {
                "guid": entry["guid"] or hashlib.sha1(entry["link"].encode("utf-8")).hexdigest(),
                "title": title,
                "summary": summary,
                "source_name": name,
                "source_url": entry["link"],
                "published_at": published.strftime("%Y-%m-%d %H:%M:%S"),
                "category": category,
                "tags": json.dumps(tags, ensure_ascii=False),
                "related_tools": json.dumps(tools, ensure_ascii=False),
                "importance": importance,
            }

            seen_titles.add(title_key)
            kept += 1
            if args.dry_run:
                print(f"{row['published_at']} [{name}] {title}")
                continue

            before = connection.total_changes
            insert.execute(
                """
                INSERT INTO ai_news
                  (guid, title, summary, source_name, source_url, published_at,
                   fetched_at, category, tags, related_tools, importance, status)
                VALUES
                  (:guid, :title, :summary, :source_name, :source_url, :published_at,
                   CURRENT_TIMESTAMP, :category, :tags, :related_tools, :importance, 'published')
                ON CONFLICT(guid) DO UPDATE SET
                  title = excluded.title,
                  summary = excluded.summary,
                  source_name = excluded.source_name,
                  source_url = excluded.source_url,
                  published_at = excluded.published_at,
                  fetched_at = CURRENT_TIMESTAMP,
                  category = excluded.category,
                  tags = excluded.tags,
                  related_tools = excluded.related_tools,
                  importance = excluded.importance
                """,
                row,
            )
            if connection.total_changes > before:
                inserted += 1

        connection.commit()
        total_kept += kept
        total_new += inserted
        print(f"[ok] {name}: {len(entries)} fetched, {kept} kept, {inserted} inserted/updated")

    if not args.dry_run:
        connection.execute(
            "DELETE FROM ai_news WHERE published_at < datetime('now', ?)",
            (f"-{max(1, args.prune_days)} day",),
        )
        connection.execute(
            "DELETE FROM ai_news WHERE id NOT IN (SELECT id FROM ai_news ORDER BY published_at DESC, id DESC LIMIT 500)"
        )
        connection.commit()
        count = connection.execute("SELECT COUNT(*) FROM ai_news").fetchone()[0]
        print(f"done: {total_new} inserted/updated, {total_kept} kept, {count} rows in database")
    else:
        print(f"dry run: {total_kept} items would be kept")

    connection.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
