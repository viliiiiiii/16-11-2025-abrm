<?php
// Copy this file to config.php and fill in production credentials.
define('APP_TIMEZONE', 'Europe/Podgorica');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'abrm');
define('DB_USER', 'abrm');
define('DB_PASS', 'secret');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', 'http://148.230.105.29:8081'); // no trailing slash

// S3-compatible storage credentials
// Example for MinIO running locally: http://127.0.0.1:9000

define('S3_ENDPOINT', 'http://148.230.105.29:9000');
define('S3_KEY', 'abrm');
define('S3_SECRET', 'StrongPassword123!');
define('S3_BUCKET', 'abrm');
define('S3_REGION', 'us-east-1');
define('S3_USE_PATH_STYLE', true); // set false if using virtual-hosted-style endpoints

// Optional: override the URL base used to serve files.
define('S3_URL_BASE', ''); // leave blank to derive from endpoint + bucket.

// Security

define('SESSION_NAME', 'abrm_session');
define('CSRF_TOKEN_NAME', 'csrf_token');


define('APP_TITLE', 'ABRM Management');

// Dual database support (core governance + application data)
define('APPS_DSN', getenv('APPS_DSN') ?: sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET));
define('APPS_DB_USER', getenv('APPS_DB_USER') ?: DB_USER);
define('APPS_DB_PASS', getenv('APPS_DB_PASS') ?: DB_PASS);

define('CORE_DSN', 'mysql:host=127.0.0.1;dbname=core_db;charset=utf8mb4');
define('CORE_DB_USER', 'core_user');
define('CORE_DB_PASS', 'core123');

// Web Push / VAPID configuration
define('WEB_PUSH_VAPID_PUBLIC_KEY', getenv('WEB_PUSH_VAPID_PUBLIC_KEY') ?: 'BFVtM-ytGJ4vahPj7suGZLSrPcmvke6XKfLNdgJa56dYJzGMIEMhF3Qklm9C8pE8CWascoY5tbxFPAMvkXwy5ds');
define('WEB_PUSH_VAPID_PRIVATE_KEY', getenv('WEB_PUSH_VAPID_PRIVATE_KEY') ?: 'yKS9mCDDnnAjnyFv02dbDioGn2-TadEgslt79G9kkZg');
define('WEB_PUSH_VAPID_SUBJECT', getenv('WEB_PUSH_VAPID_SUBJECT') ?: 'mailto:elvirisljamovic@gmail.com');

define('NOTIFICATIONS_SERVICE_URL', getenv('NOTIFICATIONS_SERVICE_URL') ?: 'http://127.0.0.1:8001');
define('NOTIFICATIONS_VAPID_PUBLIC_KEY', 'BLvqTrosQ1dKSxoS_4QPSQJMhhZTH6k0rrOBHh6gCm59VQJYcJyeeQpUIoJEM8EKp--c2ncaGhYoPWCm42y1uIs');
define('NOTIFICATIONS_VAPID_PRIVATE_KEY', 'vq-VFxjwJbMmq58QpKTeabv91ATEg8-wEHXLKKHAlD0');
define('NOTIFICATIONS_VAPID_SUBJECT',  'mailto:elvirisljamovic@gmail.com');
