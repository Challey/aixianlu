<?php

declare(strict_types=1);

function published_providers(bool $withPlans = true): array
{
    $pdo = db();
    $rows = $pdo->query(
        "SELECT * FROM providers WHERE status = 'published' ORDER BY weight DESC, source_order ASC, id ASC"
    )->fetchAll();

    $providers = [];
    $planStmt = $pdo->prepare('SELECT * FROM plans WHERE provider_id = ? ORDER BY sort ASC, id ASC');
    foreach ($rows as $row) {
        $provider = provider_for_view($row);
        $provider['plans'] = [];
        if ($withPlans) {
            $planStmt->execute([$row['id']]);
            $provider['plans'] = $planStmt->fetchAll();
        }
        $providers[] = $provider;
    }
    return $providers;
}

function provider_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM providers WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $provider = provider_for_view($row);
    $plans = db()->prepare('SELECT * FROM plans WHERE provider_id = ? ORDER BY sort ASC, id ASC');
    $plans->execute([$row['id']]);
    $provider['plans'] = $plans->fetchAll();
    return $provider;
}

function grouped_plans(array $plans): array
{
    $groups = [];
    foreach ($plans as $plan) {
        $name = $plan['name'] ?: '套餐';
        if (!isset($groups[$name])) {
            $groups[$name] = ['name' => $name, 'options' => []];
        }
        $groups[$name]['options'][] = $plan;
    }
    foreach ($groups as &$group) {
        $group['entry_id'] = null;
        foreach ($group['options'] as $option) {
            if (!empty($option['is_entry'])) {
                $group['entry_id'] = (int) $option['id'];
                break;
            }
        }
    }
    return array_values($groups);
}

function compact_index(array $providers): array
{
    $index = [];
    foreach ($providers as $p) {
        $index[] = [
            'slug' => $p['slug'],
            'name' => $p['name'],
            'summary' => $p['summary'],
            'line_type' => $p['line_type'],
            'protocols' => $p['protocols'],
            'regions' => $p['regions'],
            'region_count' => (int) $p['region_count'],
            'node_count' => (int) ($p['node_count'] ?? 0),
            'node_count_disclosed' => (int) ($p['node_count_disclosed'] ?? 0),
            'node_count_note' => $p['node_count_note'] ?? '',
            'node_count_label' => node_count_label($p),
            'price_from' => $p['price_from'],
            'price_from_period' => $p['price_from_period'],
            'price_currency' => $p['price_currency'],
            'monthly_price_from' => $p['monthly_price_from'],
            'traffic_from' => $p['traffic_from'],
            'unit_price_from' => $p['unit_price_from'],
            'plan_count' => (int) $p['plan_count'],
            'founded' => $p['founded'],
            'features' => $p['features'],
            'media_unlock' => $p['media_unlock'],
            'ai_unlock' => $p['ai_unlock'],
            'discount_code' => $p['discount_code'],
            'discount_label' => $p['discount_label'],
            'monthly_offline' => (bool) $p['monthly_offline'],
            'url' => '/providers/' . $p['slug'] . '/',
        ];
    }
    return $index;
}

function dataset_updates(): array
{
    $counts = db()->query(
        "SELECT
            COUNT(*) AS providers,
            SUM(plan_count) AS plans,
            MAX(region_count) AS max_regions,
            MAX(source_updated_at) AS updated_at
         FROM providers WHERE status = 'published'"
    )->fetch();
    return [
        'providers' => (int) ($counts['providers'] ?? 0),
        'plans' => (int) ($counts['plans'] ?? 0),
        'max_regions' => (int) ($counts['max_regions'] ?? 0),
        'updated_at' => (string) ($counts['updated_at'] ?? setting('data_updated_at', date('Y-m-d'))),
    ];
}
