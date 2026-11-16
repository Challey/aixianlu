"""
Data models.

Design notes for future Go port:
- All fields use simple types that map 1:1 to Go structs + json tags.
- No inheritance, no complex generics.
- Enums are plain strings with documented allowed values.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Optional

from pydantic import BaseModel, Field

# Transport-level error types (closed enum, see PROTOCOL.md).
ERROR_TYPES = ("timeout", "dns", "tls", "connect", "http", "other")

# Application-level error classes (closed enum, see PROTOCOL.md).
ERROR_CLASSES = (
    "region_block",
    "rate_limit",
    "auth_required",
    "forbidden",
    "not_found",
    "bad_request",
    "server_error",
    "http_error",
)


class Location(BaseModel):
    """Reporter location. Never contains an IP address."""

    isp: str = ""
    region: str = ""
    asn: Optional[int] = None
    country: str = "CN"


class Target(BaseModel):
    name: str
    url: str
    method: str = "GET"
    headers: dict[str, str] = Field(default_factory=dict)
    expected_status: list[int] = Field(default_factory=lambda: [200])
    category: str = "ai"
    timeout_seconds: Optional[float] = None  # override global
    retries: Optional[int] = None  # override global
    model: Optional[str] = None  # model-level endpoint label
    body: Optional[dict[str, Any]] = None  # JSON body for POST targets
    # Optional credentials read from an environment variable at run time.
    # The value is never written to reports or logs.
    auth_env: Optional[str] = None
    auth_header: str = "Authorization"
    auth_scheme: str = "Bearer"


class ProbeResult(BaseModel):
    name: str
    url: str
    category: str = "ai"
    method: str = "GET"
    success: bool
    reachable: bool
    status_code: Optional[int] = None
    latency_ms: Optional[float] = None
    error_type: Optional[str] = None  # timeout | dns | tls | connect | http | other
    error_class: Optional[str] = None  # region_block | rate_limit | auth_required | ...
    error_message: Optional[str] = None
    tls_version: Optional[str] = None
    http_version: Optional[str] = None
    redirect_count: int = 0
    response_size: Optional[int] = None
    attempts: int = 1
    model: Optional[str] = None
    checked_at: datetime


class ClientInfo(BaseModel):
    version: str
    language: str = "python"
    os: str = ""


class ReportPayload(BaseModel):
    protocol_version: str
    probe_id: str
    timestamp: datetime
    client: ClientInfo
    location: Location
    results: list[ProbeResult]
    signature: Optional[str] = None

    def to_report_dict(self) -> dict[str, Any]:
        """Canonical dict for JSON serialization (used by both local save and upload)."""
        return self.model_dump(mode="json")


class ProbeConfig(BaseModel):
    probe_id: str = ""
    timeout_seconds: float = 12.0
    concurrency: int = 6
    retries: int = 1
    retry_backoff_seconds: float = 0.5
    user_agent: str = "aixianlu-probe/0.2.0"
    location: Location = Field(default_factory=Location)
    auto_detect_location: bool = False
    geo_timeout_seconds: float = 5.0
    report_enabled: bool = False
    report_endpoint: str = "https://aixianlu.com/api/v1/metrics/report"
    report_token: str = ""
    report_timeout_seconds: float = 10.0
    report_min_interval_seconds: float = 60.0
    report_state_path: Optional[str] = None
    targets: list[Target] = Field(default_factory=list)
