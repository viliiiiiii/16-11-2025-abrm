<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_login();
require_once __DIR__ . '/../includes/notifications.php';

/* ---------- Data source detection ---------- */
function profile_resolve_user_store(): array {
    static $store = null;
    if ($store !== null) {
        return $store;
    }

    // Force "core" as the authoritative users DB.
    // Assumes get_pdo('core') is configured for your core_db database.
    $pdo = get_pdo('core');

    // Sanity check: make sure core.users looks like we expect
    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_map('strval', $cols);
    } catch (Throwable $e) {
        throw new RuntimeException('Could not read columns from core.users');
    }

    if (!in_array('pass_hash', $cols, true)) {
        throw new RuntimeException('core.users is missing expected column pass_hash');
    }

    // Cache the chosen store
    $store = [
        'db_key'          => 'core',
        'schema'          => 'core',
        'password_column' => 'pass_hash',
        'role_column'     => 'role_id',
        'pdo'             => $pdo,
        'columns'         => $cols,
    ];
    return $store;
}


function pick_users_pdo(): PDO
{
    $store = profile_resolve_user_store();
    return $store['pdo'];
}

function profile_store_schema(): string
{
    $store = profile_resolve_user_store();
    return (string)$store['schema'];
}

function profile_password_column(): string
{
    $store = profile_resolve_user_store();
    return (string)$store['password_column'];
}

function fetch_user(PDO $pdo, int $id): ?array
{
    $store = profile_resolve_user_store();
    if ($store['schema'] === 'core') {
        $sql = 'SELECT u.id, u.email, u.pass_hash AS password_hash, u.role_id, u.created_at, '
             . 'u.suspended_at, u.suspended_by, u.sector_id, '
             . 'r.label AS role_label, r.key_slug AS role_key, s.name AS sector_name '
             . 'FROM users u '
             . 'LEFT JOIN roles r   ON r.id = u.role_id '
             . 'LEFT JOIN sectors s ON s.id = u.sector_id '
             . 'WHERE u.id = ?';
    } else {
        $sql = 'SELECT id, email, password_hash, role, created_at FROM users WHERE id = ?';
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if ($store['schema'] === 'core') {
        if (isset($row['role_label']) && $row['role_label'] !== null) {
            $row['role'] = $row['role_label'];
        } elseif (isset($row['role_key'])) {
            $row['role'] = ucfirst(str_replace('_', ' ', (string)$row['role_key']));
        }
    } else {
        $role = (string)($row['role'] ?? '');
        $row['role_label'] = $role === '' ? '' : ucfirst(str_replace('_', ' ', $role));
        $row['role_key']   = $role;
    }

    return $row;
}

function profile_sync_shadow_email(int $userId, string $email, string $sourceSchema): void
{
    if ($sourceSchema !== 'core') {
        try {
            $core = get_pdo('core');
            $stmt = $core->prepare('UPDATE `users` SET `email` = ? WHERE `id` = ?');
            $stmt->execute([$email, $userId]);
        } catch (Throwable $e) {
        }
    }
    if ($sourceSchema !== 'abrm') {
        try {
            $apps = get_pdo();
            $stmt = $apps->prepare('UPDATE `users` SET `email` = ? WHERE `id` = ?');
            $stmt->execute([$email, $userId]);
        } catch (Throwable $e) {
        }
    }
}

function profile_sync_shadow_password(int $userId, string $hash, string $sourceSchema): void
{
    if ($sourceSchema !== 'core') {
        try {
            $core = get_pdo('core');
            $stmt = $core->prepare('UPDATE `users` SET `pass_hash` = ? WHERE `id` = ?');
            $stmt->execute([$hash, $userId]);
        } catch (Throwable $e) {
        }
    }
    if ($sourceSchema !== 'abrm') {
        try {
            $apps = get_pdo();
            $stmt = $apps->prepare('UPDATE `users` SET `password_hash` = ? WHERE `id` = ?');
            $stmt->execute([$hash, $userId]);
        } catch (Throwable $e) {
        }
    }
}

function profile_avatar_initial(?string $email): string
{
    $email = trim((string)$email);
    if ($email === '') {
        return 'U';
    }
    $first = strtoupper($email[0]);
    if (!preg_match('/[A-Z0-9]/', $first)) {
        $first = '#';
    }
    return $first;
}

function profile_format_datetime(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $dt = new DateTimeImmutable((string)$timestamp);
        return $dt->format('M j, Y · H:i');
    } catch (Throwable $e) {
        return (string)$timestamp;
    }
}

function profile_relative_time(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $dt  = new DateTimeImmutable((string)$timestamp);
        $now = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return '';
    }

    $diff = $now->getTimestamp() - $dt->getTimestamp();
    $suffix = $diff >= 0 ? 'ago' : 'from now';
    $diff = abs($diff);

    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
        1        => 'second',
    ];

    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $value = (int)floor($diff / $secs);
            if ($value > 1) {
                $label .= 's';
            }
            return $value . ' ' . $label . ' ' . $suffix;
        }
    }

    return 'just now';
}

function profile_format_ip($raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (function_exists('inet_ntop')) {
        $ip = @inet_ntop((string)$raw);
        if ($ip !== false) {
            return $ip;
        }
    }
    if (is_string($raw) && preg_match('/^[0-9.]+$/', $raw)) {
        return $raw;
    }
    return null;
}

function profile_summarize_user_agent(?string $ua): string
{
    $ua = trim((string)$ua);
    if ($ua === '') {
        return 'Unknown device';
    }

    $uaLower = strtolower($ua);
    $browser = 'Browser';
    if (str_contains($uaLower, 'edg/')) {
        $browser = 'Edge';
    } elseif (str_contains($uaLower, 'chrome')) {
        $browser = 'Chrome';
    } elseif (str_contains($uaLower, 'firefox')) {
        $browser = 'Firefox';
    } elseif (str_contains($uaLower, 'safari')) {
        $browser = 'Safari';
    } elseif (str_contains($uaLower, 'opera') || str_contains($uaLower, 'opr/')) {
        $browser = 'Opera';
    }

    $os = '';
    if (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad')) {
        $os = 'iOS';
    } elseif (str_contains($uaLower, 'android')) {
        $os = 'Android';
    } elseif (str_contains($uaLower, 'windows')) {
        $os = 'Windows';
    } elseif (str_contains($uaLower, 'mac os')) {
        $os = 'macOS';
    } elseif (str_contains($uaLower, 'linux')) {
        $os = 'Linux';
    }

    $parts = array_filter([$browser, $os]);
    return implode(' · ', $parts) ?: $browser;
}

function fetch_recent_security_events(int $userId, int $limit = 6): array
{
    try {
        $pdo = get_pdo('core');
    } catch (Throwable $e) {
        return [];
    }

    try {
        $sql = 'SELECT ts, action, meta, ip, ua FROM activity_log '
             . 'WHERE user_id = :uid AND action IN ("login","logout","user.password_change","user.email_change") '
             . 'ORDER BY ts DESC LIMIT :lim';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $events = [];
    foreach ($rows as $row) {
        $events[] = profile_describe_security_event($row);
    }
    return $events;
}

function profile_describe_security_event(array $row): array
{
    $action = (string)($row['action'] ?? '');
    $ts     = $row['ts'] ?? null;
    $title  = match ($action) {
        'login'               => 'Signed in',
        'logout'              => 'Signed out',
        'user.password_change'=> 'Password updated',
        'user.email_change'   => 'Email updated',
        default               => ucfirst(str_replace('_', ' ', $action)),
    };

    $ip    = profile_format_ip($row['ip'] ?? null);
    $agent = profile_summarize_user_agent($row['ua'] ?? '');
    $metaParts = [];
    if ($ip) {
        $metaParts[] = $ip;
    }
    if ($agent) {
        $metaParts[] = $agent;
    }

    $details = '';
    if (!empty($row['meta'])) {
        $decoded = json_decode((string)$row['meta'], true);
        if (is_array($decoded)) {
            if (isset($decoded['old'], $decoded['new'])) {
                $details = 'Changed ' . (string)$decoded['old'] . ' → ' . (string)$decoded['new'];
            } elseif (isset($decoded['email'])) {
                $details = (string)$decoded['email'];
            }
        }
    }

    return [
        'title'       => $title,
        'meta'        => implode(' • ', $metaParts),
        'details'     => $details,
        'ts'          => $ts,
        'relative'    => profile_relative_time($ts),
        'formatted'   => profile_format_datetime($ts),
    ];
}

function profile_membership_summary(?string $createdAt): array
{
    $summary = [
        'short' => '—',
        'long'  => 'Join date not available.',
    ];

    if (!$createdAt) {
        return $summary;
    }

    try {
        $start = new DateTimeImmutable($createdAt);
        $now   = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return $summary;
    }

    if ($start > $now) {
        $summary['short'] = 'Pending access';
        $summary['long']  = 'Account activates ' . $start->format('M j, Y');
        return $summary;
    }

    $diff   = $start->diff($now);
    $parts  = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' yr' . ($diff->y === 1 ? '' : 's');
    }
    if ($diff->m > 0 && count($parts) < 2) {
        $parts[] = $diff->m . ' mo' . ($diff->m === 1 ? '' : 's');
    }
    if ($diff->d > 0 && count($parts) < 2) {
        $parts[] = $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
    }
    if (!$parts) {
        $parts[] = 'Today';
    }

    $summary['short'] = implode(' ', array_slice($parts, 0, 2));
    $summary['long']  = 'Joined ' . $start->format('M j, Y');
    return $summary;
}

function profile_fetch_sectors(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT id, name FROM sectors ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $sectors = [];
    foreach ($rows as $row) {
        if (!isset($row['id'])) {
            continue;
        }
        $sectors[(int)$row['id']] = (string)($row['name'] ?? '');
    }

    return $sectors;
}

$errors = [];
$me     = current_user();
$userId = (int)($me['id'] ?? 0);

try {
    $pdo  = pick_users_pdo();
    $user = fetch_user($pdo, $userId);
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Profile error</h1><p>Could not access the users table. '
       . 'Make sure it exists on the expected database connection.</p>';
    exit;
}

$storeSchema = profile_store_schema();
$notificationUserId = null;
if (function_exists('notif_resolve_local_user_id')) {
    try {
        $notificationUserId = notif_resolve_local_user_id($userId);
    } catch (Throwable $e) {
        $notificationUserId = null;
    }
}
$notificationsAvailable = $notificationUserId !== null;
$sectorOptions = profile_fetch_sectors($pdo);

/* ---------- POST handlers ---------- */
if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'change_email') {
            $newEmail = trim((string)($_POST['email'] ?? ''));
            if ($newEmail === '') {
                $errors[] = 'Email is required.';
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                try {
                    $st = $pdo->prepare('SELECT 1 FROM `users` WHERE `email` = ? AND `id` <> ? LIMIT 1');
                    $st->execute([$newEmail, $userId]);
                    if ($st->fetchColumn()) {
                        $errors[] = 'That email is already in use.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not validate email uniqueness.';
                }
            }

            if (!$errors) {
                try {
                    $oldEmail = (string)$user['email'];
                    $columnEmail = 'email';
                    $stmt = $pdo->prepare('UPDATE `users` SET `' . $columnEmail . '` = ? WHERE `id` = ?');
                    $stmt->execute([$newEmail, $userId]);

                    profile_sync_shadow_email($userId, $newEmail, $storeSchema);

                    if (function_exists('log_event')) {
                        log_event('user.email_change', 'user', $userId, ['old' => $oldEmail, 'new' => $newEmail]);
                    }
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['email'] = $newEmail;
                    }
                    redirect_with_message('/account/profile.php', 'Email updated.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to update email.';
                }
            }
        }

        if ($action === 'update_sector') {
            $sectorValue = $_POST['sector_id'] ?? '';
            $sectorId = ($sectorValue === '' || $sectorValue === null) ? null : (int)$sectorValue;

            if ($sectorId !== null && !array_key_exists($sectorId, $sectorOptions)) {
                $errors[] = 'Please choose a valid team/sector.';
            }

            if (!$errors) {
                try {
                    if ($sectorId === null) {
                        $stmt = $pdo->prepare('UPDATE `users` SET `sector_id` = NULL WHERE `id` = ?');
                        $stmt->execute([$userId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE `users` SET `sector_id` = ? WHERE `id` = ?');
                        $stmt->execute([$sectorId, $userId]);
                    }

                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['sector_id'] = $sectorId;
                    }

                    redirect_with_message('/account/profile.php', 'Primary team updated.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Could not update your team.';
                }
            }
        }

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                $errors[] = 'All password fields are required.';
            } elseif (!password_verify($current, (string)$user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            }

            if (!$errors) {
                try {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $column = profile_password_column();
                    $stmt = $pdo->prepare('UPDATE `users` SET `' . $column . '` = ? WHERE `id` = ?');
                    $stmt->execute([$hash, $userId]);

                    profile_sync_shadow_password($userId, $hash, $storeSchema);

                    if (function_exists('log_event')) {
                        log_event('user.password_change', 'user', $userId);
                    }

                    redirect_with_message('/account/profile.php', 'Password updated.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to update password.';
                }
            }
        }
        if ($action === 'revoke_device') {
            if (!$notificationsAvailable) {
                $errors[] = 'Notifications are not available right now.';
            } else {
                $deviceId = (int)($_POST['device_id'] ?? 0);
                if ($deviceId <= 0) {
                    $errors[] = 'Device not found.';
                } else {
                    if (notif_delete_push_subscription($notificationUserId, $deviceId)) {
                        redirect_with_message('/account/profile.php', 'Device disconnected.', 'success');
                    } else {
                        $errors[] = 'Device not found or already removed.';
                    }
                }
            }
        }
    }

    try {
        $user = fetch_user($pdo, $userId) ?? $user;
    } catch (Throwable $e) {
    }

}

/* ---------- Derived view data ---------- */
$statusLabel = 'Active';
$statusBadgeClass = 'badge -success';
if (!empty($user['suspended_at'])) {
    $statusLabel = 'Suspended';
    $statusBadgeClass = 'badge -danger';
}
$roleLabel = (string)($user['role'] ?? $user['role_label'] ?? '');
if ($roleLabel === '' && !empty($user['role_key'])) {
    $roleLabel = ucfirst(str_replace('_', ' ', (string)$user['role_key']));
}
$sectorLabel = (string)($user['sector_name'] ?? '');
$securityEvents = fetch_recent_security_events($userId, 6);
$membershipSummary = profile_membership_summary($user['created_at'] ?? null);
$latestSecurityEvent = $securityEvents[0] ?? null;
$lastActiveRelative = (string)($latestSecurityEvent['relative'] ?? '');
$lastActiveFull = (string)($latestSecurityEvent['formatted'] ?? '');
$notificationDevices = ($notificationsAvailable && $notificationUserId)
    ? notif_fetch_devices($notificationUserId)
    : [];
$recentNotifications = ($notificationsAvailable && $notificationUserId)
    ? notif_recent($notificationUserId, 4)
    : [];
$unreadNotificationCount = ($notificationsAvailable && $notificationUserId)
    ? notif_unread_count($notificationUserId)
    : 0;
$pushReady = $notificationsAvailable && notif_vapid_ready();

$title = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>
<div class="account-shell">
  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <section class="account-hero card">
    <div class="account-hero__identity">
      <span class="account-hero__avatar"><?php echo sanitize(profile_avatar_initial($user['email'] ?? '')); ?></span>
      <div class="account-hero__identity-text">
        <p class="account-hero__eyebrow">Account</p>
        <h1 class="account-hero__title"><?php echo sanitize($user['email'] ?? ''); ?></h1>
        <div class="account-hero__chips">
          <?php if ($roleLabel): ?><span class="account-chip"><?php echo sanitize($roleLabel); ?></span><?php endif; ?>
          <?php if ($sectorLabel): ?><span class="account-chip"><?php echo sanitize($sectorLabel); ?></span><?php endif; ?>
        </div>
      </div>
      <span class="badge <?php echo sanitize($statusBadgeClass); ?>"><?php echo sanitize($statusLabel); ?></span>
    </div>
    <dl class="account-hero__stats">
      <div>
        <dt>Joined</dt>
        <dd><?php echo sanitize($membershipSummary['short'] ?? '—'); ?></dd>
      </div>
      <div>
        <dt>Last activity</dt>
        <dd>
          <?php if ($lastActiveRelative !== ''): ?>
            <span><?php echo sanitize($lastActiveRelative); ?></span>
            <?php if ($lastActiveFull): ?><span class="muted"> (<?php echo sanitize($lastActiveFull); ?>)</span><?php endif; ?>
          <?php else: ?>
            <span>Waiting for first sign-in</span>
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt>User ID</dt>
        <dd>#<?php echo (int)$user['id']; ?></dd>
      </div>
      <div>
        <dt>Unread alerts</dt>
        <dd><?php echo (int)$unreadNotificationCount; ?></dd>
      </div>
    </dl>
  </section>

  <div class="account-grid">
    <section class="account-card card" id="account-email">
      <div class="account-card__header">
        <h2>Sign-in email</h2>
        <p>Update the address you use to sign in.</p>
      </div>
      <form method="post" class="account-form">
        <label class="account-field">Email address
          <input type="email" name="email" required value="<?php echo sanitize($user['email'] ?? ''); ?>">
        </label>
        <div class="account-actions">
          <input type="hidden" name="action" value="change_email">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn primary" type="submit">Update email</button>
        </div>
      </form>
    </section>

    <section class="account-card card">
      <div class="account-card__header">
        <h2>Primary team</h2>
        <p>Pick the sector that best represents your work.</p>
      </div>
      <form method="post" class="account-form">
        <label class="account-field">Team
          <select name="sector_id">
            <option value="">No primary team</option>
            <?php $currentSectorId = isset($user['sector_id']) ? (int)$user['sector_id'] : null; ?>
            <?php foreach ($sectorOptions as $id => $name): ?>
              <option value="<?php echo (int)$id; ?>"<?php echo ($currentSectorId !== null && $currentSectorId === (int)$id) ? ' selected' : ''; ?>><?php echo sanitize($name ?: 'Unnamed'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="account-actions">
          <input type="hidden" name="action" value="update_sector">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn secondary" type="submit">Save team</button>
        </div>
      </form>
    </section>

    <section class="account-card card">
      <div class="account-card__header">
        <h2>Password</h2>
        <p>Use a unique, 8+ character password for better security.</p>
      </div>
      <form method="post" class="account-form" autocomplete="off">
        <label class="account-field">Current password
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label class="account-field">New password
          <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="account-field">Confirm new password
          <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <div class="account-actions">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
          <button class="btn secondary" type="submit">Update password</button>
        </div>
      </form>
    </section>

    <section class="account-card card account-card--notifications" id="notification-center">
      <div class="account-card__header">
        <h2>Notifications</h2>
        <p>Push + toast alerts keep you informed.</p>
      </div>
      <div class="account-notify-status">
        <div>
          <p class="account-notify-status__value"><?php echo $pushReady ? 'Push ready' : 'Push unavailable'; ?></p>
          <p class="account-notify-status__meta"><?php echo (int)$unreadNotificationCount; ?> unread alerts</p>
        </div>
        <?php if ($pushReady): ?>
          <button type="button" class="btn primary" data-notification-permission>Allow this browser</button>
        <?php else: ?>
          <p class="muted small">Configure VAPID keys to enable push delivery.</p>
        <?php endif; ?>
      </div>
      <div class="account-device-list">
        <?php if ($notificationDevices): ?>
          <?php foreach ($notificationDevices as $device): ?>
            <div class="account-device">
              <div>
                <p class="account-device__label"><?php echo sanitize(profile_summarize_user_agent($device['user_agent'] ?? 'Browser')); ?></p>
                <?php $stamp = $device['last_used_at'] ?? $device['created_at'] ?? null; ?>
                <p class="account-device__meta"><?php echo $stamp ? sanitize(profile_format_datetime($stamp)) : 'First seen recently'; ?></p>
              </div>
              <form method="post" class="account-device__actions">
                <input type="hidden" name="action" value="revoke_device">
                <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
                <button class="btn secondary small" type="submit">Disconnect</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="muted">No browsers or devices are registered yet.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="account-card card">
      <div class="account-card__header">
        <h2>Recent alerts</h2>
        <p>Your latest push + toast notifications.</p>
      </div>
      <ul class="account-inbox">
        <?php if ($recentNotifications): ?>
          <?php foreach ($recentNotifications as $notification): ?>
            <li class="account-inbox__item<?php echo !empty($notification['is_read']) ? ' is-read' : ''; ?>">
              <div>
                <p class="account-inbox__title">
                  <?php if (!empty($notification['url'])): ?>
                    <a href="<?php echo sanitize($notification['url']); ?>"><?php echo sanitize($notification['title'] ?: 'Notification'); ?></a>
                  <?php else: ?>
                    <?php echo sanitize($notification['title'] ?: 'Notification'); ?>
                  <?php endif; ?>
                </p>
                <?php if (!empty($notification['body'])): ?>
                  <p class="account-inbox__body"><?php echo sanitize($notification['body']); ?></p>
                <?php endif; ?>
                <p class="account-inbox__meta"><?php echo sanitize(profile_relative_time($notification['created_at'] ?? null)); ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li class="account-inbox__item is-empty">New alerts will appear here.</li>
        <?php endif; ?>
      </ul>
      <div class="account-card__footer">
        <a class="btn secondary small" href="/notifications/index.php">Open inbox</a>
      </div>
    </section>

    <section class="account-card card">
      <div class="account-card__header">
        <h2>Security log</h2>
        <p>Sign-ins and credential changes.</p>
      </div>
      <ul class="account-security-list">
        <?php if ($securityEvents): ?>
          <?php foreach ($securityEvents as $event): ?>
            <li class="account-security-item">
              <p class="account-security-item__title"><?php echo sanitize($event['title']); ?></p>
              <p class="account-security-item__meta"><?php echo sanitize($event['meta'] ?: 'Device unknown'); ?></p>
              <?php if (!empty($event['details'])): ?><p class="account-security-item__details"><?php echo sanitize($event['details']); ?></p><?php endif; ?>
              <p class="account-security-item__time"><?php echo sanitize($event['relative'] ?? ''); ?></p>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li class="account-security-item">No recent security events.</li>
        <?php endif; ?>
      </ul>
    </section>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
