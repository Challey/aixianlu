<?php

declare(strict_types=1);

function db_path(): string
{
    return getenv('PROXY_DB') ?: APP_ROOT . '/storage/proxy-atlas.sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('schema.sql not found');
    }
    $pdo->exec($schema);

    $probeSchema = __DIR__ . '/probe_schema.sql';
    if (is_file($probeSchema)) {
        $pdo->exec((string) file_get_contents($probeSchema));
    }

    // CREATE TABLE IF NOT EXISTS does not add columns to an existing database.
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(providers)')->fetchAll() as $column) {
        $columns[(string) $column['name']] = true;
    }
    foreach ([
        'node_count' => 'INTEGER NOT NULL DEFAULT 0',
        'node_count_disclosed' => 'INTEGER NOT NULL DEFAULT 0',
        'node_count_note' => "TEXT NOT NULL DEFAULT ''",
        'affiliate_code' => "TEXT NOT NULL DEFAULT ''",
        'affiliate_status' => "TEXT NOT NULL DEFAULT 'none'",
        'affiliate_checked_at' => "TEXT NOT NULL DEFAULT ''",
        'affiliate_note' => "TEXT NOT NULL DEFAULT ''",
    ] as $name => $definition) {
        if (!isset($columns[$name])) {
            $pdo->exec("ALTER TABLE providers ADD COLUMN {$name} {$definition}");
        }
    }
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = $value === false ? $default : (string) $value;
    return $cache[$key];
}

function put_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings(key, value) VALUES(?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}
