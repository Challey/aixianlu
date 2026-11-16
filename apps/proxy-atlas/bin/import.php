#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$args = $argv ?? [];
$reset = in_array('--reset', $args, true);
$file = APP_ROOT . '/data/seed/providers.json';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}

if ($reset) {
    $path = db_path();
    foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
        if (is_file($candidate)) {
            unlink($candidate);
        }
    }
    echo "reset: removed existing database\n";
}

$pdo = db();
ensure_schema($pdo);

if (!is_file($file)) {
    fwrite(STDERR, "seed file not found: {$file}\n");
    exit(1);
}
$payload = json_decode((string) file_get_contents($file), true);
if (!is_array($payload) || !isset($payload['providers'])) {
    fwrite(STDERR, "invalid seed JSON\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(
        'INSERT INTO providers (
            slug, name, name_en, founded, summary, line_type, protocols, regions,
            regions_common, regions_other, media_unlock, ai_unlock, features, payments,
            tags, discount_code, discount_label, monthly_offline, plan_note,
            official_url, affiliate_url, source_review_url, source_home_url,
            source_updated_at, status, source_order, weight, region_count,
            node_count, node_count_disclosed, node_count_note, plan_count,
            price_from, price_from_period, price_currency, traffic_from,
            unit_price_from, monthly_price_from, updated_at
        ) VALUES (
            :slug, :name, :name_en, :founded, :summary, :line_type, :protocols, :regions,
            :regions_common, :regions_other, :media_unlock, :ai_unlock, :features, :payments,
            :tags, :discount_code, :discount_label, :monthly_offline, :plan_note,
            :official_url, :affiliate_url, :source_review_url, :source_home_url,
            :source_updated_at, :status, :source_order, :weight, :region_count,
            :node_count, :node_count_disclosed, :node_count_note, :plan_count,
            :price_from, :price_from_period, :price_currency, :traffic_from,
            :unit_price_from, :monthly_price_from, CURRENT_TIMESTAMP
        )
        ON CONFLICT(slug) DO UPDATE SET
            name = excluded.name,
            name_en = excluded.name_en,
            founded = excluded.founded,
            summary = excluded.summary,
            line_type = excluded.line_type,
            protocols = excluded.protocols,
            regions = excluded.regions,
            regions_common = excluded.regions_common,
            regions_other = excluded.regions_other,
            media_unlock = excluded.media_unlock,
            ai_unlock = excluded.ai_unlock,
            features = excluded.features,
            payments = excluded.payments,
            tags = excluded.tags,
            discount_code = excluded.discount_code,
            discount_label = excluded.discount_label,
            monthly_offline = excluded.monthly_offline,
            plan_note = excluded.plan_note,
            official_url = excluded.official_url,
            affiliate_url = CASE WHEN excluded.affiliate_url <> \'\' THEN excluded.affiliate_url ELSE providers.affiliate_url END,
            source_review_url = excluded.source_review_url,
            source_home_url = excluded.source_home_url,
            source_updated_at = excluded.source_updated_at,
            status = excluded.status,
            source_order = excluded.source_order,
            weight = excluded.weight,
            region_count = excluded.region_count,
            node_count = excluded.node_count,
            node_count_disclosed = excluded.node_count_disclosed,
            node_count_note = excluded.node_count_note,
            plan_count = excluded.plan_count,
            price_from = excluded.price_from,
            price_from_period = excluded.price_from_period,
            price_currency = excluded.price_currency,
            traffic_from = excluded.traffic_from,
            unit_price_from = excluded.unit_price_from,
            monthly_price_from = excluded.monthly_price_from,
            updated_at = CURRENT_TIMESTAMP'
    );

    $find = $pdo->prepare('SELECT id FROM providers WHERE slug = ?');
    $deletePlans = $pdo->prepare('DELETE FROM plans WHERE provider_id = ?');
    $insertPlan = $pdo->prepare(
        'INSERT INTO plans (
            provider_id, name, option_label, price, currency, period, period_months,
            traffic_gb, traffic_period, monthly_price, monthly_traffic_gb, unit_price,
            speed_limit, device_limit, notes, is_entry, sort
        ) VALUES (
            :provider_id, :name, :option_label, :price, :currency, :period, :period_months,
            :traffic_gb, :traffic_period, :monthly_price, :monthly_traffic_gb, :unit_price,
            :speed_limit, :device_limit, :notes, :is_entry, :sort
        )'
    );

    $totalPlans = 0;
    foreach ($payload['providers'] as $p) {
        $entry = $p['entry'] ?? null;
        $priceFrom = $entry['price'] ?? null;
        $monthlyFrom = ((int) ($entry['period_months'] ?? 0)) > 0
            ? ($entry['monthly_price'] ?? ($entry['price'] ?? null))
            : null;

        $row = [
            ':slug' => $p['slug'],
            ':name' => $p['name'],
            ':name_en' => $p['name_en'] ?? '',
            ':founded' => $p['founded'] ?? null,
            ':summary' => $p['summary'] ?? '',
            ':line_type' => $p['line_type'] ?? '未标注',
            ':protocols' => json_col($p['protocols'] ?? []),
            ':regions' => json_col($p['regions'] ?? []),
            ':regions_common' => json_col($p['regions_common'] ?? []),
            ':regions_other' => json_col($p['regions_other'] ?? []),
            ':media_unlock' => json_col($p['media_unlock'] ?? []),
            ':ai_unlock' => json_col($p['ai_unlock'] ?? []),
            ':features' => json_col($p['features'] ?? []),
            ':payments' => json_col($p['payments'] ?? []),
            ':tags' => json_col($p['tags'] ?? []),
            ':discount_code' => $p['discount_code'] ?? '',
            ':discount_label' => $p['discount_label'] ?? '',
            ':monthly_offline' => !empty($p['monthly_offline']) ? 1 : 0,
            ':plan_note' => $p['plan_note'] ?? '',
            ':official_url' => $p['official_url'] ?? '',
            ':affiliate_url' => $p['affiliate_url'] ?? '',
            ':source_review_url' => $p['source_review_url'] ?? '',
            ':source_home_url' => $p['source_home_url'] ?? '',
            ':source_updated_at' => $p['source_updated_at'] ?? '',
            ':status' => $p['status'] ?? 'published',
            ':source_order' => (int) ($p['source_order'] ?? 0),
            ':weight' => (int) ($p['weight'] ?? 0),
            ':region_count' => count($p['regions'] ?? []),
            ':node_count' => (int) ($p['node_count'] ?? count($p['regions'] ?? [])),
            ':node_count_disclosed' => !empty($p['node_count_disclosed']) ? 1 : 0,
            ':node_count_note' => $p['node_count_note'] ?? '',
            ':plan_count' => array_sum(array_map(fn ($pl) => count($pl['options'] ?? []), $p['plans'] ?? [])),
            ':price_from' => $priceFrom,
            ':price_from_period' => $entry['period'] ?? '',
            ':price_currency' => $entry['currency'] ?? 'CNY',
            ':traffic_from' => $entry['monthly_traffic_gb'] ?? null,
            ':unit_price_from' => $entry['unit_price'] ?? null,
            ':monthly_price_from' => $monthlyFrom,
        ];
        $upsert->execute($row);

        $find->execute([$p['slug']]);
        $providerId = (int) $find->fetchColumn();
        $deletePlans->execute([$providerId]);

        $sort = 0;
        foreach ($p['plans'] ?? [] as $plan) {
            foreach ($plan['options'] ?? [] as $option) {
                $isEntry = 0;
                if ($entry && $option['price'] == $entry['price'] && $option['period'] === $entry['period']) {
                    $isEntry = 1;
                }
                $insertPlan->execute([
                    ':provider_id' => $providerId,
                    ':name' => $plan['name'] ?? '套餐',
                    ':option_label' => $option['label'] ?? '',
                    ':price' => $option['price'],
                    ':currency' => $option['currency'] ?? 'CNY',
                    ':period' => $option['period'] ?? '月付',
                    ':period_months' => (int) ($option['period_months'] ?? 1),
                    ':traffic_gb' => $option['traffic_gb'] ?? null,
                    ':traffic_period' => $option['traffic_period'] ?? 'month',
                    ':monthly_price' => $option['monthly_price'] ?? null,
                    ':monthly_traffic_gb' => $option['monthly_traffic_gb'] ?? null,
                    ':unit_price' => $option['unit_price'] ?? null,
                    ':speed_limit' => $option['speed_limit'] ?? '',
                    ':device_limit' => $option['device_limit'] ?? null,
                    ':notes' => $option['notes'] ?? '',
                    ':is_entry' => $isEntry,
                    ':sort' => $sort++,
                ]);
                $totalPlans++;
            }
        }
    }

    $toolCount = seed_ai_tools($pdo);
    $fitCount = rebuild_provider_ai_fits($pdo);

    $defaults = [
        'site_name' => 'AI 线路',
        'site_tagline' => 'AI 工具网络要求与机场对比',
        'site_description' => '面向国内 AI 用户的机场对比与线路图谱：价格、线路、协议、地区、AI 工具适配与优惠信息一站看清。',
        'site_url' => getenv('PROXY_SITE_URL') ?: '',
        'source_name' => 'Clash 爱好者',
        'source_url' => 'https://clashios.com/',
        'data_updated_at' => $payload['source']['updated_at'] ?? date('Y-m-d'),
        'affiliate_disclosure' => '本站部分官网链接为推广链接；通过链接注册可能为我们带来佣金，不影响你的实际价格。价格与套餐以服务商官网为准。',
        'partner_contact' => 'partner@example.com',
        'admin_note' => '默认后台账号 admin；上线前请修改密码并配置 PROXY_ADMIN_PASSWORD。',
    ];
    // Only seed defaults on a fresh install. Re-importing provider data must not
    // overwrite the production domain, brand or disclosure copy.
    $put = $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO NOTHING');
    $touch = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
    foreach ($defaults as $key => $value) {
        if ($key === 'data_updated_at') {
            $touch->execute([$value, $key]);
            continue;
        }
        $put->execute([$key, $value]);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count === 0) {
        $username = getenv('PROXY_ADMIN_USER') ?: 'admin';
        $password = getenv('PROXY_ADMIN_PASSWORD') ?: 'admin';
        $stmt = $pdo->prepare('INSERT INTO admin_users(username, password_hash) VALUES(?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        echo "admin user created: {$username}" . (getenv('PROXY_ADMIN_PASSWORD') ? '' : ' (password: admin - change it)') . "\n";
    }

    $pdo->commit();
    echo 'imported ' . count($payload['providers']) . " providers, {$totalPlans} plan options, {$toolCount} AI tools, {$fitCount} AI fit rows\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
