<?php
// save_subscription.php

require_once __DIR__ . '/config.php'; // where NOTIFICATIONS_SERVICE_URL, etc. live

// Match the session bootstrapping that the rest of the app uses so we can
// actually read the authenticated user's id (otherwise PHP starts a new
// session with the default name and $_SESSION is empty).
if (session_status() === PHP_SESSION_NONE) {
    if (defined('SESSION_NAME') && SESSION_NAME) {
        session_name(SESSION_NAME);
    }
    session_start();
}

// Optional: get your logged-in user id from session
$userId = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null;
// Or fall back if you are testing without auth
if ($userId === null) {
    $userId = 'guest';
}

// Read raw JSON body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data) || !isset($data['subscription'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid subscription payload'
    ]);
    exit;
}

$subscription = $data['subscription'];

// Prepare payload for Python notification microservice
$payload = [
    'user_id'     => $userId,
    'subscription'=> $subscription,
];

// Call Python service
$url = rtrim(NOTIFICATIONS_SERVICE_URL, '/') . '/api/notifications/register-subscription';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 5,
]);

$responseBody = curl_exec($ch);
$curlErr      = curl_error($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Simple logging if something goes wrong talking to Python
if ($curlErr || $httpCode >= 400) {
    // Make sure logs/ exists and is writable by www-data
    $logLine = sprintf(
        "[%s] save_subscription: error calling %s | http=%s | curlErr=%s | body=%s\n",
        date('Y-m-d H:i:s'),
        $url,
        $httpCode,
        $curlErr,
        $responseBody
    );
    @file_put_contents(__DIR__ . '/logs/notifications.log', $logLine, FILE_APPEND);

    // Still return 200 to the browser if you don't want the UI to break,
    // or return 500 if you want to treat it as real failure.
}

// Return success to JS
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'Subscription saved (or queued)'
]);
