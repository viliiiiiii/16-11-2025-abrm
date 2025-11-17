<?php
// includes/notification_service.php
// Helper functions to call the Python notification microservice

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

if (!function_exists('notification_service_base_url')) {
    function notification_service_base_url(): string
    {
        $base = defined('NOTIFICATIONS_SERVICE_URL') && NOTIFICATIONS_SERVICE_URL
            ? NOTIFICATIONS_SERVICE_URL
            : (getenv('NOTIFICATIONS_SERVICE_URL') ?: 'http://127.0.0.1:8001');
        return rtrim($base, '/');
    }
}

if (!function_exists('notification_service_log')) {
    function notification_service_log(string $message): void
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = '[' . date('c') . '] ' . $message . PHP_EOL;
        error_log($line, 3, $dir . '/notifications.log');
    }
}

if (!function_exists('notification_service_request')) {
    function notification_service_request(string $endpoint, array $payload = [], string $method = 'POST'): ?array
    {
        $url = notification_service_base_url() . $endpoint;
        $ch = curl_init();
        $headers = ['Content-Type: application/json'];
        $normalizedMethod = strtoupper($method);
        if ($normalizedMethod === 'GET') {
            if ($payload) {
                $query = http_build_query($payload);
                $url .= (str_contains($url, '?') ? '&' : '?') . $query;
            }
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch, CURLOPT_POST, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            notification_service_log('Request failed: ' . $error);
            return null;
        }

        if ($status >= 400) {
            notification_service_log('Service responded with HTTP ' . $status . ': ' . $response);
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
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
    function notify_toast($userId, string $message, string $type = 'info', array $context = []): bool
    {
        if (!$userId) {
            return false;
        }
        $payload = [
            'user_id' => $userId,
            'message' => $message,
            'type'    => in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info',
            'context' => $context,
        ];
        $response = notification_service_request('/api/notifications/toast', $payload, 'POST');
        return is_array($response) && !empty($response['ok']);
    }
}

if (!function_exists('notify_push')) {
    function notify_push($userId, string $title, string $body, ?string $url = null, ?string $icon = null): bool
    {
        if (!$userId) {
            return false;
        }
        $payload = [
            'user_id' => $userId,
            'title'   => $title,
            'body'    => $body,
        ];
        if ($url) {
            $payload['url'] = $url;
        }
        if ($icon) {
            $payload['icon'] = $icon;
        }
        $response = notification_service_request('/api/notifications/push', $payload, 'POST');
        return is_array($response) && !empty($response['ok']);
    }
}

if (!function_exists('notify_register_subscription')) {
    function notify_register_subscription($userId, array $subscription): bool
    {
        if (!$userId) {
            return false;
        }
        $payload = [
            'user_id'     => $userId,
            'subscription'=> $subscription,
        ];
        $response = notification_service_request('/api/notifications/register-subscription', $payload, 'POST');
        return is_array($response) && !empty($response['ok']);
    }
}

if (!function_exists('notification_service_fetch_toasts')) {
    function notification_service_fetch_toasts($userId): array
    {
        if (!$userId) {
            return [];
        }
        $response = notification_service_request('/api/notifications/toast', ['user_id' => $userId], 'GET');
        if (!is_array($response) || empty($response['ok'])) {
            return [];
        }
        return isset($response['items']) && is_array($response['items']) ? $response['items'] : [];
    }
}
