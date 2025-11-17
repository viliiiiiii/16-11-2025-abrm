<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$me = current_user();
$userId = (int)($me['id'] ?? 0);
$localUserId = $userId ? notif_resolve_local_user_id($userId) : null;

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$sanitizeDevices = static function (array $rows): array {
    return array_map(static function (array $row): array {
        return [
            'id'           => (int)($row['id'] ?? 0),
            'kind'         => $row['kind'] ?? '',
            'user_agent'   => $row['user_agent'] ?? '',
            'created_at'   => $row['created_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
        ];
    }, $rows);
};

$statusPayload = static function () use ($localUserId, $sanitizeDevices): array {
    $global = notif_get_global_preferences($localUserId);
    return [
        'ok'          => true,
        'allow_push'  => !empty($global['allow_push']),
        'vapid_ready' => notif_vapid_ready(),
        'devices'     => $sanitizeDevices(notif_fetch_push_subscriptions($localUserId)),
    ];
};

if (!$localUserId) {
    $respond(['ok' => false, 'error' => 'profile_unavailable'], 409);
}

$raw = file_get_contents('php://input');
$data = [];
if ($raw !== '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$input = array_merge($_POST, $data);
$intent = strtolower((string)($input['intent'] ?? 'status'));

if ($intent !== 'status') {
    if (!verify_csrf_token($input[CSRF_TOKEN_NAME] ?? null)) {
        $respond(['ok' => false, 'error' => 'csrf'], 422);
    }
}

try {
    if ($intent === 'status') {
        $respond($statusPayload());
    }

    if ($intent === 'unsubscribe') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            notif_delete_push_subscription($localUserId, null, $endpoint);
        }
        $respond($statusPayload());
    }

    if ($intent === 'disable') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            notif_delete_push_subscription($localUserId, null, $endpoint);
        }
        $pdo = notif_pdo();
        try {
            $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = :uid')->execute([':uid' => $localUserId]);
        } catch (Throwable $e) {
        }
        notif_set_global_preferences($localUserId, ['allow_push' => false]);
        $respond($statusPayload());
    }

    if ($intent !== 'subscribe') {
        $respond(['ok' => false, 'error' => 'bad_intent'], 400);
    }

    $subscription = $input['subscription'] ?? null;
    if (!is_array($subscription)) {
        $respond(['ok' => false, 'error' => 'missing_subscription'], 422);
    }

    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if (!notif_save_push_subscription($localUserId, $subscription, $userAgent)) {
        $respond(['ok' => false, 'error' => 'invalid_subscription'], 422);
    }

    notif_set_global_preferences($localUserId, ['allow_push' => true]);

    $respond($statusPayload());
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()], 500);
}
