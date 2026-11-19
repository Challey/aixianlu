"""Default AI-related targets.

These are intentionally conservative:
- For unauthenticated probes, 401/403 are listed in ``expected_status``: they prove
  the endpoint answered correctly from a network without a key, NOT that any
  account works. Set ``auth_env`` and the target expects 200 only.
- Only public, well-known endpoints are included.
- Model-level targets send a minimal request body naming a specific model.
  Without an API key they only prove the endpoint is reachable and reveal the
  error class (auth / region block / rate limit). Set the matching environment
  variable (``auth_env``) to run a real model-level check with your own key.
  Keys are read at run time and never written to reports.
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
        model="gpt-4o-mini",
        body={
            "model": "gpt-4o-mini",
            "messages": [{"role": "user", "content": "ping"}],
            "max_tokens": 1,
        },
        auth_env="OPENAI_API_KEY",
    ),
    Target(
        name="openai-embeddings",
        url="https://api.openai.com/v1/embeddings",
        method="POST",
        headers={"Content-Type": "application/json"},
        expected_status=[200, 401, 403, 429],
        category="ai",
        model="text-embedding-3-small",
        body={"model": "text-embedding-3-small", "input": "ping"},
        auth_env="OPENAI_API_KEY",
    ),
    Target(
        name="claude-api",
        url="https://api.anthropic.com/v1/messages",
        method="POST",
        headers={"Content-Type": "application/json", "anthropic-version": "2023-06-01"},
        expected_status=[200, 401, 403, 429],
        category="ai",
        model="claude-sonnet-4-5",
        body={
            "model": "claude-sonnet-4-5",
            "max_tokens": 1,
            "messages": [{"role": "user", "content": "ping"}],
        },
        auth_env="ANTHROPIC_API_KEY",
        auth_header="x-api-key",
        auth_scheme="",
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
        name="gemini-flash",
        url="https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent",
        method="POST",
        headers={"Content-Type": "application/json"},
        expected_status=[200, 400, 401, 403, 429],
        category="ai",
        model="gemini-2.0-flash",
        body={"contents": [{"parts": [{"text": "ping"}]}]},
        auth_env="GEMINI_API_KEY",
        auth_header="x-goog-api-key",
        auth_scheme="",
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
