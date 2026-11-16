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


class Location(BaseModel):
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
    error_message: Optional[str] = None
    tls_version: Optional[str] = None
    http_version: Optional[str] = None
    redirect_count: int = 0
    response_size: Optional[int] = None
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
    user_agent: str = "aixianlu-probe/0.1.0"
    location: Location = Field(default_factory=Location)
    report_enabled: bool = False
    report_endpoint: str = "https://aixianlu.com/api/v1/metrics/report"
    report_token: str = ""
    report_timeout_seconds: float = 10.0
    targets: list[Target] = Field(default_factory=list)
