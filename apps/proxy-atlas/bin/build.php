#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$siteName = setting('site_name', 'AI 线路');
if (trim((string) setting('site_url', '')) === '') {
    put_setting('site_url', getenv('PROXY_SITE_URL') ?: 'http://localhost:8080');
    echo "note: site_url was empty; defaulted to " . setting('site_url') . " (change before deploy)\n";
}

ensure_schema(db());
$providers = attach_ai_fits(published_providers(true));
if (!$providers) {
    fwrite(STDERR, "no providers found; run: php bin/import.php --reset\n");
    exit(1);
}

$aiTools = ai_tools(true);
$aiFitCounts = ai_tool_fit_counts();
$aiNews = latest_ai_news(200);
$aiNewsStats = ai_news_stats();

$stats = dataset_updates();
$updated = $stats['updated_at'];
$out = APP_ROOT . '/public';

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function write_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
    printf("  %-58s %6.1f KB\n", str_replace(APP_ROOT . '/public/', '', $path), strlen($content) / 1024);
}

echo "Building static site ({$stats['providers']} providers, {$stats['plans']} plans)\n";

foreach (['index.html', '404.html', 'sitemap.xml', 'robots.txt'] as $file) {
    if (is_file($out . '/' . $file)) {
        unlink($out . '/' . $file);
    }
}
foreach (['providers', 'compare', 'network', 'match', 'partner', 'about', 'guide', 'ai', 'news', 'data', 'my', 'status'] as $dir) {
    remove_tree($out . '/' . $dir);
}

if (!is_dir($out . '/assets')) {
    mkdir($out . '/assets', 0755, true);
}
foreach (['site.css', 'site.js', 'admin.css', 'admin.js', 'favicon.svg'] as $asset) {
    copy(APP_ROOT . '/assets/' . $asset, $out . '/assets/' . $asset);
}

$homeJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $siteName,
    'url' => site_url('/'),
    'description' => setting('site_description', ''),
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => site_url('/providers/') . '?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];

write_file($out . '/index.html', render_page('home', [
    'providers' => $providers,
    'aiTools' => array_slice($aiTools, 0, 4),
    'aiToolCount' => count($aiTools),
    'aiNews' => array_slice($aiNews, 0, 3),
    'aiNewsStats' => $aiNewsStats,
    'title' => $siteName . ' — ' . $stats['providers'] . ' 家机场与 AI 工具网络选型',
    'description' => $stats['providers'] . ' 家机场对比，以及 ChatGPT、Claude、Gemini、Cursor、Qoder 等 AI 工具的网络要求与候选机场；价格、线路、地区与证据等级一页看清。',
    'canonical' => '/',
    'body_class' => 'page-home',
    'jsonld' => $homeJsonLd,
]));

$listJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => '机场对比', 'item' => site_url('/providers/')],
    ],
];

write_file($out . '/providers/index.html', render_page('providers', [
    'providers' => $providers,
    'title' => '机场对比：' . $stats['providers'] . ' 家机场价格、线路、流量与解锁 — ' . $siteName,
    'description' => '按价格、线路、协议、地区和特性筛选 ' . $stats['providers'] . ' 家机场，查看入门价、月均成本、月流量与单位流量价，支持并排对比。',
    'canonical' => '/providers/',
    'jsonld' => $listJsonLd,
]));

write_file($out . '/compare/index.html', render_page('compare', [
    'title' => '机场并排对比 — ' . $siteName,
    'description' => '最多同时对比 4 家机场的入门价、月均成本、流量、单位流量价、线路、协议、地区与解锁能力。',
    'canonical' => '/compare/',
    'body_class' => 'page-compare',
]));

write_file($out . '/match/index.html', render_page('match', [
    'title' => '智能匹配：三步找到合适的机场 — ' . $siteName,
    'description' => '按预算、月流量和线路偏好为 ' . $stats['providers'] . ' 家机场评分，筛出更接近需求的 4 家。',
    'canonical' => '/match/',
]));

write_file($out . '/network/index.html', render_page('network', [
    'providers' => $providers,
    'title' => '上游网络：专线、中转与协议分布 — ' . $siteName,
    'description' => '看懂 IPLC/IEPL 专线、公网中转与直连的成本差异，查看 ' . $stats['providers'] . ' 家机场的线路类型、协议与地区覆盖分布。',
    'canonical' => '/network/',
]));

$probeRows = probe_latest_metrics(24);
$probeConfig = [
    'targets' => probe_target_catalog(),
    'windows' => [6, 24, 72, 168],
    'names' => array_keys(probe_target_catalog()),
    'repo' => 'https://github.com/Challey/aixianlu/tree/main/probes',
];
$probeStatusMeta = probe_status_meta(24);

write_file($out . '/status/index.html', render_page('status', [
    'probe_rows' => $probeRows,
    'probe_summary' => probe_status_summary(24),
    'probe_matrix' => probe_status_matrix(24),
    'probe_meta' => $probeStatusMeta,
    'probe_config' => $probeConfig,
    'title' => 'AI 端点公开探测：速率、可靠度与稳定度实测 — ' . $siteName,
    'description' => '社区公开探测节点从中国大陆实测 OpenAI、Claude、Gemini、Cursor 等端点的可用率、可达率与 TTFB，按小时聚合公开，算法与样本量全部可见，不含 IP 与账号信息。',
    'canonical' => '/status/',
    'body_class' => 'page-status',
]));

$guideJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Article',
            'headline' => 'VPN 怎么选：直连、中转与专线的真实差别',
            'description' => '直连、BGP 中转与 IPLC / IEPL 专线在成本、延迟、晚高峰与抗干扰上的差别，附本站机场线路分布与选型建议。',
            'datePublished' => '2026-11-10',
            'dateModified' => $updated,
            'inLanguage' => 'zh-CN',
            'mainEntityOfPage' => site_url('/guide/'),
            'author' => ['@type' => 'Organization', 'name' => $siteName],
            'publisher' => ['@type' => 'Organization', 'name' => $siteName],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '选择指南', 'item' => site_url('/guide/')],
            ],
        ],
    ],
];

write_file($out . '/guide/index.html', render_page('guide', [
    'providers' => $providers,
    'title' => 'VPN 怎么选：直连、中转与专线的真实差别 — ' . $siteName,
    'description' => '用三分钟看懂直连、BGP 中转与 IPLC / IEPL 专线在成本、延迟、晚高峰与抗干扰上的差别，附 ' . $stats['providers'] . ' 家机场的线路分布与选型建议。',
    'canonical' => '/guide/',
    'body_class' => 'page-guide',
    'jsonld' => $guideJsonLd,
]));

write_file($out . '/partner/index.html', render_page('partner', [
    'title' => '代理合作：选品、子 ID 与结算 — ' . $siteName,
    'description' => '面向社群、内容站、企业 IT 与跨境团队的机场代理合作：统一选品、独立子 ID、点击留痕与透明结算。',
    'canonical' => '/partner/',
]));

write_file($out . '/about/index.html', render_page('about', [
    'title' => '关于与数据来源 — ' . $siteName,
    'description' => 'AI 线路的数据来源、价格口径、AI 适配证据、隐私统计与免责声明。',
    'canonical' => '/about/',
]));

write_file($out . '/my/index.html', render_page('my', [
    'title' => '我的足迹：本机订阅与续费提醒 — ' . $siteName,
    'description' => '在本机浏览器记录点过与买过的机场、订阅到期日，并提供续费提醒；无需账号，数据不上传。',
    'canonical' => '/my/',
    'noindex' => true,
    'body_class' => 'page-my',
]));

write_file($out . '/404.html', render_page('404', [
    'title' => '页面不存在 — ' . $siteName,
    'description' => '页面不存在或已调整，返回机场列表继续浏览。',
    'canonical' => '/404.html',
]));

$aiIndexJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CollectionPage',
            'name' => 'AI 工具网络选型',
            'url' => site_url('/ai/'),
            'description' => 'ChatGPT、Claude、Gemini、Cursor、Qoder 等 AI 工具的网络要求与候选机场。',
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'AI 工具', 'item' => site_url('/ai/')],
            ],
        ],
        [
            '@type' => 'ItemList',
            'itemListElement' => array_map(fn ($tool, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $tool['name'],
                'url' => site_url('/ai/' . $tool['slug'] . '/'),
            ], $aiTools, array_keys($aiTools)),
        ],
    ],
];

write_file($out . '/ai/index.html', render_page('ai-index', [
    'tools' => $aiTools,
    'fitCounts' => $aiFitCounts,
    'news' => array_slice($aiNews, 0, 6),
    'newsStats' => $aiNewsStats,
    'providerCount' => $stats['providers'],
    'title' => 'AI 工具网络选型：ChatGPT、Claude、Cursor、Qoder — ' . $siteName,
    'description' => 'ChatGPT、Claude、Claude Code、Gemini、Cursor、Copilot、Qoder 国际版、Perplexity 的网络要求与候选机场对比，标注来源提及与条件推断。',
    'canonical' => '/ai/',
    'jsonld' => $aiIndexJsonLd,
]));

$builtToolPages = 0;
foreach ($aiTools as $tool) {
    $toolProviders = providers_for_ai_tool((int) $tool['id']);
    $toolNews = ai_news_for_tool((string) $tool['slug'], 4);
    $relatedTools = array_slice(array_values(array_filter(
        $aiTools,
        fn ($candidate) => $candidate['slug'] !== $tool['slug']
    )), 0, 4);

    // Latin-only names need a space before Chinese copy; names ending in Chinese don't.
    $nameForTitle = preg_match('/[A-Za-z0-9]$/', (string) $tool['name'])
        ? $tool['name'] . ' '
        : (string) $tool['name'];

    $faqItems = [
        [
            '@type' => 'Question',
            'name' => '这些机场一定能用 ' . $tool['name'] . ' 吗？',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => '不一定。来源提及只表示公开页面写过，条件推断只表示网络特征匹配；工具的地区策略、账号状态和节点表现都会影响结果。',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => '更看重延迟还是稳定性？',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $tool['category'] === 'coding'
                    ? '编程类工具通常有长任务和连续请求，稳定性、丢包率和节点一致性比最低延迟更重要。'
                    : '对话和搜索类工具对流式输出更敏感，首屏延迟、丢包和峰值时段稳定性都值得关注。',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => '价格和可用性以哪里为准？',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => '以 ' . $tool['name'] . ' 官方页面和机场官网结算页为准。本站价格是历史整理，可能滞后。',
            ],
        ],
    ];

    $toolJsonLd = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'AI 工具', 'item' => site_url('/ai/')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $tool['name'], 'item' => site_url('/ai/' . $tool['slug'] . '/')],
                ],
            ],
            [
                '@type' => 'ItemList',
                'name' => $tool['name'] . ' 候选机场',
                'itemListElement' => array_map(fn ($p, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $p['name'],
                    'url' => site_url('/providers/' . $p['slug'] . '/'),
                ], $toolProviders, array_keys($toolProviders)),
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => $faqItems,
            ],
        ],
    ];

    write_file($out . '/ai/' . $tool['slug'] . '/index.html', render_page('ai-tool', [
        'tool' => $tool,
        'providers' => $toolProviders,
        'news' => $toolNews,
        'relatedTools' => $relatedTools,
        'title' => $nameForTitle . '用什么机场？网络要求与候选机场 — ' . $siteName,
        'description' => $nameForTitle . '的网络要求、常用出口地区与候选机场对比；共 ' . count($toolProviders) . ' 家候选，按来源提及与条件推断标注证据，价格以官网为准。',
        'canonical' => '/ai/' . $tool['slug'] . '/',
        'jsonld' => $toolJsonLd,
    ]));
    $builtToolPages++;
}

$newsJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CollectionPage',
            'name' => 'AI 前线',
            'url' => site_url('/news/'),
            'description' => '每日 AI 事件与热点，按工具和主题关联到机场网络选型。',
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'AI 前线', 'item' => site_url('/news/')],
            ],
        ],
    ],
];

write_file($out . '/news/index.html', render_page('news', [
    'news' => $aiNews,
    'stats' => $aiNewsStats,
    'title' => 'AI 前线：每日 AI 事件与热点 — ' . $siteName,
    'description' => '每日采集公开 RSS 的 AI 事件与热点，保留标题、短摘要和原文链接，并关联 ChatGPT、Claude、Gemini、Cursor、Qoder 等工具的网络选型。',
    'canonical' => '/news/',
    'jsonld' => $newsJsonLd,
]));

$builtNewsPages = 0;
foreach ($aiNews as $item) {
    $itemId = (int) ($item['id'] ?? 0);
    if ($itemId <= 0) {
        continue;
    }
    $published = (string) ($item['published_at'] ?? '');
    $timestamp = $published !== '' ? strtotime($published . ' UTC') : false;
    if ($timestamp === false) {
        $timestamp = time();
    }
    $category = (string) ($item['category'] ?? 'industry');

    $relatedNews = [];
    foreach ($aiNews as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === $itemId) {
            continue;
        }
        if (($candidate['category'] ?? '') === $category) {
            $relatedNews[] = $candidate;
        }
        if (count($relatedNews) >= 3) {
            break;
        }
    }
    if (count($relatedNews) < 3) {
        $known = array_map(fn ($row) => (int) ($row['id'] ?? 0), $relatedNews);
        foreach ($aiNews as $candidate) {
            $candidateId = (int) ($candidate['id'] ?? 0);
            if ($candidateId === $itemId || in_array($candidateId, $known, true)) {
                continue;
            }
            $relatedNews[] = $candidate;
            $known[] = $candidateId;
            if (count($relatedNews) >= 3) {
                break;
            }
        }
    }

    $detailUrl = '/news/' . $itemId . '/';
    $article = [
        '@type' => 'NewsArticle',
        'headline' => (string) $item['title'],
        'url' => site_url($detailUrl),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => site_url($detailUrl)],
        'datePublished' => date('c', $timestamp),
        'dateModified' => date('c', $timestamp),
        'articleSection' => ai_news_category_label($category),
        'description' => news_excerpt((string) ($item['summary'] ?? ''), 110),
        'publisher' => [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => site_url('/'),
        ],
    ];
    if (!empty($item['source_url'])) {
        $article['isBasedOn'] = (string) $item['source_url'];
    }
    $detailJsonLd = [
        '@context' => 'https://schema.org',
        '@graph' => [
            $article,
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'AI 前线', 'item' => site_url('/news/')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $item['title'], 'item' => site_url($detailUrl)],
                ],
            ],
        ],
    ];

    $detailDescription = news_excerpt((string) ($item['summary'] ?? ''), 110);
    if ($detailDescription === '') {
        $detailDescription = (string) $item['title'];
    }

    write_file($out . '/news/' . $itemId . '/index.html', render_page('news-detail', [
        'item' => $item,
        'related' => $relatedNews,
        'title' => (string) $item['title'] . ' — AI 前线 — ' . $siteName,
        'description' => $detailDescription,
        'canonical' => $detailUrl,
        'jsonld' => $detailJsonLd,
        'body_class' => 'page-news-detail',
        'og_type' => 'article',
    ]));
    $builtNewsPages++;
}

$xmlEscape = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$feed = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$feed .= '<rss version="2.0"><channel>' . "\n";
$feed .= '<title>' . $xmlEscape('AI 前线 — ' . $siteName) . '</title>' . "\n";
$feed .= '<link>' . $xmlEscape(site_url('/news/')) . '</link>' . "\n";
$feed .= '<description>' . $xmlEscape('每日 AI 事件与热点，关联机场网络选型。') . '</description>' . "\n";
$feed .= '<language>zh-CN</language>' . "\n";
$feed .= '<lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n";
foreach (array_slice($aiNews, 0, 50) as $item) {
    $published = (string) ($item['published_at'] ?? '');
    $timestamp = $published !== '' ? strtotime($published . ' UTC') : time();
    $feed .= '<item>' . "\n";
    $feed .= '<title>' . $xmlEscape((string) $item['title']) . '</title>' . "\n";
    $feed .= '<link>' . $xmlEscape(site_url('/news/' . (int) $item['id'] . '/')) . '</link>' . "\n";
    $feed .= '<guid isPermaLink="false">' . $xmlEscape((string) $item['guid']) . '</guid>' . "\n";
    if (!empty($item['source_url'])) {
        $feed .= '<source url="' . $xmlEscape((string) $item['source_url']) . '">' . $xmlEscape((string) ($item['source_name'] ?? '')) . '</source>' . "\n";
    }
    $feed .= '<pubDate>' . date(DATE_RSS, $timestamp ?: time()) . '</pubDate>' . "\n";
    $feed .= '<description>' . $xmlEscape((string) $item['summary']) . '</description>' . "\n";
    $feed .= '<category>' . $xmlEscape(ai_news_category_label((string) $item['category'])) . '</category>' . "\n";
    $feed .= '</item>' . "\n";
}
$feed .= '</channel></rss>' . "\n";
write_file($out . '/news/feed.xml', $feed);

$sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$staticRoutes = ['/', '/providers/', '/ai/', '/news/', '/compare/', '/match/', '/network/', '/status/', '/guide/', '/partner/', '/about/'];
foreach ($staticRoutes as $route) {
    $sitemap .= '  <url><loc>' . htmlspecialchars(site_url($route), ENT_XML1) . '</loc><lastmod>' . e($updated) . '</lastmod></url>' . "\n";
}

foreach ($aiTools as $tool) {
    $sitemap .= '  <url><loc>' . htmlspecialchars(site_url('/ai/' . $tool['slug'] . '/'), ENT_XML1) . '</loc><lastmod>' . e($updated) . '</lastmod></url>' . "\n";
}

foreach ($aiNews as $item) {
    $publishedDay = substr((string) ($item['published_at'] ?? ''), 0, 10);
    $lastmod = preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedDay) ? $publishedDay : $updated;
    $sitemap .= '  <url><loc>' . htmlspecialchars(site_url('/news/' . (int) $item['id'] . '/'), ENT_XML1) . '</loc><lastmod>' . e($lastmod) . '</lastmod></url>' . "\n";
}

$builtDetails = 0;
foreach ($providers as $p) {
    $related = [];
    foreach ($providers as $candidate) {
        if ($candidate['slug'] === $p['slug']) {
            continue;
        }
        if ($candidate['line_type'] === $p['line_type']) {
            $related[] = $candidate;
        }
    }
    usort($related, fn ($a, $b) => $b['region_count'] <=> $a['region_count']);
    $related = array_slice($related, 0, 4);
    if (count($related) < 4) {
        foreach ($providers as $candidate) {
            if ($candidate['slug'] === $p['slug'] || in_array($candidate['slug'], array_column($related, 'slug'), true)) {
                continue;
            }
            $related[] = $candidate;
            if (count($related) >= 4) {
                break;
            }
        }
    }

    $offers = null;
    if ($p['price_from'] !== null) {
        $offers = [
            '@type' => 'Offer',
            'price' => (float) $p['price_from'],
            'priceCurrency' => $p['price_currency'] ?: 'CNY',
            'url' => site_url('/providers/' . $p['slug'] . '/'),
            'availability' => 'https://schema.org/InStock',
        ];
    }
    $product = [
        '@type' => 'Product',
        'name' => $p['name'],
        'description' => $p['summary'],
        'brand' => ['@type' => 'Brand', 'name' => $p['name']],
        'url' => site_url('/providers/' . $p['slug'] . '/'),
    ];
    if ($offers) {
        $product['offers'] = $offers;
    }
    $breadcrumbs = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => site_url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '机场对比', 'item' => site_url('/providers/')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $p['name'], 'item' => site_url('/providers/' . $p['slug'] . '/')],
        ],
    ];

    write_file($out . '/providers/' . $p['slug'] . '/index.html', render_page('provider', [
        'p' => $p,
        'related' => $related,
        'title' => $p['name'] . '：套餐价格、线路与地区 — ' . $siteName,
        'description' => $p['summary'],
        'canonical' => '/providers/' . $p['slug'] . '/',
        'body_class' => 'page-provider',
        'jsonld' => [
            '@context' => 'https://schema.org',
            '@graph' => [$product, $breadcrumbs],
        ],
    ]));
    $builtDetails++;

    $sitemap .= '  <url><loc>' . htmlspecialchars(site_url('/providers/' . $p['slug'] . '/'), ENT_XML1) . '</loc><lastmod>' . e($updated) . '</lastmod></url>' . "\n";
}
$sitemap .= '</urlset>' . "\n";
write_file($out . '/sitemap.xml', $sitemap);

$robots = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /go/\n\nSitemap: " . site_url('/sitemap.xml') . "\n";
write_file($out . '/robots.txt', $robots);

$index = compact_index($providers);
write_file($out . '/data/providers.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');

printf(
    "\nDone: %d provider pages, %d AI tool pages, %d news pages, %d static routes, sitemap %d URLs\n",
    $builtDetails,
    $builtToolPages,
    $builtNewsPages,
    count($staticRoutes) + 2,
    count($staticRoutes) + $builtDetails + $builtToolPages + $builtNewsPages
);
