#!/usr/bin/env bash
# Daily pipeline: collect AI news -> rebuild static site -> health check -> ping search engines.
# Install with:
#   0 7 * * * cd /path/to/apps/proxy-atlas && ./bin/daily.sh >> storage/logs/cron.log 2>&1
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mkdir -p storage/logs
exec 9>"storage/logs/.daily.lock"
if ! flock -n 9; then
  echo "[$(date -Is)] another daily run is active; skip"
  exit 0
fi

LOG="storage/logs/daily-$(date +%Y-%m).log"
{
  echo "=== $(date -Is) daily start"
  "${PYTHON_BIN:-python3}" bin/collect_news.py "$@"
  "${PHP_BIN:-php}" bin/build.php
  "${PHP_BIN:-php}" bin/health.php || true
  "${PHP_BIN:-php}" bin/ping.php || true
  echo "=== $(date -Is) daily done"
} >> "$LOG" 2>&1
