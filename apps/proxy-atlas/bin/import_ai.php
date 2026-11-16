#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db();
ensure_schema($pdo);

$pdo->beginTransaction();
try {
    $tools = seed_ai_tools($pdo);
    $fits = rebuild_provider_ai_fits($pdo);
    $pdo->commit();
    echo "seeded {$tools} AI tools, rebuilt {$fits} provider fit rows\n";
    echo "note: rows marked 'verified' are preserved; all other evidence rows are rebuilt from provider data\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'AI import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
