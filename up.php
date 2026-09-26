<?php

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

echo 'OK';

// The platform health check hits this file without ?db, so it must never depend on MySQL.
if (!isset($_GET['db'])) {
    exit;
}

require_once __DIR__ . '/config/database.php';

$pdo = db();
if (!$pdo) {
    echo "\ndb=down";
    exit;
}

try {
    $pdo->query('SELECT 1 FROM `users` LIMIT 1');
    echo "\ndb=ok schema=ready";
} catch (Throwable $e) {
    echo "\ndb=ok schema=missing";
    exit;
}

// Runtime logs are not available on the host, so report whether the demo seed ran.
try {
    $seeded = (int) $pdo->query(
        "SELECT COUNT(*) FROM `schema_migrations`
         WHERE `migration_key` IN ('2026_09_27_demo_scheduling_seed', '2026_09_27_demo_scheduling_seed_extra')"
    )->fetchColumn();
} catch (Throwable $e) {
    $seeded = 0;
}
echo ' demo=' . ($seeded === 2 ? 'loaded' : ($seeded === 1 ? 'partial' : 'none'));
