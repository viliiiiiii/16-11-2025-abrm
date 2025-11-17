<?php
// includes/notification_service.php
// Legacy-friendly helpers that now proxy to the first-party notification library.

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    if (defined('SESSION_NAME') && SESSION_NAME) {
        session_name(SESSION_NAME);
    }
    session_start();
}

if (!function_exists('queue_toast')) {
    function queue_toast(string $message, string $type = 'info', array $context = []): void
    {
        if (!isset($_SESSION['toasts']) || !is_array($_SESSION['toasts'])) {
            $_SESSION['toasts'] = [];
        }
        $_SESSION['toasts'][] = [
            'message' => $message,
            'type'    => $type,
            'context' => $context,
        ];
    }
}

if (!function_exists('notify_toast')) {
    function notify_toast($userId, string $message, string $variant = 'info', array $context = []): bool
    {
        if (!$userId) {
            return false;
        }
        require_once __DIR__ . '/notifications.php';
        $localId = notif_resolve_local_user_id((int)$userId);
        if (!$localId) {
            return false;
        }
        $payload = [
            'user_id' => $localId,
            'type'    => $context['notif_type'] ?? 'system.alert',
            'title'   => $message,
            'body'    => $context['body'] ?? $message,
            'data'    => array_merge($context, ['variant' => $variant]),
        ];
        return (bool)notif_emit($payload);
    }
}

if (!function_exists('notify_push')) {
    function notify_push($userId, string $title, string $body, ?string $url = null, ?string $icon = null): bool
    {
        if (!$userId) {
            return false;
        }
        require_once __DIR__ . '/notifications.php';
        $localId = notif_resolve_local_user_id((int)$userId);
        if (!$localId) {
            return false;
        }
        $payload = [
            'user_id' => $localId,
            'type'    => 'system.alert',
            'title'   => $title,
            'body'    => $body,
            'url'     => $url,
            'data'    => array_filter([
                'icon' => $icon,
            ]),
        ];
        return (bool)notif_emit($payload);
    }
}

if (!function_exists('notify_register_subscription')) {
    function notify_register_subscription($userId, array $subscription): bool
    {
        if (!$userId) {
            return false;
        }
        require_once __DIR__ . '/notifications.php';
        $localId = notif_resolve_local_user_id((int)$userId);
        if (!$localId) {
            return false;
        }
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        return notif_save_push_subscription($localId, $subscription, $userAgent);
    }
}

if (!function_exists('notification_service_fetch_toasts')) {
    function notification_service_fetch_toasts($userId): array
    {
        // Toasts are persisted via notif_emit() now; the realtime poller reads directly from MySQL.
        return [];
    }
}
