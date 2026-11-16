PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS providers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  name_en TEXT NOT NULL DEFAULT '',
  founded INTEGER,
  summary TEXT NOT NULL DEFAULT '',
  line_type TEXT NOT NULL DEFAULT '未标注',
  protocols TEXT NOT NULL DEFAULT '[]',
  regions TEXT NOT NULL DEFAULT '[]',
  regions_common TEXT NOT NULL DEFAULT '[]',
  regions_other TEXT NOT NULL DEFAULT '[]',
  media_unlock TEXT NOT NULL DEFAULT '[]',
  ai_unlock TEXT NOT NULL DEFAULT '[]',
  features TEXT NOT NULL DEFAULT '[]',
  payments TEXT NOT NULL DEFAULT '[]',
  tags TEXT NOT NULL DEFAULT '[]',
  discount_code TEXT NOT NULL DEFAULT '',
  discount_label TEXT NOT NULL DEFAULT '',
  affiliate_code TEXT NOT NULL DEFAULT '',
  affiliate_status TEXT NOT NULL DEFAULT 'none',
  affiliate_checked_at TEXT NOT NULL DEFAULT '',
  affiliate_note TEXT NOT NULL DEFAULT '',
  monthly_offline INTEGER NOT NULL DEFAULT 0,
  plan_note TEXT NOT NULL DEFAULT '',
  official_url TEXT NOT NULL DEFAULT '',
  affiliate_url TEXT NOT NULL DEFAULT '',
  source_review_url TEXT NOT NULL DEFAULT '',
  source_home_url TEXT NOT NULL DEFAULT '',
  source_updated_at TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'published',
  source_order INTEGER NOT NULL DEFAULT 0,
  weight INTEGER NOT NULL DEFAULT 0,
  region_count INTEGER NOT NULL DEFAULT 0,
  node_count INTEGER NOT NULL DEFAULT 0,
  node_count_disclosed INTEGER NOT NULL DEFAULT 0,
  node_count_note TEXT NOT NULL DEFAULT '',
  plan_count INTEGER NOT NULL DEFAULT 0,
  price_from REAL,
  price_from_period TEXT NOT NULL DEFAULT '',
  price_currency TEXT NOT NULL DEFAULT 'CNY',
  traffic_from REAL,
  unit_price_from REAL,
  monthly_price_from REAL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider_id INTEGER NOT NULL REFERENCES providers(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  option_label TEXT NOT NULL DEFAULT '',
  price REAL NOT NULL,
  currency TEXT NOT NULL DEFAULT 'CNY',
  period TEXT NOT NULL DEFAULT '月付',
  period_months INTEGER NOT NULL DEFAULT 1,
  traffic_gb REAL,
  traffic_period TEXT NOT NULL DEFAULT 'month',
  monthly_price REAL,
  monthly_traffic_gb REAL,
  unit_price REAL,
  speed_limit TEXT NOT NULL DEFAULT '',
  device_limit INTEGER,
  notes TEXT NOT NULL DEFAULT '',
  is_entry INTEGER NOT NULL DEFAULT 0,
  sort INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_plans_provider ON plans(provider_id, sort);
CREATE INDEX IF NOT EXISTS idx_providers_status ON providers(status, weight, id);

CREATE TABLE IF NOT EXISTS agents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  code TEXT NOT NULL UNIQUE,
  tier TEXT NOT NULL DEFAULT 'standard',
  status TEXT NOT NULL DEFAULT 'pending',
  commission_rate REAL NOT NULL DEFAULT 0.1,
  balance REAL NOT NULL DEFAULT 0,
  channel TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  ip_hash TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clicks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider_id INTEGER REFERENCES providers(id) ON DELETE SET NULL,
  plan_id INTEGER REFERENCES plans(id) ON DELETE SET NULL,
  agent_id INTEGER REFERENCES agents(id) ON DELETE SET NULL,
  subid TEXT NOT NULL DEFAULT '',
  campaign TEXT NOT NULL DEFAULT '',
  ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_hash TEXT NOT NULL DEFAULT '',
  ua TEXT NOT NULL DEFAULT '',
  referer TEXT NOT NULL DEFAULT '',
  device TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_clicks_provider_ts ON clicks(provider_id, ts);
CREATE INDEX IF NOT EXISTS idx_clicks_subid ON clicks(subid);

CREATE TABLE IF NOT EXISTS conversions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  agent_id INTEGER REFERENCES agents(id) ON DELETE SET NULL,
  provider_id INTEGER REFERENCES providers(id) ON DELETE SET NULL,
  subid TEXT NOT NULL DEFAULT '',
  amount REAL NOT NULL DEFAULT 0,
  commission REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending',
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_conversions_agent ON conversions(agent_id, created_at);

CREATE TABLE IF NOT EXISTS admin_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS ai_tools (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  vendor TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'chat',
  tagline TEXT NOT NULL DEFAULT '',
  summary TEXT NOT NULL DEFAULT '',
  requirements TEXT NOT NULL DEFAULT '[]',
  regions TEXT NOT NULL DEFAULT '[]',
  network_traits TEXT NOT NULL DEFAULT '[]',
  ip_sensitivity TEXT NOT NULL DEFAULT '',
  account_risk TEXT NOT NULL DEFAULT '',
  official_url TEXT NOT NULL DEFAULT '',
  official_note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'published',
  sort_order INTEGER NOT NULL DEFAULT 0,
  last_verified TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS provider_ai_fit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider_id INTEGER NOT NULL REFERENCES providers(id) ON DELETE CASCADE,
  tool_id INTEGER NOT NULL REFERENCES ai_tools(id) ON DELETE CASCADE,
  evidence_level TEXT NOT NULL DEFAULT 'unknown',
  evidence_note TEXT NOT NULL DEFAULT '',
  source_url TEXT NOT NULL DEFAULT '',
  last_checked TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(provider_id, tool_id)
);

CREATE INDEX IF NOT EXISTS idx_ai_fit_tool ON provider_ai_fit(tool_id, evidence_level);
CREATE INDEX IF NOT EXISTS idx_ai_fit_provider ON provider_ai_fit(provider_id);

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
