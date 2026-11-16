#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Probe token management.
 *
 *   php bin/probe_token.php issue --label="电信-广东-01" [--probe-id=probe-xxxx]
 *   php bin/probe_token.php list
 *   php bin/probe_token.php revoke <probe_id>
 *   php bin/probe_token.php activate <probe_id>
 *
 * Tokens are shown once at issue time; only their SHA-256 hash is stored.
 */

probe_ensure_schema();
$args = $argv ?? [];
$command = $args[1] ?? 'help';

function option_value(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return '';
}

switch ($command) {
    case 'issue':
        $probeId = option_value($args, 'probe-id') ?: probe_new_id();
        $label = option_value($args, 'label');
        try {
            $issued = probe_issue_token($probeId, $label);
        } catch (PDOException $exc) {
            fwrite(STDERR, "issue failed: probe_id already exists? " . $exc->getMessage() . "\n");
            exit(1);
        }
        echo "probe_id : {$issued['probe_id']}\n";
        echo "token    : {$issued['token']}\n";
        echo "\n把 token 写入探针 config.yaml 的 report.token；token 只显示这一次。\n";
        echo "示例：aixianlu-probe run --upload -c config.yaml\n";
        break;

    case 'list':
        $rows = db()->query(
            'SELECT probe_id, label, status, created_at, last_report_at FROM probe_tokens ORDER BY id DESC'
        )->fetchAll();
        if (!$rows) {
            echo "暂无探针 token。用 issue 子命令创建。\n";
            break;
        }
        printf("%-26s %-10s %-20s %-20s %s\n", 'probe_id', 'status', 'created_at (UTC)', 'last_report (UTC)', 'label');
        foreach ($rows as $row) {
            printf(
                "%-26s %-10s %-20s %-20s %s\n",
                $row['probe_id'],
                $row['status'],
                $row['created_at'],
                $row['last_report_at'] ?: '—',
                $row['label']
            );
        }
        break;

    case 'revoke':
    case 'activate':
        $probeId = $args[2] ?? '';
        if ($probeId === '') {
            fwrite(STDERR, "usage: probe_token.php {$command} <probe_id>\n");
            exit(1);
        }
        $status = $command === 'revoke' ? 'revoked' : 'active';
        $stmt = db()->prepare('UPDATE probe_tokens SET status = ? WHERE probe_id = ?');
        $stmt->execute([$status, $probeId]);
        echo $stmt->rowCount() > 0 ? "{$probeId} → {$status}\n" : "未找到 {$probeId}\n";
        break;

    default:
        echo "usage: php bin/probe_token.php {issue|list|revoke|activate} [options]\n";
        exit($command === 'help' ? 0 : 1);
}
