<?php

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

// Caches keep preference lookups cheap across a single request.
$GLOBALS['notif_type_pref_cache'] = $GLOBALS['notif_type_pref_cache'] ?? [];
$GLOBALS['notif_global_pref_cache'] = $GLOBALS['notif_global_pref_cache'] ?? [];

function notif_pdo(): PDO
{
    return get_pdo();
}

function notif_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Invalid table name supplied.');
    }

    try {
        $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function notif_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Invalid table name supplied.');
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    if ($stmt) {
        foreach ($stmt as $row) {
            if (isset($row['Field'])) {
                $out[] = (string)$row['Field'];
            }
        }
    }

    return $out;
}

function notif_ensure_notifications_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = notif_pdo();
    $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `actor_user_id` int DEFAULT NULL,
        `type` varchar(64) NOT NULL,
        `entity_type` varchar(64) DEFAULT NULL,
        `entity_id` bigint DEFAULT NULL,
        `title` varchar(255) DEFAULT NULL,
        `body` text,
        `data` json DEFAULT NULL,
        `url` varchar(500) DEFAULT NULL,
        `is_read` tinyint(1) NOT NULL DEFAULT '0',
        `read_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_notifications_user` (`user_id`,`is_read`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Table already exists or cannot be created – downstream functions fail gracefully.
    }
}

function notif_notifications_column_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    notif_ensure_notifications_table();

    $map = [
        'id'             => 'id',
        'user_id'        => 'user_id',
        'actor_user_id'  => 'actor_user_id',
        'type'           => 'type',
        'entity_type'    => 'entity_type',
        'entity_id'      => 'entity_id',
        'title'          => 'title',
        'body'           => 'body',
        'data'           => 'data',
        'url'            => 'url',
        'is_read'        => 'is_read',
        'read_at'        => 'read_at',
        'created_at'     => 'created_at',
        'updated_at'     => 'updated_at',
    ];

    return $map;
}

function notif_settings_table(): string
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $pdo = notif_pdo();
    $table = 'notification_settings';
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `user_id` int NOT NULL,
        `allow_in_app` tinyint(1) NOT NULL DEFAULT '1',
        `allow_email` tinyint(1) NOT NULL DEFAULT '0',
        `allow_push` tinyint(1) NOT NULL DEFAULT '1',
        `categories` json DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`),
        CONSTRAINT `fk_notification_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
    }

    $legacy = 'notification_global_preferences';
    if (notif_table_exists($pdo, $legacy)) {
        $columns = notif_table_columns($pdo, $legacy);
        if ($columns) {
            $map = [
                'type_task'           => 'task',
                'type_note'           => 'note',
                'type_system'         => 'system',
                'type_password_reset' => 'password_reset',
                'type_security'       => 'security',
                'type_digest'         => 'digest',
                'type_marketing'      => 'marketing',
            ];
            $parts = [];
            foreach ($map as $column => $key) {
                if (in_array($column, $columns, true)) {
                    $parts[] = "'{$key}'";
                    $parts[] = "`{$column}`";
                }
            }
            $jsonExpr = $parts ? ('JSON_OBJECT(' . implode(', ', $parts) . ')') : 'NULL';
            $sql = "INSERT IGNORE INTO `{$table}` (user_id, allow_in_app, allow_email, allow_push, categories, created_at, updated_at)
                    SELECT user_id, allow_in_app, allow_email, allow_push, {$jsonExpr}, created_at, updated_at FROM `{$legacy}`";
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
            }
        }
    }

    return $table;
}

function notif_preferences_table(): string
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $pdo = notif_pdo();
    $table = 'notification_preferences';
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `user_id` int NOT NULL,
        `notif_type` varchar(64) NOT NULL,
        `allow_web` tinyint(1) NOT NULL DEFAULT '1',
        `allow_email` tinyint(1) NOT NULL DEFAULT '0',
        `allow_push` tinyint(1) NOT NULL DEFAULT '0',
        `mute_until` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`,`notif_type`),
        CONSTRAINT `fk_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
    }

    $legacy = 'notification_type_prefs';
    if (notif_table_exists($pdo, $legacy)) {
        $sql = "INSERT IGNORE INTO `{$table}` (user_id, notif_type, allow_web, allow_email, allow_push, mute_until, created_at, updated_at)
                SELECT user_id, notif_type, allow_web, allow_email, allow_push, mute_until, NOW(), NOW()
                  FROM `{$legacy}`";
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }

    return $table;
}

function notif_push_table(): string
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $pdo = notif_pdo();
    $table = 'push_subscriptions';
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `endpoint` varchar(500) NOT NULL,
        `p256dh` varchar(255) DEFAULT NULL,
        `auth` varchar(255) DEFAULT NULL,
        `user_agent` varchar(255) NOT NULL DEFAULT '',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_endpoint` (`endpoint`),
        KEY `idx_push_user` (`user_id`),
        CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
    }

    $legacy = 'notification_devices';
    if (notif_table_exists($pdo, $legacy)) {
        $columns = notif_table_columns($pdo, $legacy);
        $selectCols = ['user_id', 'endpoint', 'p256dh', 'auth', 'user_agent', 'created_at', 'last_used_at'];
        $missing = array_diff($selectCols, $columns);
        if (!$missing) {
            $sql = "INSERT IGNORE INTO `{$table}` (user_id, endpoint, p256dh, auth, user_agent, created_at, last_used_at)
                    SELECT user_id, endpoint, p256dh, auth, user_agent, created_at, last_used_at
                      FROM `{$legacy}`
                     WHERE `kind` = 'webpush' OR `kind` IS NULL";
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
            }
        }
    }

    return $table;
}

function notif_normalize_row(array $row): array
{
    $map = notif_notifications_column_map();
    $payload = [];
    if (!empty($row[$map['data']])) {
        $decoded = json_decode((string)$row[$map['data']], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    return [
        'id'            => (int)($row[$map['id']] ?? 0),
        'user_id'       => isset($row[$map['user_id']]) ? (int)$row[$map['user_id']] : null,
        'type'          => $row[$map['type']] ?? 'system.alert',
        'title'         => $row[$map['title']] ?? '',
        'body'          => $row[$map['body']] ?? '',
        'url'           => $row[$map['url']] ?? null,
        'is_read'       => !empty($row[$map['is_read']]),
        'read_at'       => $row[$map['read_at']] ?? null,
        'created_at'    => $row[$map['created_at']] ?? null,
        'payload'       => $payload,
    ];
}

function notif_type_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'task.assigned' => [
            'label'            => 'Task assignments',
            'description'      => 'Alerts when someone assigns a task to you or your team.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => true],
        ],
        'task.unassigned' => [
            'label'            => 'Task unassigned',
            'description'      => 'Heads up when a task is no longer assigned to you.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'task.updated' => [
            'label'            => 'Task progress',
            'description'      => 'Updates when priority, due dates, or status change on tasks you follow.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'note.activity' => [
            'label'            => 'Note collaboration',
            'description'      => 'Comments, mentions, and edits on notes you created or follow.',
            'category'         => 'note',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'system.broadcast' => [
            'label'            => 'System announcements',
            'description'      => 'Release notes and scheduled maintenance updates from the team.',
            'category'         => 'system',
            'default_channels' => ['web' => true, 'email' => true, 'push' => false],
        ],
        'system.alert' => [
            'label'            => 'Critical system alerts',
            'description'      => 'Immediate warnings about high-impact events.',
            'category'         => 'system',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.login_alert' => [
            'label'            => 'Sign-in alerts',
            'description'      => 'Alerts when a new browser or device signs in with your account.',
            'category'         => 'security',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.password_change' => [
            'label'            => 'Password changed',
            'description'      => 'Confirms when your account password is updated.',
            'category'         => 'password_reset',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.password_reset' => [
            'label'            => 'Password reset requests',
            'description'      => 'Notifies you when a password reset link is requested.',
            'category'         => 'password_reset',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'digest.weekly' => [
            'label'            => 'Weekly digest',
            'description'      => 'Friday recap email with overdue tasks and unread notes.',
            'category'         => 'digest',
            'default_channels' => ['web' => true, 'email' => true, 'push' => false],
        ],
        'marketing.campaign' => [
            'label'            => 'Product updates & tips',
            'description'      => 'Occasional product announcements and webinars.',
            'category'         => 'marketing',
            'default_channels' => ['web' => false, 'email' => true, 'push' => false],
        ],
    ];

    return $catalog;
}

function notif_type_category(string $type): string
{
    $catalog = notif_type_catalog();
    if (isset($catalog[$type]['category'])) {
        return (string)$catalog[$type]['category'];
    }

    if (str_starts_with($type, 'task.')) {
        return 'task';
    }
    if (str_starts_with($type, 'note.')) {
        return 'note';
    }
    if (str_starts_with($type, 'security.')) {
        return 'security';
    }
    if (str_starts_with($type, 'system.')) {
        return 'system';
    }
    if (str_starts_with($type, 'digest.')) {
        return 'digest';
    }
    if (str_starts_with($type, 'marketing.')) {
        return 'marketing';
    }

    return 'system';
}

function notif_notification_categories(): array
{
    static $categories = null;
    if ($categories !== null) {
        return $categories;
    }

    $categories = ['task', 'note', 'system', 'security', 'password_reset', 'digest', 'marketing'];
    return $categories;
}

function notif_default_preferences(): array
{
    $types = [];
    foreach (notif_notification_categories() as $category) {
        $types[$category] = $category !== 'marketing';
    }

    return [
        'allow_in_app' => true,
        'allow_email'  => false,
        'allow_push'   => true,
        'types'        => $types,
    ];
}

function notif_get_global_preferences(int $userId): array
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return notif_default_preferences();
    }

    global $notif_global_pref_cache;
    if (isset($notif_global_pref_cache[$userId])) {
        return $notif_global_pref_cache[$userId];
    }

    $table = notif_settings_table();
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('SELECT allow_in_app, allow_email, allow_push, categories FROM `' . $table . '` WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }

    $defaults = notif_default_preferences();
    if (!$row) {
        return $notif_global_pref_cache[$userId] = $defaults;
    }

    $prefs = [
        'allow_in_app' => !empty($row['allow_in_app']),
        'allow_email'  => !empty($row['allow_email']),
        'allow_push'   => !empty($row['allow_push']),
        'types'        => $defaults['types'],
    ];

    if (!empty($row['categories'])) {
        $decoded = json_decode((string)$row['categories'], true);
        if (is_array($decoded)) {
            foreach ($prefs['types'] as $key => $value) {
                if (array_key_exists($key, $decoded)) {
                    $prefs['types'][$key] = !empty($decoded[$key]);
                }
            }
        }
    }

    return $notif_global_pref_cache[$userId] = $prefs;
}

function notif_set_global_preferences(int $userId, array $prefs): void
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }

    $current = notif_get_global_preferences($userId);
    $merged = $current;
    if (array_key_exists('allow_in_app', $prefs)) {
        $merged['allow_in_app'] = !empty($prefs['allow_in_app']);
    }
    if (array_key_exists('allow_email', $prefs)) {
        $merged['allow_email'] = !empty($prefs['allow_email']);
    }
    if (array_key_exists('allow_push', $prefs)) {
        $merged['allow_push'] = !empty($prefs['allow_push']);
    }
    if (!empty($prefs['types']) && is_array($prefs['types'])) {
        foreach ($merged['types'] as $key => $value) {
            if (array_key_exists($key, $prefs['types'])) {
                $merged['types'][$key] = !empty($prefs['types'][$key]);
            }
        }
    }

    $table = notif_settings_table();
    $pdo = notif_pdo();
    $json = json_encode($merged['types'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = 'INSERT INTO `' . $table . '` (user_id, allow_in_app, allow_email, allow_push, categories, created_at, updated_at)
            VALUES (:u, :in_app, :email, :push, :cats, NOW(), NOW())
            ON DUPLICATE KEY UPDATE allow_in_app = VALUES(allow_in_app), allow_email = VALUES(allow_email),
            allow_push = VALUES(allow_push), categories = VALUES(categories), updated_at = VALUES(updated_at)';
    try {
        $pdo->prepare($sql)->execute([
            ':u'      => $userId,
            ':in_app' => $merged['allow_in_app'] ? 1 : 0,
            ':email'  => $merged['allow_email'] ? 1 : 0,
            ':push'   => $merged['allow_push'] ? 1 : 0,
            ':cats'   => $json,
        ]);
    } catch (Throwable $e) {
    }

    global $notif_global_pref_cache, $notif_type_pref_cache;
    $notif_global_pref_cache[$userId] = $merged;
    if (!empty($notif_type_pref_cache[$userId])) {
        unset($notif_type_pref_cache[$userId]);
    }
}

function notif_forget_type_pref_cache(int $userId, ?string $type = null): void
{
    global $notif_type_pref_cache;
    if ($type === null) {
        unset($notif_type_pref_cache[$userId]);
        return;
    }

    if (!empty($notif_type_pref_cache[$userId])) {
        unset($notif_type_pref_cache[$userId][$type]);
        if (!$notif_type_pref_cache[$userId]) {
            unset($notif_type_pref_cache[$userId]);
        }
    }
}

function notif_resolve_local_user_id(?int $userId): ?int
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }
    } catch (Throwable $e) {
    }

    $coreEmail = null;
    $coreRole = null;
    try {
        if (function_exists('core_user_record')) {
            $core = core_user_record($userId);
            if ($core) {
                $coreEmail = $core['email'] ?? null;
                $coreRole  = $core['role_key'] ?? ($core['role'] ?? null);
            }
        }
    } catch (Throwable $e) {
    }

    if (!$coreEmail) {
        return $cache[$userId] = null;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $coreEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }

        $role = 'user';
        $roleKey = is_string($coreRole) ? strtolower($coreRole) : '';
        if (in_array($roleKey, ['admin', 'manager', 'root'], true)) {
            $role = 'admin';
        }

        $password = password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT);
        $ins = $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (:email, :hash, :role, NOW())');
        $ins->execute([
            ':email' => $coreEmail,
            ':hash'  => $password,
            ':role'  => $role,
        ]);

        return $cache[$userId] = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        try {
            error_log('notif_resolve_local_user_id failed for ' . $userId . ': ' . $e->getMessage());
        } catch (Throwable $_) {
        }
    }

    return $cache[$userId] = null;
}

function notif_resolve_local_user_ids(array $userIds): array
{
    $out = [];
    foreach ($userIds as $uid) {
        $local = notif_resolve_local_user_id((int)$uid);
        if ($local) {
            $out[] = $local;
        }
    }
    return $out;
}

function notif_set_type_pref(int $userId, string $type, array $prefs): void
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }

    $table = notif_preferences_table();
    $pdo = notif_pdo();

    $allowWeb = !empty($prefs['allow_web']) ? 1 : 0;
    $allowEmail = !empty($prefs['allow_email']) ? 1 : 0;
    $allowPush = !empty($prefs['allow_push']) ? 1 : 0;
    $muteUntil = isset($prefs['mute_until']) && $prefs['mute_until'] !== '' ? $prefs['mute_until'] : null;

    $sql = 'INSERT INTO `' . $table . '` (user_id, notif_type, allow_web, allow_email, allow_push, mute_until, created_at, updated_at)
            VALUES (:u, :t, :web, :email, :push, :mute, NOW(), NOW())
            ON DUPLICATE KEY UPDATE allow_web = VALUES(allow_web), allow_email = VALUES(allow_email),
            allow_push = VALUES(allow_push), mute_until = VALUES(mute_until), updated_at = VALUES(updated_at)';
    try {
        $pdo->prepare($sql)->execute([
            ':u'    => $userId,
            ':t'    => $type,
            ':web'  => $allowWeb,
            ':email'=> $allowEmail,
            ':push' => $allowPush,
            ':mute' => $muteUntil,
        ]);
    } catch (Throwable $e) {
    }

    notif_forget_type_pref_cache($userId, $type);
}

function notif_get_type_pref(int $userId, string $type): array
{
    $userId = (int)$userId;
    $type = (string)$type;
    $key = $userId . ':' . $type;

    global $notif_type_pref_cache;
    if (isset($notif_type_pref_cache[$key])) {
        return $notif_type_pref_cache[$key];
    }

    $catalog = notif_type_catalog();
    $meta = $catalog[$type] ?? [
        'category' => notif_type_category($type),
        'default_channels' => ['web' => true, 'email' => false, 'push' => false],
    ];
    $global = notif_get_global_preferences($userId);
    $category = notif_type_category($type);

    $defaults = [
        'allow_web'   => !empty($meta['default_channels']['web']),
        'allow_email' => !empty($meta['default_channels']['email']),
        'allow_push'  => !empty($meta['default_channels']['push']),
        'mute_until'  => null,
    ];

    $table = notif_preferences_table();
    $pdo = notif_pdo();
    $row = null;
    try {
        $stmt = $pdo->prepare('SELECT allow_web, allow_email, allow_push, mute_until FROM `' . $table . '` WHERE user_id = :u AND notif_type = :t LIMIT 1');
        $stmt->execute([':u' => $userId, ':t' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }

    if ($row) {
        $defaults['allow_web'] = !empty($row['allow_web']);
        $defaults['allow_email'] = !empty($row['allow_email']);
        $defaults['allow_push'] = !empty($row['allow_push']);
        $defaults['mute_until'] = $row['mute_until'] ?? null;
    }

    if (empty($global['allow_in_app'])) {
        $defaults['allow_web'] = false;
    }
    if (empty($global['allow_email'])) {
        $defaults['allow_email'] = false;
    }
    if (empty($global['allow_push']) || empty($global['types'][$category] ?? true)) {
        $defaults['allow_push'] = false;
    }

    return $notif_type_pref_cache[$key] = $defaults;
}

function notif_emit(array $args): ?int
{
    $userId = isset($args['user_id']) ? (int)$args['user_id'] : 0;
    if ($userId <= 0) {
        return null;
    }

    $type = (string)($args['type'] ?? 'system.alert');
    $prefs = notif_get_type_pref($userId, $type);
    $now = new DateTimeImmutable('now');
    if (!empty($prefs['mute_until'])) {
        try {
            $mute = new DateTimeImmutable((string)$prefs['mute_until']);
            if ($mute > $now) {
                return null;
            }
        } catch (Throwable $e) {
        }
    }

    $allowWeb = !empty($prefs['allow_web']);
    $allowPush = !empty($prefs['allow_push']);
    $allowEmail = !empty($prefs['allow_email']);

    if (!$allowWeb && !$allowPush && !$allowEmail) {
        return null;
    }

    $pdo = notif_pdo();
    $map = notif_notifications_column_map();

    $columns = ['`' . $map['user_id'] . '`', '`' . $map['actor_user_id'] . '`', '`' . $map['type'] . '`', '`' . $map['entity_type'] . '`', '`' . $map['entity_id'] . '`', '`' . $map['title'] . '`', '`' . $map['body'] . '`', '`' . $map['data'] . '`', '`' . $map['url'] . '`', '`' . $map['is_read'] . '`', '`' . $map['read_at'] . '`'];
    $placeholders = [':user_id', ':actor', ':type', ':entity_type', ':entity_id', ':title', ':body', ':data', ':url', ':is_read', ':read_at'];

    $payload = !empty($args['data']) ? json_encode($args['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt = $pdo->prepare('INSERT INTO notifications (' . implode(', ', $columns) . ')
                            VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute([
        ':user_id'     => $userId,
        ':actor'       => isset($args['actor_user_id']) ? (int)$args['actor_user_id'] : null,
        ':type'        => $type,
        ':entity_type' => $args['entity_type'] ?? null,
        ':entity_id'   => isset($args['entity_id']) ? (int)$args['entity_id'] : null,
        ':title'       => $args['title'] ?? null,
        ':body'        => $args['body'] ?? null,
        ':data'        => $payload,
        ':url'         => $args['url'] ?? null,
        ':is_read'     => $allowWeb ? 0 : 1,
        ':read_at'     => $allowWeb ? null : $now->format('Y-m-d H:i:s'),
    ]);

    $notificationId = (int)$pdo->lastInsertId();

    if ($notificationId && $allowPush) {
        $push = [
            'title' => $args['title'] ?? 'Notification',
            'body'  => $args['body'] ?? '',
            'url'   => $args['url'] ?? '/',
            'data'  => [
                'notification_id' => $notificationId,
                'type'            => $type,
                'url'             => $args['url'] ?? '/',
            ],
        ];
        if (!empty($args['push']) && is_array($args['push'])) {
            $push = array_merge($push, $args['push']);
        }
        notif_send_push_now($userId, $push);
    }

    return $notificationId;
}

function notif_broadcast(array $userIds, array $payload): array
{
    $ids = [];
    foreach ($userIds as $uid) {
        $payload['user_id'] = (int)$uid;
        $id = notif_emit($payload);
        if ($id) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function notif_unread_count(int $userId): int
{
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function notif_recent_unread(int $userId, int $limit = 3): array
{
    $pdo = notif_pdo();
    $map = notif_notifications_column_map();
    try {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    return array_map('notif_normalize_row', $rows);
}

function notif_list(int $userId, int $limit = 20, int $offset = 0): array
{
    $pdo = notif_pdo();
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    try {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    return array_map('notif_normalize_row', $rows);
}

function notif_recent(int $userId, int $limit = 3): array
{
    $pdo = notif_pdo();
    $limit = max(1, $limit);
    try {
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    return array_map('notif_normalize_row', $rows);
}

function notif_mark_read(int $userId, int $notifId): void
{
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $notifId, ':u' => $userId]);
    } catch (Throwable $e) {
    }
}

function notif_mark_unread(int $userId, int $notifId): void
{
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $notifId, ':u' => $userId]);
    } catch (Throwable $e) {
    }
}

function notif_delete(int $userId, int $notifId): void
{
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $notifId, ':u' => $userId]);
    } catch (Throwable $e) {
    }
}

function notif_mark_all_read(int $userId): void
{
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = :u AND is_read = 0');
        $stmt->execute([':u' => $userId]);
    } catch (Throwable $e) {
    }
}

function notif_touch_web_device(int $userId, string $userAgent): void
{
    // No-op: push subscriptions now represent real browsers/devices only.
}

function notif_fetch_devices(int $userId, ?string $kind = null): array
{
    $table = notif_push_table();
    $pdo = notif_pdo();
    try {
        $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth, user_agent, created_at, last_used_at FROM `' . $table . '` WHERE user_id = :u ORDER BY COALESCE(last_used_at, created_at) DESC');
        $stmt->execute([':u' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    return array_map(static function (array $row): array {
        return [
            'id'           => (int)($row['id'] ?? 0),
            'kind'         => 'webpush',
            'endpoint'     => $row['endpoint'] ?? '',
            'p256dh'       => $row['p256dh'] ?? null,
            'auth'         => $row['auth'] ?? null,
            'user_agent'   => $row['user_agent'] ?? '',
            'created_at'   => $row['created_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
        ];
    }, $rows);
}

function notif_fetch_push_subscriptions(int $userId): array
{
    return notif_fetch_devices($userId, 'webpush');
}

function notif_delete_push_subscription(int $userId, ?int $subscriptionId = null, ?string $endpoint = null): bool
{
    if (!$subscriptionId && !$endpoint) {
        return false;
    }

    $table = notif_push_table();
    $pdo = notif_pdo();
    $where = ['user_id = :u'];
    $params = [':u' => $userId];
    if ($subscriptionId) {
        $where[] = 'id = :id';
        $params[':id'] = $subscriptionId;
    }
    if ($endpoint) {
        $where[] = 'endpoint = :ep';
        $params[':ep'] = $endpoint;
    }

    $sql = 'DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $where);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function notif_save_push_subscription(int $userId, array $subscription, string $userAgent = ''): bool
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }

    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = isset($keys['p256dh']) ? trim((string)$keys['p256dh']) : null;
    $auth = isset($keys['auth']) ? trim((string)$keys['auth']) : null;
    if ($endpoint === '' || !$p256dh || !$auth) {
        return false;
    }

    $table = notif_push_table();
    $pdo = notif_pdo();
    $sql = 'INSERT INTO `' . $table . '` (user_id, endpoint, p256dh, auth, user_agent, created_at, last_used_at)
            VALUES (:u, :ep, :p, :a, :ua, NOW(), NOW())
            ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent), last_used_at = NOW()';
    try {
        $pdo->prepare($sql)->execute([
            ':u'  => $userId,
            ':ep' => $endpoint,
            ':p'  => $p256dh,
            ':a'  => $auth,
            ':ua' => substr($userAgent, 0, 255),
        ]);
    } catch (Throwable $e) {
        try {
            error_log('notif_save_push_subscription failed: ' . $e->getMessage());
        } catch (Throwable $_) {
        }
        return false;
    }

    return true;
}

function notif_vapid_config(): array
{
    return [
        'VAPID' => [
            'subject' => defined('WEB_PUSH_VAPID_SUBJECT') ? WEB_PUSH_VAPID_SUBJECT : '',
            'publicKey' => defined('WEB_PUSH_VAPID_PUBLIC_KEY') ? WEB_PUSH_VAPID_PUBLIC_KEY : '',
            'privateKey' => defined('WEB_PUSH_VAPID_PRIVATE_KEY') ? WEB_PUSH_VAPID_PRIVATE_KEY : '',
        ],
    ];
}

function notif_vapid_ready(): bool
{
    $cfg = notif_vapid_config();
    return !empty($cfg['VAPID']['publicKey']) && !empty($cfg['VAPID']['privateKey']) && !empty($cfg['VAPID']['subject']);
}

function notif_admin_core_user_ids(): array
{
    if (!function_exists('core_admin_user_ids')) {
        return [];
    }

    try {
        return core_admin_user_ids();
    } catch (Throwable $e) {
        return [];
    }
}

function notif_alert_admins(string $type, string $title, string $body, ?string $url = null, array $data = []): void
{
    $coreIds = notif_admin_core_user_ids();
    if (!$coreIds) {
        return;
    }

    $localIds = notif_resolve_local_user_ids($coreIds);
    if (!$localIds) {
        return;
    }

    notif_broadcast($localIds, [
        'type'  => $type,
        'title' => $title,
        'body'  => $body,
        'url'   => $url,
        'data'  => $data,
    ]);
}

function notif_track_login(int $coreUserId, ?string $ip, ?string $userAgent): void
{
    $localId = notif_resolve_local_user_id($coreUserId);
    if (!$localId) {
        return;
    }

    notif_emit([
        'user_id' => $localId,
        'type'    => 'security.login_alert',
        'title'   => 'New sign-in detected',
        'body'    => ($ip ? ('From ' . $ip) : 'New browser or device') . ($userAgent ? ' · ' . $userAgent : ''),
        'data'    => [
            'ip'  => $ip,
            'ua'  => $userAgent,
        ],
    ]);
}

function notif_handle_log_event(string $action, ?string $entityType, ?int $entityId, array $meta, ?array $actor, ?string $ip): void
{
    if ($action === 'task_status_changed' && $entityId) {
        $title = 'Task status changed';
        $body = 'Task #' . $entityId . ' status changed to ' . ($meta['status'] ?? '');
        notif_alert_admins('task.updated', $title, $body, '/task_view.php?id=' . $entityId, [
            'actor' => $actor,
            'ip'    => $ip,
        ]);
    }
}

function notif_send_push_now(int $userId, array $message): array
{
    $result = [
        'sent'    => 0,
        'failed'  => 0,
        'removed' => 0,
        'errors'  => [],
    ];

    if (!notif_vapid_ready()) {
        $result['errors'][] = 'VAPID keys missing';
        return $result;
    }

    $subscriptions = notif_fetch_push_subscriptions($userId);
    if (!$subscriptions) {
        return $result;
    }

    $payload = json_encode([
        'title' => $message['title'] ?? 'Notification',
        'body'  => $message['body'] ?? '',
        'url'   => $message['url'] ?? '/',
        'icon'  => $message['icon'] ?? null,
        'data'  => $message['data'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $config = notif_vapid_config();
    $webPush = new WebPush($config['VAPID'], ['TTL' => $message['ttl'] ?? 300]);

    foreach ($subscriptions as $sub) {
        if (empty($sub['endpoint']) || empty($sub['p256dh']) || empty($sub['auth'])) {
            continue;
        }
        $subscription = Subscription::create([
            'endpoint' => $sub['endpoint'],
            'keys'     => [
                'p256dh' => $sub['p256dh'],
                'auth'   => $sub['auth'],
            ],
        ]);

        try {
            $report = $webPush->sendOneNotification($subscription, $payload);
            if ($report->isSuccess()) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $reason = $report->getReason() ?: 'Delivery failed';
                $result['errors'][] = $reason;
                if ($report->isSubscriptionExpired()) {
                    if (notif_delete_push_subscription($userId, (int)$sub['id'], $sub['endpoint'])) {
                        $result['removed']++;
                    }
                }
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = $e->getMessage();
            if (str_contains(strtolower($e->getMessage()), 'gone')) {
                if (notif_delete_push_subscription($userId, (int)$sub['id'], $sub['endpoint'])) {
                    $result['removed']++;
                }
            }
        }
    }

    return $result;
}

function notif_broadcast_push(array $userIds, array $message): array
{
    $summary = [
        'checked' => 0,
        'sent'    => 0,
        'failed'  => 0,
        'removed' => 0,
        'errors'  => [],
    ];

    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) {
            continue;
        }
        $summary['checked']++;
        $result = notif_send_push_now($uid, $message);
        $summary['sent'] += $result['sent'];
        $summary['failed'] += $result['failed'];
        $summary['removed'] += $result['removed'];
        $summary['errors'] = array_merge($summary['errors'], $result['errors']);
    }

    return $summary;
}
