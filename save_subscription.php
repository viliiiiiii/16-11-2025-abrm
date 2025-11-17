<?php
// Legacy endpoint for service worker subscriptions (mirrors the YouTube tutorial flow).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/includes/notifications.php';

require_login();

$me = current_user();
$localUserId = $me && !empty($me['id']) ? notif_resolve_local_user_id((int)$me['id']) : null;
if (!$localUserId) {
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unable to resolve user.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload) || empty($payload['subscription'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Invalid subscription payload.']);
    exit;
}

$subscription = $payload['subscription'];
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if (!notif_save_push_subscription($localUserId, $subscription, $userAgent)) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Subscription missing endpoint or keys.']);
    exit;
}

notif_set_global_preferences($localUserId, ['allow_push' => true]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok']);
