<?php

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

echo 'OK';

// The host's log viewer is unreliable, so recent PHP errors are readable here with a key derived from SMS2_APP_KEY.
if (isset($_GET['log'])) {
    $appKey = (string) getenv('SMS2_APP_KEY');
    $expected = $appKey !== '' ? hash_hmac('sha256', 'sms2-error-log', $appKey) : '';
    if ($expected === '' || !hash_equals($expected, (string) $_GET['log'])) {
        exit;
    }
    $logFile = __DIR__ . '/storage/logs/php-error.log';
    $lines = is_readable($logFile) ? (array) file($logFile, FILE_IGNORE_NEW_LINES) : [];
    echo "\n" . implode("\n", array_slice($lines, -200));
    exit;
}

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
}
