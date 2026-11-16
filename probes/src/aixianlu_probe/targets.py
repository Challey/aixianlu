"""Default AI-related targets.

These are intentionally conservative:
- We treat 401/403 as "reachable" for authenticated APIs.
- Only public, well-known endpoints are included.
"""

from __future__ import annotations

from aixianlu_probe.models import Target

DEFAULT_TARGETS: list[Target] = [
    Target(
        name="openai-api",
        url="https://api.openai.com/v1/models",
        method="GET",
        expected_status=[200, 401, 403],
        category="ai",
    ),
    Target(
        name="openai-chat",
        url="https://api.openai.com/v1/chat/completions",
        method="POST",
        headers={"Content-Type": "application/json"},
        expected_status=[200, 401, 403, 429],
        category="ai",
    ),
    Target(
        name="claude-api",
        url="https://api.anthropic.com/v1/messages",
        method="POST",
        headers={"Content-Type": "application/json", "anthropic-version": "2023-06-01"},
        expected_status=[200, 401, 403, 429],
        category="ai",
    ),
    Target(
        name="claude-web",
        url="https://claude.ai",
        method="GET",
        expected_status=[200, 301, 302, 403],
        category="ai",
    ),
    Target(
        name="gemini-api",
        url="https://generativelanguage.googleapis.com/v1beta/models",
        method="GET",
        expected_status=[200, 400, 401, 403],
        category="ai",
    ),
    Target(
        name="cursor-api",
        url="https://api2.cursor.sh",
        method="GET",
        expected_status=[200, 401, 403, 404],
        category="ai",
    ),
    Target(
        name="github-api",
        url="https://api.github.com",
        method="GET",
        expected_status=[200],
        category="control",
    ),
    Target(
        name="cloudflare",
        url="https://1.1.1.1",
        method="GET",
        expected_status=[200, 301, 302],
        category="control",
    ),
]
