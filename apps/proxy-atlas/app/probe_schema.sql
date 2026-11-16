-- Probe ingestion schema (PROTOCOL.md v0.2.0).
-- Executed by ensure_schema() and probe_ensure_schema(); safe to re-run.

CREATE TABLE IF NOT EXISTS probe_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  probe_id TEXT NOT NULL UNIQUE,
  token_hash TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_report_at TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS probe_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  probe_id TEXT NOT NULL,
  protocol_version TEXT NOT NULL DEFAULT '',
  client_ts TEXT NOT NULL DEFAULT '',
  received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  isp TEXT NOT NULL DEFAULT '',
  region TEXT NOT NULL DEFAULT '',
  asn INTEGER,
  country TEXT NOT NULL DEFAULT '',
  client_version TEXT NOT NULL DEFAULT '',
  client_os TEXT NOT NULL DEFAULT '',
  result_count INTEGER NOT NULL DEFAULT 0,
  success_count INTEGER NOT NULL DEFAULT 0,
  reachable_count INTEGER NOT NULL DEFAULT 0,
  signature_ok INTEGER NOT NULL DEFAULT 0,
  ip_hash TEXT NOT NULL DEFAULT '',
  dedupe_key TEXT NOT NULL UNIQUE,
  payload TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_probe_reports_probe_ts ON probe_reports(probe_id, received_at);
CREATE INDEX IF NOT EXISTS idx_probe_reports_received ON probe_reports(received_at);

CREATE TABLE IF NOT EXISTS probe_results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES probe_reports(id) ON DELETE CASCADE,
  probe_id TEXT NOT NULL,
  name TEXT NOT NULL,
  url TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT '',
  model TEXT NOT NULL DEFAULT '',
  method TEXT NOT NULL DEFAULT 'GET',
  success INTEGER NOT NULL DEFAULT 0,
  reachable INTEGER NOT NULL DEFAULT 0,
  status_code INTEGER,
  latency_ms REAL,
  error_type TEXT NOT NULL DEFAULT '',
  error_class TEXT NOT NULL DEFAULT '',
  tls_version TEXT NOT NULL DEFAULT '',
  http_version TEXT NOT NULL DEFAULT '',
  redirect_count INTEGER NOT NULL DEFAULT 0,
  response_size INTEGER,
  attempts INTEGER NOT NULL DEFAULT 1,
  isp TEXT NOT NULL DEFAULT '',
  checked_at TEXT NOT NULL DEFAULT '',
  hour TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_probe_results_hour ON probe_results(hour, name, isp);
CREATE INDEX IF NOT EXISTS idx_probe_results_report ON probe_results(report_id);

CREATE TABLE IF NOT EXISTS probe_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  hour TEXT NOT NULL,
  name TEXT NOT NULL,
  model TEXT NOT NULL DEFAULT '',
  isp TEXT NOT NULL DEFAULT '',
  samples INTEGER NOT NULL DEFAULT 0,
  success_count INTEGER NOT NULL DEFAULT 0,
  reachable_count INTEGER NOT NULL DEFAULT 0,
  avg_latency_ms REAL,
  p50_latency_ms REAL,
  p95_latency_ms REAL,
  region_block_count INTEGER NOT NULL DEFAULT 0,
  rate_limit_count INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(hour, name, model, isp)
);

CREATE INDEX IF NOT EXISTS idx_probe_snapshots_hour ON probe_snapshots(hour);
