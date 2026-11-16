#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${PORT:-8080}"
export PROXY_SITE_URL="${PROXY_SITE_URL:-http://127.0.0.1:${PORT}}"

if [ ! -f storage/proxy-atlas.sqlite ]; then
  echo "→ 初始化数据库并导入种子数据"
  php bin/import.php
fi

php bin/build.php

echo "→ 本地预览: http://127.0.0.1:${PORT}/"
echo "→ 管理后台: http://127.0.0.1:${PORT}/admin/"
echo "→ 默认账号: admin / admin（首次登录后请修改）"
exec php -S "127.0.0.1:${PORT}" -t public public/router.php
