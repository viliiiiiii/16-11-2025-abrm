<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!notif_vapid_ready()) {
    fwrite(STDERR, "VAPID keys are missing. Configure WEB_PUSH_VAPID_* in config.php before sending pushes." . PHP_EOL);
    exit(1);
}

$title = $argv[1] ?? 'Punch List update';
$body  = $argv[2] ?? 'New activity is waiting for you.';
$url   = $argv[3] ?? '/';

$pdo = notif_pdo();
try {
    $stmt = $pdo->query('SELECT DISTINCT user_id FROM push_subscriptions ORDER BY user_id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to load subscribers: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$userIds = array_map('intval', $rows);
if (!$userIds) {
    fwrite(STDOUT, "No push subscriptions found. Register a browser first." . PHP_EOL);
    exit(0);
}

$result = notif_broadcast_push($userIds, [
    'title' => $title,
    'body'  => $body,
    'url'   => $url,
]);

printf("Users checked: %d\nSent: %d\nFailed: %d\nInvalid removed: %d\n", $result['checked'], $result['sent'], $result['failed'], $result['removed']);
if (!empty($result['errors'])) {
    fwrite(STDERR, "Errors:\n" . implode("\n", array_unique($result['errors'])) . "\n");
}

exit($result['failed'] > 0 ? 1 : 0);
