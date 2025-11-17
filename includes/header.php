<?php
if (!isset($title)) { $title = APP_TITLE; }
$roleKey = current_user_role_key();
$me = current_user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$extraHead = $extraHead ?? [];

function bc_s($s){ return sanitize($s); }

function build_breadcrumbs(string $path): array {
  $crumbs = [
    ['label' => 'Dashboard', 'href' => '/index.php'],
  ];

  $script = basename($path);
  $dir    = trim(dirname($path), '/');

  if ($script === 'tasks.php' || preg_match('#^task_#', $script)) {
    $crumbs[] = ['label' => 'Tasks', 'href' => '/tasks.php'];
    if ($script === 'task_view.php' && isset($_GET['id'])) {
      $id = (int)$_GET['id'];
      $crumbs[] = ['label' => "Task #{$id}", 'href' => null];
    } elseif ($script === 'task_edit.php') {
      $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
      if ($id) {
        $crumbs[] = ['label' => "Task #{$id}", 'href' => "/task_view.php?id={$id}"];
        $crumbs[] = ['label' => 'Edit', 'href' => null];
      } else {
        $crumbs[] = ['label' => 'New Task', 'href' => null];
      }
    }
  }

  if ($script === 'rooms.php' || preg_match('#^rooms(/|$)#', $path)) {
    $crumbs[] = ['label' => 'Rooms', 'href' => '/rooms.php'];
  }

  if ($script === 'inventory.php' || preg_match('#^inventory(/|$)#', $path)) {
    $crumbs[] = ['label' => 'Inventory', 'href' => '/inventory.php'];
  }

  if (str_starts_with($dir, 'notes') || $dir === 'notes') {
    $crumbs[] = ['label' => 'Notes', 'href' => '/notes/index.php'];
    if ($script === 'view.php' && isset($_GET['id'])) {
      $id = (int)$_GET['id'];
      $crumbs[] = ['label' => "Note #{$id}", 'href' => null];
    } elseif ($script === 'edit.php') {
      $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
      if ($id) {
        $crumbs[] = ['label' => "Note #{$id}", 'href' => "/notes/view.php?id={$id}"];
        $crumbs[] = ['label' => 'Edit', 'href' => null];
      } else {
        $crumbs[] = ['label' => 'New Note', 'href' => null];
      }
    }
  }

  if (str_starts_with($dir, 'account') || $dir === 'account') {
    $crumbs[] = ['label' => 'Account', 'href' => '/account/profile.php'];
    if ($script === 'profile.php') {
      $crumbs[] = ['label' => 'Profile', 'href' => null];
    }
  }

  if (str_starts_with($dir, 'admin') || $dir === 'admin') {
    $crumbs[] = ['label' => 'Admin', 'href' => '/admin/activity.php'];
    if ($script === 'users.php')      $crumbs[] = ['label' => 'Users', 'href' => null];
    if ($script === 'sectors.php')    $crumbs[] = ['label' => 'Sectors', 'href' => null];
    if ($script === 'activity.php')   $crumbs[] = ['label' => 'Activity', 'href' => null];
    if ($script === 'settings.php')   $crumbs[] = ['label' => 'Settings', 'href' => null];
  }

  if (count($crumbs) === 1 && ($path !== '/' && $path !== '/index.php')) {
    $label = $script ?: 'Page';
    $crumbs[] = ['label' => $label, 'href' => null];
  }

  return $crumbs;
}

$breadcrumbs = build_breadcrumbs($path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo bc_s($title); ?> - <?php echo bc_s(APP_TITLE); ?></title>
  <link rel="stylesheet" href="/assets/css/app.css?v=2.0">
  <link rel="stylesheet" href="/assets/css/toast.css?v=1">
  <link rel="icon" href="/assets/favicon.ico">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0f111b">
  <meta name="csrf-token" content="<?php echo bc_s(csrf_token()); ?>">
  <?php if (defined('WEB_PUSH_VAPID_PUBLIC_KEY') && WEB_PUSH_VAPID_PUBLIC_KEY): ?>
    <meta name="vapid-public-key" content="<?php echo bc_s(WEB_PUSH_VAPID_PUBLIC_KEY); ?>">
  <?php endif; ?>
  <script type="module" src="/assets/js/app.js?v=2.0" defer></script>
  <script type="module" src="/assets/js/notifications.js?v=1" defer></script>
  <?php foreach ((array)$extraHead as $snippet): ?>
    <?php echo $snippet; ?>
  <?php endforeach; ?>
  <style>
    .breadcrumbs { margin: 0 0 1rem; font-size: .85rem; color: #8da2c0; text-transform: uppercase; letter-spacing: .08em; }
    .breadcrumbs ol { list-style: none; display: flex; flex-wrap: wrap; gap: .35rem; padding: 0; margin: 0; }
    .breadcrumbs li { display: flex; align-items: center; gap: .35rem; }
    .breadcrumbs li + li::before { content: '\203A'; color: #475569; font-weight: 600; }
    .breadcrumbs a { color: #7dd3fc; border-bottom: 1px solid rgba(125,211,252,.4); text-decoration: none; }
    .breadcrumbs [aria-current="page"] { color: #e2e8f0; font-weight: 600; border-bottom: none; }
  </style>
</head>
<body
  data-notif-stream="/notifications/stream.php"
  data-notif-poll="/notifications/api.php?action=unread_count"
  data-auth="<?php echo $me ? '1' : '0'; ?>"
  data-user-id="<?php echo $me ? (int)$me['id'] : ''; ?>"
  data-service-worker="/service-worker.js"
  data-push-endpoint="/save_subscription.php"
  data-push-subscribe="/notifications/push_subscribe.php"
  data-push-public-key="<?php echo bc_s(defined('WEB_PUSH_VAPID_PUBLIC_KEY') ? (WEB_PUSH_VAPID_PUBLIC_KEY ?? '') : ''); ?>"
  data-vapid-key="<?php echo bc_s(defined('NOTIFICATIONS_VAPID_PUBLIC_KEY') ? (NOTIFICATIONS_VAPID_PUBLIC_KEY ?? '') : (defined('WEB_PUSH_VAPID_PUBLIC_KEY') ? (WEB_PUSH_VAPID_PUBLIC_KEY ?? '') : '')); ?>"
  data-csrf-name="<?php echo bc_s(CSRF_TOKEN_NAME); ?>">
<?php
  flash_message();
  $sessionToasts = $_SESSION['toasts'] ?? [];
  if ($sessionToasts) {
      echo '<script>window.__SESSION_TOASTS = ' . json_encode($sessionToasts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
      unset($_SESSION['toasts']);
  }
?>
<div class="app-shell">
  <div class="app-sidebar__backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
  <aside class="app-sidebar" data-sidebar>
    <div class="app-sidebar__brand">
      <a href="/index.php" class="brand" aria-label="<?php echo bc_s(APP_TITLE); ?>">
        <span class="brand__mark">
          <img src="/assets/logo.png" alt="" class="brand__logo">
        </span>
        <span class="brand__text">
          <span class="brand__title"><?php echo bc_s(APP_TITLE); ?></span>
          <span class="brand__subtitle">Operations Hub</span>
        </span>
      </a>
      <button class="sidebar-close" type="button" data-sidebar-close aria-label="Close menu">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>

    <nav class="app-sidebar__nav" aria-label="Primary">
      <p class="app-sidebar__section-label">Workspace</p>
      <ul class="nav nav--stacked">
        <li>
          <a class="nav__link<?= ($path === '/' || $path === '/index.php') ? ' is-active' : '' ?>"
             <?= ($path === '/' || $path === '/index.php') ? 'aria-current="page"' : '' ?>
             href="/index.php">
            <span class="nav__icon">🏠</span>
            <span class="nav__label">Dashboard</span>
          </a>
        </li>
        <li>
          <a class="nav__link<?= preg_match('#^/(tasks\\.php|task_)#', $path) ? ' is-active' : '' ?>"
             <?= preg_match('#^/(tasks\\.php|task_)#', $path) ? 'aria-current="page"' : '' ?>
             href="/tasks.php">
            <span class="nav__icon">📋</span>
            <span class="nav__label">Tasks</span>
          </a>
        </li>
        <li>
          <a class="nav__link<?= preg_match('#^/rooms(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
             <?= preg_match('#^/rooms(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
             href="/rooms.php">
            <span class="nav__icon">🧭</span>
            <span class="nav__label">Rooms</span>
          </a>
        </li>
        <li>
          <a class="nav__link<?= preg_match('#^/inventory(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
             <?= preg_match('#^/inventory(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
             href="/inventory.php">
            <span class="nav__icon">📦</span>
            <span class="nav__label">Inventory</span>
          </a>
        </li>
        <li>
          <a class="nav__link<?= preg_match('#^/notes(/|$)#', $path) ? ' is-active' : '' ?>"
             <?= preg_match('#^/notes(/|$)#', $path) ? 'aria-current="page"' : '' ?>
             href="/notes/index.php">
            <span class="nav__icon">🗒️</span>
            <span class="nav__label">Notes</span>
          </a>
        </li>
      </ul>

      <?php if ($roleKey === 'root'): ?>
        <p class="app-sidebar__section-label">Administration</p>
        <ul class="nav nav--stacked">
          <li>
            <a class="nav__link<?= preg_match('#^/admin/users(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/admin/users(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/admin/users.php">
              <span class="nav__icon">👥</span>
              <span class="nav__label">Users</span>
            </a>
          </li>
          <li>
            <a class="nav__link<?= preg_match('#^/admin/sectors(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/admin/sectors(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/admin/sectors.php">
              <span class="nav__icon">🗺️</span>
              <span class="nav__label">Sectors</span>
            </a>
          </li>
          <li>
            <a class="nav__link<?= preg_match('#^/admin/activity(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/admin/activity(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/admin/activity.php">
              <span class="nav__icon">📡</span>
              <span class="nav__label">Activity</span>
            </a>
          </li>
          <li>
            <a class="nav__link<?= preg_match('#^/admin/settings(\\.php|/|$)#', $path) ? ' is-active' : '' ?>"
               <?= preg_match('#^/admin/settings(\\.php|/|$)#', $path) ? 'aria-current="page"' : '' ?>
               href="/admin/settings.php">
              <span class="nav__icon">⚙️</span>
              <span class="nav__label">Settings</span>
            </a>
          </li>
        </ul>
      <?php endif; ?>
    </nav>

    <div class="app-sidebar__footer">
      <?php if ($me): ?>
        <div class="sidebar-user">
          <span class="sidebar-user__name"><?php echo bc_s($me['email'] ?? ''); ?></span>
          <small class="sidebar-user__role"><?php echo strtoupper($roleKey ?: 'member'); ?></small>
        </div>
        <a class="sidebar-user__profile" href="/account/profile.php">Profile</a>
      <?php else: ?>
        <p class="sidebar-user__guest">Sign in to personalize your workspace.</p>
        <a class="sidebar-user__profile" href="/login.php">Login</a>
      <?php endif; ?>
    </div>
  </aside>

  <div class="app-shell__content">
    <header class="app-topbar">
      <button id="navToggle" class="sidebar-toggle" aria-label="Toggle navigation" aria-expanded="false">
        <span class="sidebar-toggle__bar"></span>
        <span class="sidebar-toggle__bar"></span>
        <span class="sidebar-toggle__bar"></span>
      </button>
      <div class="app-topbar__title">
        <p>Now viewing</p>
        <strong><?php echo bc_s($title); ?></strong>
      </div>
      <div class="app-topbar__actions">
        <div class="nav__bell-wrapper" data-notif-bell>
          <button class="nav__bell" aria-label="Open notifications" data-notif-bell-trigger>
            <span class="nav__bell-icon" aria-hidden="true">🔔</span>
            <span id="notifDot" class="nav__bell-dot" aria-hidden="true"></span>
          </button>
          <div class="nav__bell-popover" data-notif-popover hidden>
            <div class="nav__bell-popover__heading">Recent activity</div>
            <div class="nav__bell-popover__body" data-notif-popover-body>
              <p class="nav__bell-empty" data-notif-popover-empty>You're all caught up.</p>
              <ul class="nav__bell-list" data-notif-popover-list hidden></ul>
            </div>
            <div class="nav__bell-popover__footer">
              <a href="/notifications/index.php">View all</a>
              <a class="nav__bell-settings" href="/account/profile.php#notification-preferences" title="Notification settings"><span aria-hidden="true">⚙️</span><span class="sr-only">Notification settings</span></a>
            </div>
          </div>
        </div>

        <button type="button" class="nav__command" data-command-open>
          <span class="nav__command-icon" aria-hidden="true">⌘</span>
          <span class="nav__command-label">Quick Find</span>
          <span class="nav__command-hint">Ctrl&nbsp;+&nbsp;K</span>
        </button>

        <?php if ($me): ?>
          <a class="nav-user__email nav-user__profile-link" href="/account/profile.php" title="Open my profile"><?php echo bc_s($me['email'] ?? ''); ?></a>
          <a class="btn small" href="/logout.php">Logout</a>
        <?php else: ?>
          <a class="btn small" href="/login.php">Login</a>
        <?php endif; ?>
      </div>
    </header>

    <div id="commandPalette" class="command-palette" hidden aria-hidden="true">
      <div class="command-palette__backdrop" data-command-close></div>
      <div class="command-palette__panel" role="dialog" aria-modal="true" aria-labelledby="commandPaletteLabel">
        <div class="command-palette__search">
          <label id="commandPaletteLabel" class="sr-only" for="commandPaletteInput">Quick find</label>
          <input id="commandPaletteInput" type="search" name="command" autocomplete="off" placeholder="Search destinations or type #ID to open a task">
          <div class="command-palette__shortcut" aria-hidden="true">
            <kbd>Ctrl</kbd>
            <span>+</span>
            <kbd>K</kbd>
          </div>
        </div>
        <ul id="commandPaletteResults" class="command-palette__results" role="listbox"></ul>
        <footer class="command-palette__hint">
          <p>Use ↑↓ to navigate, Enter to open. Try typing <strong>#42</strong> to jump to a task.</p>
        </footer>
      </div>
    </div>

    <div id="toast-container" class="toast-stack" aria-live="polite" aria-atomic="false"></div>

    <main class="container" id="app-main">
      <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): ?>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
          <ol>
            <?php
              $lastIdx = count($breadcrumbs) - 1;
              foreach ($breadcrumbs as $i => $c) {
                $label = bc_s($c['label'] ?? '');
                $href  = $c['href'] ?? null;
                if ($i === $lastIdx || !$href) {
                  echo '<li><span aria-current="page">'.$label.'</span></li>';
                } else {
                  echo '<li><a href="'.bc_s($href).'">'.$label.'</a></li>';
                }
              }
            ?>
          </ol>
        </nav>
      <?php endif; ?>
