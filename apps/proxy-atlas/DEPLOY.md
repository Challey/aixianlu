# 海外部署与自动化运行

目标形态：**一台海外 VPS 跑完整套**——Nginx 静态站、PHP 后台、SQLite、Python 采集器、每日构建与搜索引擎推送。不依赖共享主机，不依赖国内备案。

```
用户 / 搜索引擎
      │
      ▼
Nginx (443, 静态 HTML + PHP 入口)
      ├── public/*.html          直接返回，无数据库查询
      ├── /go/{slug}             PHP 302 + 点击记录
      ├── /admin/                PHP 后台（建议加 IP 白名单或 Basic Auth）
      └── /api/agent-apply.php   代理申请
      │
      ▼
storage/proxy-atlas.sqlite     SQLite WAL
      ▲
      │
cron 07:00  bin/daily.sh        采集新闻 → 构建 → 健康检查 → IndexNow/百度推送
```

## 1. 主机怎么选

这套站的资源需求很低：2 vCPU / 2 GB 内存 / 40 GB SSD 足够；真正影响体验的是**中国大陆到机房的线路**，不是 CPU。

| 方案 | 适合 | 月成本（约） | 说明 |
|---|---|---|---|
| **DMIT 香港/日本（CN2 GIA / CMI）** | 首选，国内访问优先 | $15–30 | 线路质量最好，延迟低、丢包少；库存波动，需蹲补货 |
| **搬瓦工 CN2 GIA-E** | 国内访问优先 | $50–80/年 | 老牌 CN2 GIA，性价比高；配置偏小，需要控制采集频率 |
| **Vultr 东京/新加坡** | 平衡之选 | $6–12 | 开通快、按小时计费、可换 IP；普通国际线路，晚高峰可能抖动 |
| **DigitalOcean 新加坡 / Linode 东京** | 平衡之选 | $6–12 | 稳定、文档全；同样不是中国优化线路 |
| **RackNerd 美国** | 极限预算 | $20–30/年 | 便宜、流量大，但国内延迟 150–250ms，适合先跑起来 |
| **Hetzner 德国/芬兰** | 后台/采集/备份 | €4–8 | 性能和价格极好，但国内访问延迟高，不建议做主站 |

不建议：

- **共享虚拟主机**：经常没有 Python 3、cron 受限、SQLite WAL 支持差，采集和构建会很难受。
- **国内阿里云/腾讯云跑公网主站**：这类内容备案和合规风险高；没有 ICP 备案也不能用国内节点对外提供 Web 服务。
- **把采集放国内、站点放国外**：要跨服务器同步 SQLite，容易产生锁冲突和版本不一致，收益很小。

建议先买一台**香港或日本**的 2C2G VPS，装 Ubuntu 24.04。购买前用 `ping` / `mtr` 从电信、联通、移动各测一次；优先选支持小时计费或 3–7 天退款的服务商，跑一周再决定是否长期。

## 2. 初始化服务器

```bash
# 以 root 登录后创建部署用户
adduser deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy/

# 切到 deploy，安装依赖（Ubuntu 24.04 自带 PHP 8.3）
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-curl \
  python3 python3-pip sqlite3 git unzip fail2ban ufw certbot python3-certbot-nginx
```

如果发行版没有 PHP 8.3，加 `ondrej/php` PPA 后安装 `php8.3-*`。

把代码放到 `/var/www/aixianlu`（`public/` 是 Web 根目录）：

```bash
sudo mkdir -p /var/www/aixianlu
sudo chown deploy:deploy /var/www/aixianlu
# 本机执行：
rsync -avz --delete --exclude storage --exclude .env \
  apps/proxy-atlas/ deploy@SERVER:/var/www/aixianlu/
```

服务器上：

```bash
cd /var/www/aixianlu
cp .env.example .env
nano .env
```

`.env` 至少设置：

```ini
PROXY_SITE_URL=https://aixianlu.com
PROXY_TZ=Asia/Shanghai
PROXY_IP_SALT=用 openssl rand -hex 32 生成
PROXY_ADMIN_USER=admin
PROXY_ADMIN_PASSWORD=一个至少 16 位的强密码
PROXY_DB=/var/lib/aixianlu/proxy-atlas.sqlite
INDEXNOW_KEY=用 openssl rand -hex 16 生成
# BAIDU_PUSH_TOKEN=百度搜索资源平台推送 token
POSTBACK_TOKEN=服务器回传佣金用的随机 token
ALERT_WEBHOOK_URL=健康检查异常时推送的 webhook
# 探针上报（开源 probes/，协议 v0.2.0）
PROBE_MIN_INTERVAL_SECONDS=60
PROBE_HOURLY_LIMIT=120
PROBE_TIMESTAMP_SKEW_SECONDS=300
PROBE_MAX_RESULTS=200
PROBE_MAX_BODY_BYTES=262144
# 仅在迁移 0.1.x 旧探针时临时设为 1，稳定后请改回 0
PROBE_ALLOW_UNSIGNED=0
```

```bash
sudo mkdir -p /var/lib/aixianlu
sudo chown www-data:www-data /var/lib/aixianlu
sudo chmod 600 .env
sudo chown -R deploy:www-data /var/www/aixianlu
sudo chmod -R g+w storage
php bin/import.php
php bin/build.php
```

> `PROXY_DB` 指到 Web 根目录之外，避免 SQLite 文件被下载。之后所有 `php` 命令和 cron 都必须在同一个 `.env` 下运行，采集器会自动读取 `PROXY_DB`。

首次登录 `/admin/` 后立刻改密码，并在“站点设置”确认 `site_url`、站点名称、合作邮箱、推广声明。

## 3. Nginx 配置

`/etc/nginx/sites-available/aixianlu`：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name aixianlu.com www.aixianlu.com;

    root /var/www/aixianlu/public;
    index index.html;

    # 静态站：文件、目录、404
    location / {
        try_files $uri $uri/ =404;
    }

    # 推广跳转：/go/{slug}
    location ~ ^/go/([a-z0-9-]+)/?$ {
        rewrite ^/go/([a-z0-9-]+)/?$ /go.php last;
    }

    location = /go { return 302 /providers/; }

    # 探针接口：版本化路径 → PHP 入口
    location = /api/v1/metrics/report { rewrite ^ /api/metrics-report.php last; }
    location = /api/v1/metrics/latest { rewrite ^ /api/metrics-latest.php last; }

    # PHP 只在这些入口执行
    location ~ ^/(admin|api)/.*\.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /go.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 后台加一层保护：二选一
    # 1) IP 白名单
    # location ^~ /admin/ {
    #     allow 203.0.113.10;
    #     deny all;
    #     try_files $uri $uri/ =404;
    # }
    # 2) Basic Auth（更通用）
    # location ^~ /admin/ {
    #     auth_basic "AI 线路 admin";
    #     auth_basic_user_file /etc/nginx/.htpasswd;
    #     try_files $uri $uri/ =404;
    # }

    location ~ /\.(env|git|ht) { deny all; }
    location ~* \.(sqlite|sqlite3|db)$ { deny all; }

    client_max_body_size 8m;
    error_page 404 /404.html;
}
```

启用并申请证书：

```bash
sudo ln -s /etc/nginx/sites-available/aixianlu /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d aixianlu.com -d www.aixianlu.com
```

证书自动续期由 certbot 的 systemd timer 负责。

## 4. 防火墙与基础安全

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo systemctl enable --now fail2ban

# 关闭 SSH 密码登录，改用密钥
sudo nano /etc/ssh/sshd_config    # PasswordAuthentication no
sudo systemctl restart ssh
```

- 每个机场后台用**不同密码** + 2FA；不要把机场登录密码写进 `.env`、数据库或仓库。
- 后台 `/admin/` 至少加 Basic Auth 或 IP 白名单。
- 给 `/go/` 做限速（Nginx `limit_req`），防止被刷点击。
- 每天备份 SQLite，见下一节。

## 5. 每日自动化

`bin/daily.sh` 已包含：采集 → 构建 → 健康检查 → 搜索引擎推送。

```bash
chmod +x bin/daily.sh
crontab -e
```

```cron
# 每天 07:00（服务器时区；建议系统时区设为 Asia/Shanghai）
0 7 * * * cd /var/www/aixianlu && ./bin/daily.sh >> storage/logs/cron.log 2>&1
# 每小时轻量健康检查（可选）
17 * * * * cd /var/www/aixianlu && php bin/health.php >> storage/logs/health.log 2>&1
# 每小时聚合探针快照，每天清理 30 天前的明细
5 * * * * cd /var/www/aixianlu && php bin/probe_snapshot.php --hours=6 >> storage/logs/probe.log 2>&1
5 4 * * * cd /var/www/aixianlu && php bin/probe_snapshot.php --hours=48 --prune-days=30 >> storage/logs/probe.log 2>&1
```

日志在 `storage/logs/daily-YYYY-MM.log`。用 logrotate 保留 30 天：

```
/var/www/aixianlu/storage/logs/*.log {
    daily
    rotate 30
    compress
    missingok
    notifempty
}
```

### 搜索引擎提交

- `INDEXNOW_KEY` 设置后，`bin/ping.php` 会自动生成 `https://aixianlu.com/<key>.txt` 并向 IndexNow（Bing/Yandex）提交 sitemap 全部 URL。
- 百度：在搜索资源平台验证站点，拿到推送 token 填入 `BAIDU_PUSH_TOKEN`，脚本会调用百度普通收录 API。
- Google 已停用 sitemap ping；在 Search Console 提交 `https://aixianlu.com/sitemap.xml`，之后靠 sitemap、内链和正常抓取发现新页。
- 三个平台都提交一次后，日常不需要人工操作。

### 探针数据管道（开源 `probes/`）

接收端随站点一起跑在 PHP-FPM 上，无需额外服务；协议与限制见 `probes/PROTOCOL.md` 与 `README.md` 的「探针数据接收」。

```bash
# 发放第一个官方探针 token（明文只打印一次）
php bin/probe_token.php issue --label="官方-新加坡-VPS"
php bin/probe_token.php list

# 立刻验证接收链路（服务端与探针同机时最直接）
curl -s "https://aixianlu.com/api/v1/metrics/latest?hours=24" | head -c 300

# 聚合一次，确认快照写入
php bin/probe_snapshot.php --hours=48
```

要点：

- Nginx 需保留上面两条 `/api/v1/metrics/*` rewrite；Nginx 默认会把 `Authorization` 头透传给 PHP-FPM，Apache 需 `CGIPassAuth On`，否则探针可改用等价的 `X-Probe-Token` 头。
- 上报是写操作，`storage/`（或 `PROXY_DB` 所在目录）必须对 `www-data` 可写；SQLite 已开 WAL，与构建任务并发安全。
- 若走 Cloudflare，把 `/api/v1/metrics/report` 设为不缓存、跳过速率限制之外的 WAF 挑战（否则探针会被 JS challenge 挡住返回 403）；`/api/v1/metrics/latest` 可保留 60 秒边缘缓存。
- 后台 `/admin/probes.php` 看质量快照与最近上报；token 撤销即刻生效（`403`）。
- 前台 `/status/` 是静态烘焙的：数字新鲜度取决于「快照聚合 + `bin/build.php`」的频率，页面右上角显示的就是烘焙时间；访客点「刷新」或切换筛选会直接走 `/api/v1/metrics/latest`，因此不必为探针单独提高构建频率。若希望静态部分也逐小时更新，可把聚合任务改成 `php bin/probe_snapshot.php --hours=6 && php bin/build.php`。
- 公开 API 只返回聚合值，不含探针 ID、IP 与原始载荷；`/api/v1/metrics/latest` 一并给出 `summary`（综合稳定度）、`matrix`（端点 × 运营商）、`scoring`（公式与阈值），前台只做展示、不再二次计算。对外展示时按 `samples` 与 `hours_covered` 标注样本量与时间窗，并保留 A/B/C/D 阈值说明。

## 6. 备份与恢复

```bash
# /usr/local/bin/aixianlu-backup.sh
#!/usr/bin/env bash
set -euo pipefail
DB="${PROXY_DB:-/var/lib/aixianlu/proxy-atlas.sqlite}"
DEST=/var/backups/aixianlu
mkdir -p "$DEST"
sqlite3 "$DB" ".backup '$DEST/proxy-atlas-$(date +%F).sqlite'"
tar -czf "$DEST/site-$(date +%F).tar.gz" -C /var/www/aixianlu public storage/logs 2>/dev/null || true
find "$DEST" -type f -mtime +14 -delete
```

```cron
30 3 * * * PROXY_DB=/var/lib/aixianlu/proxy-atlas.sqlite /usr/local/bin/aixianlu-backup.sh
```

再把 `/var/backups/aixianlu` 用 `rclone` 同步到 Backblaze B2、Cloudflare R2 或另一台机器。恢复时停 PHP-FPM，替换数据库，再 `php bin/build.php`。

## 7. 中国访问与 CDN

- 海外主机不等于国内一定打得开。上线前用电信、联通、移动各测一次首页和 `/go/`。
- 如果 IP 被墙或晚高峰严重丢包，优先换机房/IP，而不是盲目上 CDN。
- Cloudflare 免费版对国内不一定更快；可以开，但要用“Full (strict)”，并让 `/admin/`、`/go/` 绕过缓存。上线后对比直连与代理的 TTFB，再决定是否长期开启。
- 备用域名 `jichangditu.com` 可以做 301：所有路径 301 到 `https://aixianlu.com` 对应路径。它能接住品牌词和输入错误，但**新域名没有历史外链，SEO 增益接近 0**，主要是防御性和品牌保护。不要在两个域名上跑重复内容。

备用域名 301 的 Nginx 写法：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name jichangditu.com www.jichangditu.com;
    return 301 https://aixianlu.com$request_uri;
}

server {
    listen 443 ssl http2;
    server_name jichangditu.com www.jichangditu.com;
    # 复用主域证书，或单独签发
    ssl_certificate     /etc/letsencrypt/live/aixianlu.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/aixianlu.com/privkey.pem;
    return 301 https://aixianlu.com$request_uri;
}
```

## 8. 能做到多自动，哪些仍需人工

可以自动：

- 每天采集 AI 新闻、分类、去重、过期清理；
- 重建静态页、sitemap、RSS、结构化数据；
- IndexNow/百度推送；
- 推广链接跳转、点击记录、设备/来源统计；
- 佣金 CSV 导入后的汇总与待结算提醒；
- 健康检查（官网缺失、新闻过旧、构建过期）。

仍需人工：

- 各机场联盟的申请、审核、结算与提现（多数没有统一 API）；
- 推广链接/邀请码的首次录入（登录各机场后台复制，一次性工作）；
- 内容质量与合规复核、品牌合作、外链建设；
- 被封 IP、被投诉、支付通道变化等异常处理。

SEO 排名和佣金收入没有“开关式自动化”。这套流水线把重复劳动降到每天几分钟，但收入仍取决于内容质量、收录速度、转化率和各机场的结算政策，不能承诺具体收益。
