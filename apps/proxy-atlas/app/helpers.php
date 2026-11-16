<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_col(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function json_arr(?string $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function format_number(float|int|null $value, int $decimals = 0): string
{
    if ($value === null) {
        return '—';
    }
    $rounded = round((float) $value, $decimals);
    if ($decimals === 0) {
        return number_format($rounded, 0, '.', ',');
    }
    return rtrim(rtrim(number_format($rounded, $decimals, '.', ','), '0'), '.');
}

function currency_symbol(string $currency): string
{
    return $currency === 'USD' ? '$' : '￥';
}

function format_price(float|int|null $price, string $currency = 'CNY'): string
{
    if ($price === null) {
        return '—';
    }
    return currency_symbol($currency) . format_number($price, 2);
}

function format_traffic(?float $gb): string
{
    if ($gb === null) {
        return '—';
    }
    if ($gb >= 1024) {
        return format_number($gb / 1024, 2) . ' TB';
    }
    return format_number($gb, 0) . ' GB';
}

function format_unit_price(?float $unit, string $currency = 'CNY'): string
{
    if ($unit === null) {
        return '—';
    }
    return currency_symbol($currency) . format_number($unit, 3) . '/GB';
}

function period_short(string $period): string
{
    return match ($period) {
        '月付' => '月',
        '季付' => '季',
        '半年付' => '半年',
        '年付' => '年',
        '' => '',
        default => $period,
    };
}

function provider_price_label(array $p): string
{
    $currency = $p['price_currency'] ?? 'CNY';
    $price = $p['price_from'] ?? null;
    if ($price === null) {
        return '待补录';
    }
    $period = period_short((string) ($p['price_from_period'] ?? ''));
    return format_price($price, $currency) . ($period !== '' ? '/' . $period : '');
}

function node_count_label(array $p): string
{
    $count = (int) ($p['node_count'] ?? 0);
    return $count > 0 ? $count . '+' : '—';
}

function node_count_title(array $p): string
{
    $count = (int) ($p['node_count'] ?? 0);
    if ($count <= 0) {
        return '来源未提供节点数量';
    }
    $note = trim((string) ($p['node_count_note'] ?? ''));
    if ($note !== '') {
        return $note;
    }
    if (!empty($p['node_count_disclosed'])) {
        return '来源明确披露至少 ' . $count . ' 个节点';
    }
    return '来源列出 ' . $count . ' 个服务器地区；按每地区至少 1 个独立 IP 计，实际可能更多';
}

/**
 * Per-provider checkout deep-link templates, stored as a JSON map in settings
 * (slug => URL with {invite} / {coupon} placeholders). No schema change.
 */
function checkout_templates(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $decoded = json_decode((string) setting('checkout_templates', ''), true);
    $cache = [];
    if (is_array($decoded)) {
        foreach ($decoded as $slug => $template) {
            if (is_string($slug) && is_string($template) && trim($template) !== '') {
                $cache[$slug] = trim($template);
            }
        }
    }
    return $cache;
}

function provider_checkout_template(array $p): string
{
    $templates = checkout_templates();
    $template = $templates[(string) ($p['slug'] ?? '')] ?? '';
    if ($template === '') {
        return '';
    }
    $invite = trim((string) ($p['affiliate_code'] ?? ''));
    $coupon = trim((string) ($p['discount_code'] ?? ''));
    if (str_contains($template, '{invite}') && $invite === '') {
        return '';
    }
    if (str_contains($template, '{coupon}') && $coupon === '') {
        return '';
    }
    $rendered = str_replace(
        ['{invite}', '{coupon}'],
        [rawurlencode($invite), rawurlencode($coupon)],
        $template
    );
    return preg_match('#^https?://#i', $rendered) ? $rendered : '';
}

function provider_has_checkout(array $p): bool
{
    return provider_checkout_template($p) !== '';
}

function provider_checkout_url(array $p): string
{
    $template = provider_checkout_template($p);
    if ($template !== '') {
        return $template;
    }
    return trim((string) ($p['affiliate_url'] ?: $p['official_url']));
}

function provider_price_note(array $p): string
{
    $parts = [];
    $currency = $p['price_currency'] ?? 'CNY';
    $period = (string) ($p['price_from_period'] ?? '');
    $monthly = $p['monthly_price_from'] ?? null;
    if ($monthly !== null && $period !== '' && $period !== '月付') {
        $parts[] = '月均 ' . format_price($monthly, $currency);
    }
    if (!empty($p['traffic_from'])) {
        $parts[] = format_traffic((float) $p['traffic_from']) . '/月';
    }
    if (!empty($p['monthly_offline'])) {
        $parts[] = '月付已下线';
    }
    return implode(' · ', $parts);
}

function news_excerpt(string $text, int $limit = 88): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '' || mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
}

function news_url(array $item): string
{
    $id = (int) ($item['id'] ?? 0);
    return $id > 0 ? '/news/' . $id . '/' : '/news/';
}

function initials(string $name): string
{
    $name = trim($name);
    if (preg_match('/^[A-Za-z]/', $name)) {
        $words = preg_split('/\s+/', $name) ?: [];
        $letters = '';
        foreach ($words as $word) {
            if ($word !== '' && preg_match('/^[A-Za-z0-9]/', $word)) {
                $letters .= strtoupper($word[0]);
            }
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters ?: strtoupper(substr($name, 0, 2));
    }
    return mb_substr($name, 0, 2, 'UTF-8');
}

function slugify(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $value) ?? '';
    return trim(mb_strtolower($value, 'UTF-8'), '-');
}

function redirect(string $url, int $status = 302): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = getenv('PROXY_IP_SALT') ?: 'proxy-atlas-local-salt';
    return hash('sha256', $salt . '|' . $ip);
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function current_admin(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, created_at FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('/admin/login.php');
    }
    return $admin;
}

function site_url(string $path = '/'): string
{
    $base = rtrim(setting('site_url', getenv('PROXY_SITE_URL') ?: ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return '/assets/' . ltrim($path, '/') . '?v=' . $version;
}

function active_nav(string $path): string
{
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/') {
        return $current === '/' ? 'is-active' : '';
    }
    return str_starts_with($current, $path) ? 'is-active' : '';
}

function render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/templates/' . $template . '.php';
}

function render_layout(string $template, array $vars = []): void
{
    $vars['content_template'] = $template;
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/templates/layout.php';
}

function render_page(string $template, array $vars = []): string
{
    ob_start();
    render_layout($template, $vars);
    return (string) ob_get_clean();
}

function partial(string $template, array $vars = []): string
{
    ob_start();
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/templates/' . $template . '.php';
    return (string) ob_get_clean();
}

function line_badge_class(string $line): string
{
    if (str_contains($line, '专线')) {
        return 'badge--blue';
    }
    if (str_contains($line, '中转')) {
        return 'badge--amber';
    }
    return '';
}

/** Human-facing label for line types; keeps unknown data honest. */
function line_label(string $line): string
{
    return $line === '未标注' ? '线路待核实' : $line;
}

function monthly_equiv_cny(array $p): ?float
{
    $value = $p['monthly_price_from'] ?? null;
    if ($value === null) {
        $value = $p['price_from'] ?? null;
        if (($p['price_from_period'] ?? '') === '按量') {
            return null;
        }
    }
    if ($value === null) {
        return null;
    }
    $value = (float) $value;
    return ($p['price_currency'] ?? 'CNY') === 'USD' ? $value * 7.2 : $value;
}

function provider_for_view(array $row): array
{
    $decoded = [
        'protocols', 'regions', 'regions_common', 'regions_other',
        'media_unlock', 'ai_unlock', 'features', 'payments', 'tags',
    ];
    foreach ($decoded as $key) {
        $row[$key] = json_arr($row[$key] ?? '[]');
    }
    return $row;
}
