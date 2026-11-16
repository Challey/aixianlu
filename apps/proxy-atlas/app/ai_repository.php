<?php

declare(strict_types=1);

/**
 * AI tool catalogue, provider fit evidence, and collected AI news.
 */

function ai_tool_for_view(array $row): array
{
    foreach (['requirements', 'regions', 'network_traits'] as $key) {
        $row[$key] = json_arr($row[$key] ?? '[]');
    }
    return $row;
}

function ai_tools(bool $publishedOnly = true): array
{
    $sql = 'SELECT * FROM ai_tools';
    if ($publishedOnly) {
        $sql .= " WHERE status = 'published'";
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return array_map('ai_tool_for_view', db()->query($sql)->fetchAll());
}

function ai_tool_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM ai_tools WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? ai_tool_for_view($row) : null;
}

function ai_tool_category_label(string $category): string
{
    return match ($category) {
        'chat' => 'AI 对话',
        'coding' => 'AI 编程',
        'search' => 'AI 搜索',
        'image' => 'AI 图像',
        'video' => 'AI 视频',
        'api' => 'AI API',
        default => 'AI 工具',
    };
}

function ai_evidence_meta(string $level): array
{
    return match ($level) {
        'verified' => ['label' => '已实测', 'class' => 'badge--green', 'rank' => 0],
        'source' => ['label' => '来源提及', 'class' => 'badge--blue', 'rank' => 1],
        'inferred' => ['label' => '条件推断', 'class' => 'badge--amber', 'rank' => 2],
        default => ['label' => '待核验', 'class' => '', 'rank' => 3],
    };
}

function ai_fit_order_sql(): string
{
    return "CASE f.evidence_level
        WHEN 'verified' THEN 0
        WHEN 'source' THEN 1
        WHEN 'inferred' THEN 2
        ELSE 3 END";
}

function providers_for_ai_tool(int $toolId): array
{
    $sql = "SELECT p.*, f.evidence_level, f.evidence_note,
                   f.source_url AS fit_source_url, f.last_checked AS fit_last_checked
            FROM provider_ai_fit f
            JOIN providers p ON p.id = f.provider_id
            WHERE f.tool_id = ? AND p.status = 'published'
            ORDER BY " . ai_fit_order_sql() . ", p.weight DESC, p.source_order ASC, p.id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$toolId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = provider_for_view($row);
    }
    return $rows;
}

function provider_ai_fits(int $providerId): array
{
    $sql = "SELECT f.*, t.slug AS tool_slug, t.name AS tool_name, t.category AS tool_category,
                   t.tagline AS tool_tagline
            FROM provider_ai_fit f
            JOIN ai_tools t ON t.id = f.tool_id
            WHERE f.provider_id = ? AND t.status = 'published'
            ORDER BY " . ai_fit_order_sql() . ", t.sort_order ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$providerId]);
    return $stmt->fetchAll();
}

function attach_ai_fits(array $providers): array
{
    if (!$providers) {
        return $providers;
    }
    $ids = array_values(array_filter(array_map(fn ($p) => (int) ($p['id'] ?? 0), $providers)));
    if (!$ids) {
        return $providers;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT f.*, t.slug AS tool_slug, t.name AS tool_name, t.category AS tool_category,
                   t.tagline AS tool_tagline
            FROM provider_ai_fit f
            JOIN ai_tools t ON t.id = f.tool_id
            WHERE f.provider_id IN ({$placeholders}) AND t.status = 'published'
            ORDER BY " . ai_fit_order_sql() . ", t.sort_order ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['provider_id']][] = $row;
    }
    foreach ($providers as &$provider) {
        $provider['ai_fits'] = $map[(int) ($provider['id'] ?? 0)] ?? [];
    }
    return $providers;
}

function latest_ai_news(int $limit = 30, bool $publishedOnly = true): array
{
    $sql = 'SELECT * FROM ai_news';
    if ($publishedOnly) {
        $sql .= " WHERE status = 'published'";
    }
    $sql .= ' ORDER BY published_at DESC, importance DESC, id DESC LIMIT ' . max(1, min(200, $limit));
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['tags'] = json_arr($row['tags'] ?? '[]');
        $row['related_tools'] = json_arr($row['related_tools'] ?? '[]');
    }
    return $rows;
}

function ai_news_for_tool(string $toolSlug, int $limit = 4): array
{
    $stmt = db()->prepare(
        "SELECT * FROM ai_news
         WHERE status = 'published' AND related_tools LIKE ?
         ORDER BY published_at DESC, importance DESC, id DESC LIMIT " . max(1, min(20, $limit))
    );
    $stmt->execute(['%"' . $toolSlug . '"%']);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['tags'] = json_arr($row['tags'] ?? '[]');
        $row['related_tools'] = json_arr($row['related_tools'] ?? '[]');
    }
    return $rows;
}

function ai_tool_fit_counts(): array
{
    $rows = db()->query(
        "SELECT t.slug,
                COUNT(f.id) AS total,
                SUM(CASE WHEN f.evidence_level = 'verified' THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN f.evidence_level = 'source' THEN 1 ELSE 0 END) AS source,
                SUM(CASE WHEN f.evidence_level = 'inferred' THEN 1 ELSE 0 END) AS inferred
         FROM ai_tools t
         LEFT JOIN provider_ai_fit f ON f.tool_id = t.id
         WHERE t.status = 'published'
         GROUP BY t.id"
    )->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['slug']] = [
            'total' => (int) $row['total'],
            'verified' => (int) $row['verified'],
            'source' => (int) $row['source'],
            'inferred' => (int) $row['inferred'],
        ];
    }
    return $map;
}

function ai_news_stats(): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN date(published_at) = date('now') THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN published_at >= datetime('now', '-7 day') THEN 1 ELSE 0 END) AS week,
                COUNT(DISTINCT source_name) AS sources
         FROM ai_news WHERE status = 'published'"
    )->fetch();
    return [
        'total' => (int) ($row['total'] ?? 0),
        'today' => (int) ($row['today'] ?? 0),
        'week' => (int) ($row['week'] ?? 0),
        'sources' => (int) ($row['sources'] ?? 0),
    ];
}

function ai_news_category_label(string $category): string
{
    return match ($category) {
        'model' => '模型',
        'chip' => '芯片算力',
        'product' => '产品',
        'funding' => '融资并购',
        'policy' => '政策监管',
        'research' => '研究',
        'people' => '人物',
        'industry' => '行业',
        default => '行业',
    };
}

function ai_news_category_class(string $category): string
{
    return match ($category) {
        'model', 'research' => 'badge--blue',
        'chip', 'policy' => 'badge--amber',
        'product', 'funding' => 'badge--green',
        default => '',
    };
}

function ai_tool_name(string $slug): string
{
    static $cache = [];
    if (!array_key_exists($slug, $cache)) {
        $tool = ai_tool_by_slug($slug);
        $cache[$slug] = $tool['name'] ?? $slug;
    }
    return $cache[$slug];
}

/**
 * Short editorial note that connects a news item to the site's product.
 * Deliberately rule-based: no invented facts, no personal allegations.
 */
function ai_news_impact(array $news): string
{
    $category = (string) ($news['category'] ?? 'industry');
    $title = (string) ($news['title'] ?? '');
    $tools = $news['related_tools'] ?? [];

    if ($tools) {
        $names = [];
        foreach ($tools as $slug) {
            $tool = ai_tool_by_slug((string) $slug);
            if ($tool) {
                $names[] = $tool['name'];
            }
        }
        if ($names) {
            return '相关工具：' . implode('、', array_unique($names)) . '。工具更新或策略变化时，先核对官方说明，再检查现有线路的稳定性与地区覆盖。';
        }
    }

    return match ($category) {
        'model' => '新模型发布或能力变化，通常会带来一波集中访问；选择机场时优先看流式稳定性、峰值时段表现和可试用。',
        'chip' => '算力与芯片供给会影响模型价格和服务稳定性；长期使用可关注套餐周期与单位流量成本，而不是只看首月价。',
        'product' => '产品更新可能改变登录、地区或网络要求；先确认官方说明，再用短周期套餐验证线路。',
        'funding' => '融资与并购会影响服务持续性和价格策略；选择长期套餐前，优先看运营年限与可退款政策。',
        'policy' => '政策与合规变化可能影响地区可用性；保留备用线路，并避免把长期订阅押在单一服务上。',
        'research' => '研究进展到产品落地通常有时间差；对普通用户更实际的影响是后续模型能力与调用成本。',
        'people' => '人物与组织变化可能影响公司路线和产品节奏；涉及个人传闻的内容只保留可核验来源，不做二次演绎。',
        default => 'AI 行业变化很快，网络方案也需要留出备份；先小额试用，再决定是否长期付费。',
    };
}

function seed_ai_tools(PDO $pdo): int
{
    $file = APP_ROOT . '/data/seed/ai_tools.json';
    if (!is_file($file)) {
        return 0;
    }
    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload) || !isset($payload['tools']) || !is_array($payload['tools'])) {
        return 0;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO ai_tools (
            slug, name, vendor, category, tagline, summary, requirements, regions,
            network_traits, ip_sensitivity, account_risk, official_url, official_note,
            status, sort_order, last_verified, updated_at
        ) VALUES (
            :slug, :name, :vendor, :category, :tagline, :summary, :requirements, :regions,
            :network_traits, :ip_sensitivity, :account_risk, :official_url, :official_note,
            :status, :sort_order, :last_verified, CURRENT_TIMESTAMP
        )
        ON CONFLICT(slug) DO UPDATE SET
            name = excluded.name,
            vendor = excluded.vendor,
            category = excluded.category,
            tagline = excluded.tagline,
            summary = excluded.summary,
            requirements = excluded.requirements,
            regions = excluded.regions,
            network_traits = excluded.network_traits,
            ip_sensitivity = excluded.ip_sensitivity,
            account_risk = excluded.account_risk,
            official_url = excluded.official_url,
            official_note = excluded.official_note,
            status = excluded.status,
            sort_order = excluded.sort_order,
            last_verified = excluded.last_verified,
            updated_at = CURRENT_TIMESTAMP'
    );
    $count = 0;
    foreach ($payload['tools'] as $tool) {
        if (empty($tool['slug']) || empty($tool['name'])) {
            continue;
        }
        $stmt->execute([
            ':slug' => $tool['slug'],
            ':name' => $tool['name'],
            ':vendor' => $tool['vendor'] ?? '',
            ':category' => $tool['category'] ?? 'chat',
            ':tagline' => $tool['tagline'] ?? '',
            ':summary' => $tool['summary'] ?? '',
            ':requirements' => json_col($tool['requirements'] ?? []),
            ':regions' => json_col($tool['regions'] ?? []),
            ':network_traits' => json_col($tool['network_traits'] ?? []),
            ':ip_sensitivity' => $tool['ip_sensitivity'] ?? '',
            ':account_risk' => $tool['account_risk'] ?? '',
            ':official_url' => $tool['official_url'] ?? '',
            ':official_note' => $tool['official_note'] ?? '',
            ':status' => $tool['status'] ?? 'published',
            ':sort_order' => (int) ($tool['sort_order'] ?? 0),
            ':last_verified' => $tool['last_verified'] ?? date('Y-m-d'),
        ]);
        $count++;
    }
    return $count;
}

/**
 * Rebuild non-verified fit rows from current provider data.
 * Verified rows are treated as manually confirmed and left untouched.
 */
function rebuild_provider_ai_fits(PDO $pdo): int
{
    $providers = $pdo->query(
        'SELECT id, slug, name, line_type, regions, ai_unlock, features,
                region_count, node_count, source_review_url
         FROM providers WHERE status = \'published\''
    )->fetchAll();
    $tools = [];
    $toolNames = [];
    foreach ($pdo->query('SELECT id, slug, name FROM ai_tools')->fetchAll() as $row) {
        $tools[(string) $row['slug']] = (int) $row['id'];
        $toolNames[(string) $row['slug']] = (string) $row['name'];
    }
    if (!$providers || !$tools) {
        return 0;
    }

    $pdo->exec("DELETE FROM provider_ai_fit WHERE evidence_level != 'verified'");

    $insert = $pdo->prepare(
        "INSERT INTO provider_ai_fit
            (provider_id, tool_id, evidence_level, evidence_note, source_url, last_checked, updated_at)
         VALUES (:provider_id, :tool_id, :evidence_level, :evidence_note, :source_url, :last_checked, CURRENT_TIMESTAMP)
         ON CONFLICT(provider_id, tool_id) DO UPDATE SET
            evidence_level = excluded.evidence_level,
            evidence_note = excluded.evidence_note,
            source_url = excluded.source_url,
            last_checked = excluded.last_checked,
            updated_at = CURRENT_TIMESTAMP
         WHERE provider_ai_fit.evidence_level != 'verified'"
    );

    $labelToTool = ['chatgpt' => 'chatgpt', 'gemini' => 'gemini', 'claude' => 'claude'];
    $known = [];
    $today = date('Y-m-d');
    $write = function (int $providerId, string $toolSlug, string $level, string $note, string $sourceUrl) use ($insert, $tools, $today, &$known): void {
        if (!isset($tools[$toolSlug]) || isset($known[$providerId . ':' . $toolSlug])) {
            return;
        }
        $insert->execute([
            ':provider_id' => $providerId,
            ':tool_id' => $tools[$toolSlug],
            ':evidence_level' => $level,
            ':evidence_note' => $note,
            ':source_url' => $sourceUrl,
            ':last_checked' => $today,
        ]);
        $known[$providerId . ':' . $toolSlug] = true;
    };

    foreach ($providers as $provider) {
        $providerId = (int) $provider['id'];
        $aiUnlock = json_arr($provider['ai_unlock'] ?? '[]');
        $sourceUrl = (string) ($provider['source_review_url'] ?? '');
        foreach ($aiUnlock as $label) {
            $toolSlug = $labelToTool[mb_strtolower((string) $label, 'UTF-8')] ?? null;
            if ($toolSlug !== null) {
                $write(
                    $providerId,
                    $toolSlug,
                    'source',
                    '来源页面提及 ' . $label . '；本站未做独立实测，可用性以实际账号与节点为准。',
                    $sourceUrl
                );
            }
        }
        if (in_array('Claude', $aiUnlock, true)) {
            $write(
                $providerId,
                'claude-code',
                'inferred',
                '该机场来源提及 Claude；Claude Code 未单独核验，仅作同类服务条件匹配。',
                $sourceUrl
            );
        }
    }

    // Tools with thin or no direct mentions get a clearly-labelled shortlist
    // inferred from line type, region coverage, node scale and existing AI mentions.
    $inferTools = ['claude', 'claude-code', 'cursor', 'qoder', 'perplexity', 'copilot'];
    foreach ($inferTools as $toolSlug) {
        $toolName = $toolNames[$toolSlug] ?? '该工具';
        if (!isset($tools[$toolSlug])) {
            continue;
        }
        $scored = [];
        foreach ($providers as $provider) {
            $providerId = (int) $provider['id'];
            if (isset($known[$providerId . ':' . $toolSlug])) {
                continue;
            }
            $aiUnlock = json_arr($provider['ai_unlock'] ?? '[]');
            $lineType = (string) $provider['line_type'];
            $regions = json_arr($provider['regions'] ?? '[]');
            $score = 0;
            if (in_array('ChatGPT', $aiUnlock, true)) {
                $score += 30;
            }
            if (in_array('Gemini', $aiUnlock, true)) {
                $score += 20;
            }
            if (in_array('Claude', $aiUnlock, true)) {
                $score += 40;
            }
            if (str_contains($lineType, '专线')) {
                $score += 22;
            } elseif (str_contains($lineType, '中转')) {
                $score += 12;
            }
            $score += min(20, (int) $provider['region_count'] * 2);
            $score += min(16, (int) $provider['node_count'] / 8);
            if (in_array('免费试用', json_arr($provider['features'] ?? '[]'), true)) {
                $score += 5;
            }
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['provider' => $provider, 'score' => $score];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach (array_slice($scored, 0, 8) as $item) {
            $provider = $item['provider'];
            $providerId = (int) $provider['id'];
            $aiUnlock = json_arr($provider['ai_unlock'] ?? '[]');
            $regions = json_arr($provider['regions'] ?? '[]');
            $basis = [];
            if ($aiUnlock) {
                $basis[] = '来源提及 ' . implode('/', $aiUnlock);
            }
            $basis[] = '线路为' . ($provider['line_type'] ?: '未标注');
            $basis[] = '覆盖 ' . count($regions) . ' 个地区';
            $note = '该机场' . implode('，', $basis) . '；未对 ' . $toolName . ' 实测，仅按网络条件推断，需自行验证。';
            $write(
                $providerId,
                $toolSlug,
                'inferred',
                $note,
                (string) ($provider['source_review_url'] ?? '')
            );
        }
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM provider_ai_fit')->fetchColumn();
}
