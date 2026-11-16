<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/admin_chrome.php';

require_admin();

$pdo = db();
$isNew = isset($_GET['new']);
$slugParam = trim((string) ($_GET['slug'] ?? ''));
$provider = $slugParam !== '' ? provider_by_slug($slugParam) : null;

if (!$isNew && !$provider) {
    admin_flash('机场不存在。', 'error');
    redirect('/admin/providers.php');
}

$defaults = [
    'id' => null,
    'slug' => '',
    'name' => '',
    'name_en' => '',
    'founded' => null,
    'summary' => '',
    'line_type' => '中转',
    'protocols' => [],
    'regions' => [],
    'regions_common' => [],
    'regions_other' => [],
    'node_count' => 0,
    'node_count_disclosed' => 0,
    'node_count_note' => '',
    'media_unlock' => [],
    'ai_unlock' => [],
    'features' => [],
    'payments' => [],
    'tags' => [],
    'discount_code' => '',
    'discount_label' => '',
    'monthly_offline' => 0,
    'plan_note' => '',
    'official_url' => '',
    'affiliate_url' => '',
    'source_review_url' => '',
    'source_home_url' => '',
    'source_updated_at' => date('Y-m-d'),
    'status' => 'draft',
    'source_order' => 0,
    'weight' => 0,
    'price_from' => null,
    'price_from_period' => '',
    'price_currency' => 'CNY',
    'traffic_from' => null,
    'unit_price_from' => null,
    'monthly_price_from' => null,
    'plans' => [],
];
$provider = $provider ?? $defaults;
$errors = [];

function split_list(string $value): array
{
    $parts = preg_split('/[\r\n,，、;；]+/u', $value) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && !in_array($part, $out, true)) {
            $out[] = $part;
        }
    }
    return $out;
}

function is_valid_url(string $url): bool
{
    return $url === '' || (bool) preg_match('#^https?://[^\s]+$#i', $url);
}

function empty_to_null(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float) $value : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $errors[] = '会话已过期，请重新提交。';
    } elseif (($_POST['action'] ?? '') === 'delete' && $provider['id']) {
        $stmt = $pdo->prepare('DELETE FROM providers WHERE id = ?');
        $stmt->execute([(int) $provider['id']]);
        admin_flash('已删除机场「' . $provider['name'] . '」。');
        redirect('/admin/providers.php');
    } elseif (($_POST['action'] ?? '') === 'save') {
        $posted = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'name_en' => trim((string) ($_POST['name_en'] ?? '')),
            'founded' => ($_POST['founded'] ?? '') !== '' ? (int) $_POST['founded'] : null,
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            'line_type' => trim((string) ($_POST['line_type'] ?? '未标注')),
            'status' => ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'source_order' => (int) ($_POST['source_order'] ?? 0),
            'weight' => (int) ($_POST['weight'] ?? 0),
            'protocols' => split_list((string) ($_POST['protocols'] ?? '')),
            'regions' => split_list((string) ($_POST['regions'] ?? '')),
            'regions_common' => split_list((string) ($_POST['regions_common'] ?? '')),
            'regions_other' => split_list((string) ($_POST['regions_other'] ?? '')),
            'node_count' => max(0, (int) ($_POST['node_count'] ?? 0)),
            'node_count_disclosed' => !empty($_POST['node_count_disclosed']) ? 1 : 0,
            'node_count_note' => trim((string) ($_POST['node_count_note'] ?? '')),
            'media_unlock' => split_list((string) ($_POST['media_unlock'] ?? '')),
            'ai_unlock' => split_list((string) ($_POST['ai_unlock'] ?? '')),
            'features' => split_list((string) ($_POST['features'] ?? '')),
            'payments' => split_list((string) ($_POST['payments'] ?? '')),
            'tags' => split_list((string) ($_POST['tags'] ?? '')),
            'discount_code' => trim((string) ($_POST['discount_code'] ?? '')),
            'discount_label' => trim((string) ($_POST['discount_label'] ?? '')),
            'monthly_offline' => !empty($_POST['monthly_offline']) ? 1 : 0,
            'plan_note' => trim((string) ($_POST['plan_note'] ?? '')),
            'official_url' => trim((string) ($_POST['official_url'] ?? '')),
            'affiliate_url' => trim((string) ($_POST['affiliate_url'] ?? '')),
            'source_review_url' => trim((string) ($_POST['source_review_url'] ?? '')),
            'source_home_url' => trim((string) ($_POST['source_home_url'] ?? '')),
            'source_updated_at' => trim((string) ($_POST['source_updated_at'] ?? '')),
        ];

        if ($posted['name'] === '') {
            $errors[] = '机场名称不能为空。';
        }
        if ($isNew) {
            if ($posted['slug'] === '') {
                $posted['slug'] = slugify($posted['name']);
            }
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $posted['slug'])) {
                $errors[] = 'slug 只能包含小写字母、数字和连字符，长度 2–80。';
            } else {
                $check = $pdo->prepare('SELECT id FROM providers WHERE slug = ? LIMIT 1');
                $check->execute([$posted['slug']]);
                if ($check->fetchColumn()) {
                    $errors[] = 'slug 已被占用，请换一个。';
                }
            }
        }
        foreach (['official_url', 'affiliate_url', 'source_review_url', 'source_home_url'] as $urlKey) {
            if (!is_valid_url($posted[$urlKey])) {
                $errors[] = $urlKey . ' 不是有效的 http(s) 链接。';
            }
        }
        if ($posted['source_updated_at'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted['source_updated_at'])) {
            $errors[] = '数据更新时间格式应为 YYYY-MM-DD。';
        }

        $planNames = (array) ($_POST['plan_name'] ?? []);
        $planLabels = (array) ($_POST['plan_label'] ?? []);
        $planPrices = (array) ($_POST['plan_price'] ?? []);
        $planCurrencies = (array) ($_POST['plan_currency'] ?? []);
        $planPeriods = (array) ($_POST['plan_period'] ?? []);
        $planMonths = (array) ($_POST['plan_months'] ?? []);
        $planTraffic = (array) ($_POST['plan_traffic'] ?? []);
        $planTrafficPeriods = (array) ($_POST['plan_traffic_period'] ?? []);
        $planSpeeds = (array) ($_POST['plan_speed'] ?? []);
        $planDevices = (array) ($_POST['plan_device'] ?? []);
        $planNotes = (array) ($_POST['plan_notes'] ?? []);
        $entryIndex = isset($_POST['entry_index']) && $_POST['entry_index'] !== '' ? (string) $_POST['entry_index'] : null;

        $plans = [];
        foreach (array_keys($planNames) as $idx) {
            $name = trim((string) ($planNames[$idx] ?? ''));
            $price = empty_to_null($planPrices[$idx] ?? null);
            if ($name === '' && $price === null) {
                continue;
            }
            if ($price === null || $price <= 0) {
                $errors[] = '套餐「' . ($name ?: ('#' . $idx)) . '」的价格必须大于 0。';
                continue;
            }
            $months = max(0, (int) ($planMonths[$idx] ?? 1));
            $currency = in_array(($planCurrencies[$idx] ?? 'CNY'), ['CNY', 'USD'], true) ? $planCurrencies[$idx] : 'CNY';
            $period = trim((string) ($planPeriods[$idx] ?? '月付')) ?: '月付';
            $trafficGb = empty_to_null($planTraffic[$idx] ?? null);
            $trafficPeriod = in_array(($planTrafficPeriods[$idx] ?? 'month'), ['month', 'quarter', 'year'], true) ? $planTrafficPeriods[$idx] : 'month';
            $monthlyPrice = $months > 0 ? $price / $months : $price;
            $monthlyTraffic = $trafficGb;
            if ($trafficGb !== null) {
                if ($trafficPeriod === 'year') {
                    $monthlyTraffic = $trafficGb / 12;
                } elseif ($trafficPeriod === 'quarter') {
                    $monthlyTraffic = $trafficGb / 3;
                }
            }
            $unit = ($monthlyPrice > 0 && $monthlyTraffic) ? $monthlyPrice / $monthlyTraffic : null;
            $plans[] = [
                'name' => $name ?: '套餐',
                'option_label' => trim((string) ($planLabels[$idx] ?? '')),
                'price' => round($price, 2),
                'currency' => $currency,
                'period' => $period,
                'period_months' => $months,
                'traffic_gb' => $trafficGb,
                'traffic_period' => $trafficPeriod,
                'monthly_price' => round($monthlyPrice, 2),
                'monthly_traffic_gb' => $monthlyTraffic !== null ? round($monthlyTraffic, 2) : null,
                'unit_price' => $unit !== null ? round($unit, 4) : null,
                'speed_limit' => trim((string) ($planSpeeds[$idx] ?? '')),
                'device_limit' => ($planDevices[$idx] ?? '') !== '' ? (int) $planDevices[$idx] : null,
                'notes' => trim((string) ($planNotes[$idx] ?? '')),
                'is_entry' => $entryIndex !== null && (string) $idx === $entryIndex ? 1 : 0,
                'sort' => count($plans),
            ];
        }

        if ($plans && !array_filter($plans, fn ($plan) => !empty($plan['is_entry']))) {
            $plans[0]['is_entry'] = 1;
        }
        $entry = null;
        foreach ($plans as $plan) {
            if (!empty($plan['is_entry'])) {
                $entry = $plan;
                break;
            }
        }

        $summary = $posted['summary'];
        if ($summary === '' && $posted['name'] !== '') {
            $bits = [];
            if ($posted['founded']) {
                $bits[] = $posted['founded'] . ' 年成立';
            }
            $bits[] = $posted['line_type'];
            if ($posted['protocols']) {
                $bits[] = '支持 ' . implode('、', $posted['protocols']);
            }
            if ($posted['regions']) {
                $bits[] = '覆盖 ' . count($posted['regions']) . ' 个地区';
            }
            if ($entry) {
                $bits[] = '入门 ' . format_price($entry['price'], $entry['currency']) . '/' . period_short($entry['period']);
                if ($entry['monthly_traffic_gb']) {
                    $bits[] = format_traffic((float) $entry['monthly_traffic_gb']) . '/月';
                }
            }
            $summary = implode('，', $bits) . '。';
        }

        $provider = array_merge($provider, $posted, [
            'summary' => $summary,
            'plans' => $plans,
            'region_count' => count($posted['regions']),
            'node_count' => $posted['node_count'] > 0 ? $posted['node_count'] : count($posted['regions']),
            'plan_count' => count($plans),
            'price_from' => $entry['price'] ?? null,
            'price_from_period' => $entry['period'] ?? '',
            'price_currency' => $entry['currency'] ?? 'CNY',
            'traffic_from' => $entry['monthly_traffic_gb'] ?? null,
            'unit_price_from' => $entry['unit_price'] ?? null,
            'monthly_price_from' => ($entry && $entry['period_months'] > 0) ? $entry['monthly_price'] : null,
        ]);

        if (!$errors) {
            $fields = [
                'name' => $provider['name'],
                'name_en' => $provider['name_en'],
                'founded' => $provider['founded'],
                'summary' => $provider['summary'],
                'line_type' => $provider['line_type'],
                'protocols' => json_col($provider['protocols']),
                'regions' => json_col($provider['regions']),
                'regions_common' => json_col($provider['regions_common']),
                'regions_other' => json_col($provider['regions_other']),
                'media_unlock' => json_col($provider['media_unlock']),
                'ai_unlock' => json_col($provider['ai_unlock']),
                'features' => json_col($provider['features']),
                'payments' => json_col($provider['payments']),
                'tags' => json_col($provider['tags']),
                'discount_code' => $provider['discount_code'],
                'discount_label' => $provider['discount_label'],
                'monthly_offline' => $provider['monthly_offline'],
                'plan_note' => $provider['plan_note'],
                'official_url' => $provider['official_url'],
                'affiliate_url' => $provider['affiliate_url'],
                'source_review_url' => $provider['source_review_url'],
                'source_home_url' => $provider['source_home_url'],
                'source_updated_at' => $provider['source_updated_at'],
                'status' => $provider['status'],
                'source_order' => $provider['source_order'],
                'weight' => $provider['weight'],
                'region_count' => $provider['region_count'],
                'node_count' => $provider['node_count'],
                'node_count_disclosed' => $provider['node_count_disclosed'],
                'node_count_note' => $provider['node_count_note'],
                'plan_count' => $provider['plan_count'],
                'price_from' => $provider['price_from'],
                'price_from_period' => $provider['price_from_period'],
                'price_currency' => $provider['price_currency'],
                'traffic_from' => $provider['traffic_from'],
                'unit_price_from' => $provider['unit_price_from'],
                'monthly_price_from' => $provider['monthly_price_from'],
            ];

            $pdo->beginTransaction();
            try {
                if ($isNew) {
                    $fields['slug'] = $provider['slug'];
                    $cols = array_keys($fields);
                    $placeholders = array_map(fn ($col) => ':' . $col, $cols);
                    $stmt = $pdo->prepare('INSERT INTO providers (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')');
                    $stmt->execute($fields);
                    $providerId = (int) $pdo->lastInsertId();
                } else {
                    $sets = [];
                    foreach (array_keys($fields) as $col) {
                        $sets[] = $col . ' = :' . $col;
                    }
                    $stmt = $pdo->prepare('UPDATE providers SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $fields['id'] = (int) $provider['id'];
                    $stmt->execute($fields);
                    $providerId = (int) $provider['id'];
                }

                $pdo->prepare('DELETE FROM plans WHERE provider_id = ?')->execute([$providerId]);
                $insert = $pdo->prepare(
                    'INSERT INTO plans (provider_id, name, option_label, price, currency, period, period_months,
                        traffic_gb, traffic_period, monthly_price, monthly_traffic_gb, unit_price,
                        speed_limit, device_limit, notes, is_entry, sort)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($plans as $plan) {
                    $insert->execute([
                        $providerId, $plan['name'], $plan['option_label'], $plan['price'], $plan['currency'],
                        $plan['period'], $plan['period_months'], $plan['traffic_gb'], $plan['traffic_period'],
                        $plan['monthly_price'], $plan['monthly_traffic_gb'], $plan['unit_price'],
                        $plan['speed_limit'], $plan['device_limit'], $plan['notes'], $plan['is_entry'], $plan['sort'],
                    ]);
                }
                $pdo->commit();
                admin_flash('已保存「' . $provider['name'] . '」。前台需重新构建后更新。');
                redirect('/admin/provider-edit.php?slug=' . urlencode($provider['slug']));
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = '保存失败：' . $e->getMessage();
            }
        }
    }
}

$lineOptions = ['专线', '专线+中转', '中转', '直连', '未标注'];
$plansForForm = $provider['plans'] ?? [];
$slugValue = $provider['slug'] ?? '';
$entryId = null;
foreach ($plansForForm as $idx => $plan) {
    if (!empty($plan['is_entry'])) {
        $entryId = (string) $idx;
        break;
    }
}

admin_header($isNew ? '新增机场' : '编辑：' . $provider['name'], 'providers');
?>

<?php if ($errors): ?>
  <div class="admin-flash is-error">
    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="/admin/provider-edit.php<?= $isNew ? '?new=1' : '?slug=' . e(urlencode($slugValue)) ?>">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">

  <div class="admin-card">
    <h2>基本信息</h2>
    <div class="admin-form">
      <div class="admin-field">
        <label for="name">名称 *</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= e($provider['name']) ?>">
      </div>
      <div class="admin-field">
        <label for="slug">Slug（URL 路径）<?= $isNew ? ' *' : '' ?></label>
        <input id="slug" name="slug" type="text" maxlength="80" value="<?= e($slugValue) ?>" <?= $isNew ? '' : 'readonly' ?> placeholder="例如 wgetcloud">
        <small>小写字母、数字、连字符。详情页路径为 /providers/{slug}/。</small>
      </div>
      <div class="admin-field">
        <label for="name_en">英文名</label>
        <input id="name_en" name="name_en" type="text" maxlength="120" value="<?= e($provider['name_en']) ?>">
      </div>
      <div class="admin-field">
        <label for="founded">成立年份</label>
        <input id="founded" name="founded" type="number" min="1990" max="2100" value="<?= e((string) ($provider['founded'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="line_type">线路类型</label>
        <select id="line_type" name="line_type">
          <?php foreach ($lineOptions as $line): ?>
            <option value="<?= e($line) ?>" <?= $provider['line_type'] === $line ? 'selected' : '' ?>><?= e($line) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="status">状态</label>
        <select id="status" name="status">
          <option value="published" <?= $provider['status'] === 'published' ? 'selected' : '' ?>>已发布（进入静态站）</option>
          <option value="draft" <?= $provider['status'] === 'draft' ? 'selected' : '' ?>>草稿（不进入静态站）</option>
        </select>
      </div>
      <div class="admin-field">
        <label for="source_order">来源序号</label>
        <input id="source_order" name="source_order" type="number" min="0" value="<?= (int) $provider['source_order'] ?>">
      </div>
      <div class="admin-field">
        <label for="weight">排序权重（越大越靠前，首页精选取前 6）</label>
        <input id="weight" name="weight" type="number" value="<?= (int) $provider['weight'] ?>">
      </div>
      <div class="admin-field full">
        <label for="summary">摘要</label>
        <textarea id="summary" name="summary" rows="3" placeholder="留空会根据名称、线路、协议、地区和入门套餐自动生成"><?= e($provider['summary']) ?></textarea>
        <small>使用自己的话概括，不要复制评测原文。</small>
      </div>
    </div>
  </div>

  <div class="admin-card">
    <h2>分类与标签</h2>
    <p class="admin-hint">每行一个，也可用逗号分隔。地区请使用常用中文名（香港、日本、美国）。</p>
    <div class="admin-form">
      <div class="admin-field">
        <label for="protocols">协议</label>
        <textarea id="protocols" name="protocols" rows="3"><?= e(implode("\n", $provider['protocols'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="regions">全部地区</label>
        <textarea id="regions" name="regions" rows="3"><?= e(implode("\n", $provider['regions'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="regions_common">常用地区</label>
        <textarea id="regions_common" name="regions_common" rows="3"><?= e(implode("\n", $provider['regions_common'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="regions_other">其他地区</label>
        <textarea id="regions_other" name="regions_other" rows="3"><?= e(implode("\n", $provider['regions_other'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="node_count">节点数（不同 IP 入口）</label>
        <input id="node_count" name="node_count" type="number" min="0" value="<?= (int) $provider['node_count'] ?>">
        <small>填来源明确披露的数值；留 0 则前台按服务器地区数显示下限。</small>
      </div>
      <div class="admin-field">
        <label for="node_count_note">节点数说明</label>
        <input id="node_count_note" name="node_count_note" type="text" maxlength="160" value="<?= e($provider['node_count_note']) ?>" placeholder="例如：来源明确披露 100+ 个节点">
        <label style="display:flex;align-items:center;gap:.5rem;margin-top:.45rem;font-size:.8rem;color:var(--ink)">
          <input type="checkbox" name="node_count_disclosed" value="1" <?= !empty($provider['node_count_disclosed']) ? 'checked' : '' ?> style="width:auto;min-height:auto">
          来源明确披露节点数
        </label>
      </div>
      <div class="admin-field">
        <label for="media_unlock">流媒体解锁（评测提及）</label>
        <textarea id="media_unlock" name="media_unlock" rows="3"><?= e(implode("\n", $provider['media_unlock'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="ai_unlock">AI 解锁（评测提及）</label>
        <textarea id="ai_unlock" name="ai_unlock" rows="3"><?= e(implode("\n", $provider['ai_unlock'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="features">特性</label>
        <textarea id="features" name="features" rows="3" placeholder="免费试用 / 定制客户端 / 加密货币 / 家宽/原生 IP / 团队/企业 / 邀请制"><?= e(implode("\n", $provider['features'])) ?></textarea>
      </div>
      <div class="admin-field">
        <label for="payments">支付方式</label>
        <textarea id="payments" name="payments" rows="3"><?= e(implode("\n", $provider['payments'])) ?></textarea>
      </div>
      <div class="admin-field full">
        <label for="tags">来源标签</label>
        <textarea id="tags" name="tags" rows="2"><?= e(implode("\n", $provider['tags'])) ?></textarea>
      </div>
    </div>
  </div>

  <div class="admin-card">
    <h2>链接与优惠</h2>
    <div class="admin-form">
      <div class="admin-field">
        <label for="official_url">官网地址</label>
        <input id="official_url" name="official_url" type="url" value="<?= e($provider['official_url']) ?>" placeholder="https://">
        <small>填写后详情页出现“访问官网”，走 /go/{slug} 记录点击。</small>
      </div>
      <div class="admin-field">
        <label for="affiliate_url">推广链接（优先于官网）</label>
        <input id="affiliate_url" name="affiliate_url" type="url" value="<?= e($provider['affiliate_url']) ?>" placeholder="https://">
        <small>可附加 ?a=代理码&subid= 子 ID 由后台手动拼装。</small>
      </div>
      <div class="admin-field">
        <label for="discount_code">优惠码</label>
        <input id="discount_code" name="discount_code" type="text" maxlength="80" value="<?= e($provider['discount_code']) ?>">
      </div>
      <div class="admin-field">
        <label for="discount_label">优惠说明</label>
        <input id="discount_label" name="discount_label" type="text" maxlength="120" value="<?= e($provider['discount_label']) ?>">
      </div>
      <div class="admin-field">
        <label for="source_review_url">来源评测页</label>
        <input id="source_review_url" name="source_review_url" type="url" value="<?= e($provider['source_review_url']) ?>">
      </div>
      <div class="admin-field">
        <label for="source_home_url">来源首页</label>
        <input id="source_home_url" name="source_home_url" type="url" value="<?= e($provider['source_home_url']) ?>">
      </div>
      <div class="admin-field">
        <label for="source_updated_at">数据更新时间</label>
        <input id="source_updated_at" name="source_updated_at" type="date" value="<?= e($provider['source_updated_at']) ?>">
      </div>
      <div class="admin-field">
        <label for="plan_note">套餐备注</label>
        <input id="plan_note" name="plan_note" type="text" maxlength="200" value="<?= e($provider['plan_note']) ?>" placeholder="例如：月付已下线，季付起步">
      </div>
      <div class="admin-field full">
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink)">
          <input type="checkbox" name="monthly_offline" value="1" <?= !empty($provider['monthly_offline']) ? 'checked' : '' ?> style="width:auto;min-height:auto">
          月付已下线（前台会标注，月均排序仍按入门套餐折算）
        </label>
      </div>
    </div>
  </div>

  <div class="admin-section-title">
    <h2>套餐</h2>
    <span>月均、月流量与单价由系统根据价格 / 周期 / 流量自动计算</span>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table plan-editor">
      <thead>
        <tr>
          <th>套餐名</th>
          <th>选项标签</th>
          <th>价格</th>
          <th>币种</th>
          <th>周期</th>
          <th>月数</th>
          <th>流量</th>
          <th>流量周期</th>
          <th>限速</th>
          <th>设备</th>
          <th>备注</th>
          <th>入门</th>
          <th></th>
        </tr>
      </thead>
      <tbody data-plan-rows>
        <?php foreach ($plansForForm as $idx => $plan): ?>
          <tr>
            <td><input class="w-name" type="text" name="plan_name[<?= $idx ?>]" value="<?= e($plan['name']) ?>"></td>
            <td><input class="w-label" type="text" name="plan_label[<?= $idx ?>]" value="<?= e($plan['option_label']) ?>"></td>
            <td><input type="number" step="0.01" min="0" name="plan_price[<?= $idx ?>]" value="<?= e((string) $plan['price']) ?>"></td>
            <td>
              <select name="plan_currency[<?= $idx ?>]">
                <option value="CNY" <?= $plan['currency'] === 'CNY' ? 'selected' : '' ?>>CNY</option>
                <option value="USD" <?= $plan['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
              </select>
            </td>
            <td>
              <select name="plan_period[<?= $idx ?>]" data-period>
                <?php foreach (['月付' => 1, '季付' => 3, '半年付' => 6, '年付' => 12, '按量' => 0] as $period => $months): ?>
                  <option value="<?= e($period) ?>" data-months="<?= $months ?>" <?= $plan['period'] === $period ? 'selected' : '' ?>><?= e($period) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" min="0" max="120" name="plan_months[<?= $idx ?>]" value="<?= (int) $plan['period_months'] ?>" style="width:4rem"></td>
            <td><input type="number" step="0.01" min="0" name="plan_traffic[<?= $idx ?>]" value="<?= e((string) ($plan['traffic_gb'] ?? '')) ?>" style="width:5rem"></td>
            <td>
              <select name="plan_traffic_period[<?= $idx ?>]">
                <option value="month" <?= $plan['traffic_period'] === 'month' ? 'selected' : '' ?>>每月</option>
                <option value="quarter" <?= $plan['traffic_period'] === 'quarter' ? 'selected' : '' ?>>每季</option>
                <option value="year" <?= $plan['traffic_period'] === 'year' ? 'selected' : '' ?>>每年</option>
              </select>
            </td>
            <td><input type="text" name="plan_speed[<?= $idx ?>]" value="<?= e($plan['speed_limit']) ?>" style="width:5rem"></td>
            <td><input type="number" min="0" name="plan_device[<?= $idx ?>]" value="<?= e((string) ($plan['device_limit'] ?? '')) ?>" style="width:4rem"></td>
            <td><input class="w-note" type="text" name="plan_notes[<?= $idx ?>]" value="<?= e($plan['notes']) ?>"></td>
            <td style="text-align:center"><input type="radio" name="entry_index" value="<?= $idx ?>" <?= $entryId === (string) $idx ? 'checked' : '' ?> aria-label="设为入门套餐"></td>
            <td class="actions"><button type="button" class="danger" data-plan-remove>删除</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="admin-actions" style="margin-top:.6rem">
    <button class="btn btn--ghost btn--small" type="button" data-plan-add>＋ 添加套餐</button>
    <span style="font-size:.72rem;color:var(--muted-2)">未勾选“入门”时，默认第一个套餐作为价格摘要。</span>
  </div>

  <template id="plan-row-template">
    <tr>
      <td><input class="w-name" type="text" name="plan_name[__IDX__]"></td>
      <td><input class="w-label" type="text" name="plan_label[__IDX__]" placeholder="月付 · 100G/月"></td>
      <td><input type="number" step="0.01" min="0" name="plan_price[__IDX__]"></td>
      <td>
        <select name="plan_currency[__IDX__]"><option value="CNY">CNY</option><option value="USD">USD</option></select>
      </td>
      <td>
        <select name="plan_period[__IDX__]" data-period>
          <option value="月付" data-months="1">月付</option>
          <option value="季付" data-months="3">季付</option>
          <option value="半年付" data-months="6">半年付</option>
          <option value="年付" data-months="12">年付</option>
          <option value="按量" data-months="0">按量</option>
        </select>
      </td>
      <td><input type="number" min="0" max="120" name="plan_months[__IDX__]" value="1" style="width:4rem"></td>
      <td><input type="number" step="0.01" min="0" name="plan_traffic[__IDX__]" style="width:5rem"></td>
      <td>
        <select name="plan_traffic_period[__IDX__]"><option value="month">每月</option><option value="quarter">每季</option><option value="year">每年</option></select>
      </td>
      <td><input type="text" name="plan_speed[__IDX__]" style="width:5rem"></td>
      <td><input type="number" min="0" name="plan_device[__IDX__]" style="width:4rem"></td>
      <td><input class="w-note" type="text" name="plan_notes[__IDX__]"></td>
      <td style="text-align:center"><input type="radio" name="entry_index" value="__IDX__" aria-label="设为入门套餐"></td>
      <td class="actions"><button type="button" class="danger" data-plan-remove>删除</button></td>
    </tr>
  </template>

  <div class="admin-card" style="margin-top:1rem">
    <h2>当前价格摘要（保存后按入门套餐重算）</h2>
    <div class="admin-kv">
      <div><span>入门价</span><span><?= e(provider_price_label($provider)) ?></span></div>
      <div><span>月均</span><span><?= $provider['monthly_price_from'] !== null ? '￥' . format_number((float) $provider['monthly_price_from'], 2) : '—' ?></span></div>
      <div><span>入门流量</span><span><?= e(format_traffic($provider['traffic_from'])) ?>/月</span></div>
      <div><span>单位流量价</span><span><?= e(format_unit_price($provider['unit_price_from'], $provider['price_currency'])) ?></span></div>
    </div>
  </div>

  <div class="admin-actions">
    <button class="btn btn--primary" type="submit">保存</button>
    <a class="btn btn--ghost" href="/admin/providers.php">返回列表</a>
    <?php if ($provider['slug']): ?>
      <a class="btn btn--ghost" href="/providers/<?= e($provider['slug']) ?>/" target="_blank" rel="noopener">预览前台</a>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew && $provider['id']): ?>
  <form method="post" action="/admin/provider-edit.php?slug=<?= e(urlencode($slugValue)) ?>" style="margin-top:.75rem;text-align:right" data-delete-form data-confirm="确认删除该机场？套餐数据会一并删除，且不可恢复。">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <button class="btn btn--ghost btn--small js-confirm" type="submit">删除机场</button>
  </form>
<?php endif; ?>

<?php admin_footer(); ?>
