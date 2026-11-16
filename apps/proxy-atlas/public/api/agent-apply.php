<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

function wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requested = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return str_contains($accept, 'application/json') || $requested === 'xmlhttprequest';
}

function respond(bool $ok, string $message, int $status = 200): never
{
    if (wants_json()) {
        json_response(['ok' => $ok, 'message' => $message], $status);
    }
    $target = '/partner/?' . ($ok ? 'sent=1' : 'error=1') . '#apply';
    redirect($target);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(false, '请使用表单提交。', 405);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST);
    $self = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if ($host && $self && strcasecmp((string) $host, (string) $self) !== 0) {
        respond(false, '请求来源无效。', 403);
    }
}

$honeypot = trim((string) ($_POST['company_website'] ?? ''));
if ($honeypot !== '') {
    respond(true, '已收到申请，我们会尽快联系你。');
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$channel = trim((string) ($_POST['channel'] ?? ''));
$scale = trim((string) ($_POST['scale'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$intent = trim((string) ($_POST['intent'] ?? 'agent'));

if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
    respond(false, '请填写 2–80 个字符的称呼或渠道名。', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
    respond(false, '请填写有效的联系邮箱。', 422);
}
$allowedChannels = ['社群', '内容站', '视频', '企业客户', '资源供给', '其他'];
if (!in_array($channel, $allowedChannels, true)) {
    respond(false, '请选择渠道类型。', 422);
}
if (mb_strlen($note) > 800) {
    respond(false, '补充说明请控制在 800 字以内。', 422);
}

$pdo = db();
$ipHash = client_ip_hash();
$recent = $pdo->prepare("SELECT COUNT(*) FROM agents WHERE ip_hash = ? AND created_at >= datetime('now', '-1 hour')");
$recent->execute([$ipHash]);
if ((int) $recent->fetchColumn() >= 6) {
    respond(false, '提交过于频繁，请稍后再试。', 429);
}

$exists = $pdo->prepare('SELECT id FROM agents WHERE email = ? LIMIT 1');
$exists->execute([$email]);
if ($exists->fetchColumn()) {
    respond(true, '该邮箱已经提交过申请，我们会尽快联系你。');
}

$code = '';
for ($i = 0; $i < 5; $i++) {
    $candidate = 'A' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    $check = $pdo->prepare('SELECT 1 FROM agents WHERE code = ? LIMIT 1');
    $check->execute([$candidate]);
    if (!$check->fetchColumn()) {
        $code = $candidate;
        break;
    }
}
if ($code === '') {
    respond(false, '系统繁忙，请稍后重试。', 500);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO agents (name, email, code, tier, status, commission_rate, channel, note, ip_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $email, $code, 'standard', 'pending', 0.10, $channel, trim($note . ($scale !== '' ? "\n规模：" . $scale : '') . ($intent !== 'agent' ? "\n类型：" . $intent : '')), $ipHash]);
} catch (Throwable $e) {
    error_log('agent apply failed: ' . $e->getMessage());
    respond(false, '提交失败，请稍后重试或直接邮件联系。', 500);
}

respond(true, '已收到申请。人工核对后，我们会通过邮箱联系你。');
