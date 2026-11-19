#!/usr/bin/env php
<?php
/**
 * QIDUS ASSET — CLI-Only Idempotent Database Migration & Seed Runner
 *
 * Usage:
 *   php database/migrate.php
 *   php database/migrate.php --seed
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/config/bootstrap.php';

use QidusAsset\Database\Connection;

$withSeed = in_array('--seed', $argv, true);
$pdo = Connection::get();

// Ensure migration ledger table exists before scanning files.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `qa_schema_migrations` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `migration` VARCHAR(150) NOT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_qa_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT migration FROM qa_schema_migrations')
    ->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied ?: []);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($appliedSet[$name])) {
        echo "[SKIP] {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read migration: {$file}");
    }

    $statements = qa_split_sql_statements($sql);
    echo "[APPLY] {$name} (" . count($statements) . " statements)\n";
    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }

    $ins = $pdo->prepare('INSERT INTO qa_schema_migrations (migration) VALUES (:m)');
    $ins->execute([':m' => $name]);
}

if ($withSeed) {
    $seedFiles = glob(__DIR__ . '/seeds/*.sql') ?: [];
    sort($seedFiles, SORT_STRING);
    foreach ($seedFiles as $seed) {
        $sql = file_get_contents($seed);
        if ($sql === false) {
            continue;
        }
        $statements = qa_split_sql_statements($sql);
        echo "[SEED] " . basename($seed) . " (" . count($statements) . " statements)\n";
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
    }
}

echo "Migrations complete.\n";

/**
 * Split multi-statement SQL file cleanly while ignoring `--` line comments.
 *
 * @return list<string>
 */
function qa_split_sql_statements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || $trimmed === '') {
            continue;
        }
        $clean[] = $line;
    }
    $joined = implode("\n", $clean);
    $parts = array_map('trim', explode(';', $joined));
    return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
}
