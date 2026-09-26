<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    smsMarkCurrentUserNotificationRead((int) ($_POST['notification_id'] ?? 0));
    smsMarkCurrentUserSyntheticNotificationRead((string) ($_POST['batch_key'] ?? ''));
}

// Building the list only reads the session. Release its lock first, so pages the user opens
// meanwhile do not wait for this poll's database queries.
session_write_close();

$items = smsNotificationPayloadForCurrentUser();
echo json_encode([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);
