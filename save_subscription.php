<?php
require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data[CSRF_TOKEN_NAME] ?? null);
if (!verify_csrf_token($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$user = current_user();
$userId = isset($user['id']) ? (int)$user['id'] : null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth_required']);
    exit;
}

$subscription = $data['subscription'] ?? null;
if (!is_array($subscription)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_subscription']);
    exit;
}

if (!notify_register_subscription($userId, $subscription)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
    exit;
}

echo json_encode(['ok' => true]);
