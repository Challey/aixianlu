#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Build hourly probe snapshots and prune old raw reports.
 *
 *   php bin/probe_snapshot.php [--hours=48] [--prune-days=30]
 *
 * Snapshots are what /api/v1/metrics/latest and the admin dashboard read.
 * Add to cron after the daily build, e.g. hourly:
 *   5 * * * * cd /var/www/aixianlu && php bin/probe_snapshot.php --hours=6 >> storage/logs/probe.log 2>&1
 */

$args = $argv ?? [];
$hours = 48;
$pruneDays = 0;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--hours=')) {
        $hours = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--prune-days=')) {
        $pruneDays = max(0, (int) substr($arg, 13));
    }
}

$written = probe_build_snapshots($hours);
echo "snapshots written: {$written} (window {$hours}h)\n";

if ($pruneDays > 0) {
    $deleted = probe_prune($pruneDays);
    echo "raw reports pruned: {$deleted} (older than {$pruneDays}d)\n";
}
