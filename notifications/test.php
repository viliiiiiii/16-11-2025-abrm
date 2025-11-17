<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $me = current_user();
    $coreUserId = (int)($me['id'] ?? 0);
    $localUserId = $coreUserId ? notif_resolve_local_user_id($coreUserId) : null;
    if (!$localUserId) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'profile_unavailable']);
        exit;
    }

    $id = notif_emit([
        'user_id'       => $localUserId,
        'core_user_id'  => $coreUserId,
        'type'          => 'note.shared',
        'title'         => 'Test notification',
        'body'          => 'Hello from notifications/test.php',
        'url'           => '/notifications/index.php',
        'payload'       => ['env' => 'debug'],
    ]);

    if (!$id) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'emit_failed']);
        exit;
    }

    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
