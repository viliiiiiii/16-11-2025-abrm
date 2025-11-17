# Punch List Manager

A lightweight PHP 8.2+ punch list manager designed for LAMP stacks with S3-compatible storage.

## Setup

1. Run `composer install` to download dependencies (AWS SDK for PHP, Dompdf, PhpSpreadsheet, Minishlink Web Push).
2. Create a MySQL database and run the schema in `schema.sql` (or from README instructions).
3. Copy `config.php.example` to `config.php` and fill in database and S3-compatible storage credentials.
4. Run `php seed.php` once to seed the admin user (`admin@example.com` / `admin123`) and sample data.
5. Point your web server's document root to this project directory.
6. Ensure the `/vendor` directory is web-accessible (if your deployment keeps it outside the web root, update autoload include paths accordingly).

## Self-hosted Object Storage

The application assumes you run your own S3-compatible object storage (no third-party cloud required). The example configuration in `config.php.example` targets a MinIO instance, but any software that speaks the S3 API will work.

### Example: MinIO on the same VPS

1. **Install MinIO**
   ```bash
   wget https://dl.min.io/server/minio/release/linux-amd64/minio
   chmod +x minio
   sudo mv minio /usr/local/bin/
   ```

2. **Create directories** for data and configuration:
   ```bash
   sudo mkdir -p /srv/minio/data
   sudo chown -R $USER:$USER /srv/minio
   ```

3. **Start MinIO** (replace credentials with strong values):
   ```bash
   MINIO_ROOT_USER=punchlist MINIO_ROOT_PASSWORD='change-me-strong' \
     minio server /srv/minio/data --console-address ":9090" --address ":9000"
   ```
   Run it as a systemd service for production. A minimal unit file:
   ```ini
   [Unit]
   Description=MinIO Storage Server
   After=network.target

   [Service]
   User=minio
   Group=minio
   Environment="MINIO_ROOT_USER=punchlist"
   Environment="MINIO_ROOT_PASSWORD=change-me-strong"
   ExecStart=/usr/local/bin/minio server /srv/minio/data --console-address :9090 --address :9000
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. **Create a bucket** named `punchlist` via the MinIO console (`http://your-vps:9090`) or the `mc` CLI:
   ```bash
   mc alias set local http://127.0.0.1:9000 punchlist change-me-strong
   mc mb local/punchlist
   ```

5. **Generate access keys** dedicated to the web app. Inside the MinIO console, add a new user with `consoleAdmin` access or a custom policy that grants read/write to the `punchlist` bucket. Record the Access Key and Secret Key.

6. **Expose HTTPS**: put MinIO behind a reverse proxy such as Nginx or Caddy that terminates TLS. The app can connect to either HTTP or HTTPS endpoints, but HTTPS is strongly recommended.

7. **Update `config.php`** with your MinIO endpoint and credentials:
   ```php
   define('S3_ENDPOINT', 'https://minio.example.com');
   define('S3_KEY', 'APP_ACCESS_KEY');
   define('S3_SECRET', 'APP_SECRET_KEY');
   define('S3_BUCKET', 'punchlist');
   define('S3_REGION', 'us-east-1'); // MinIO accepts any region string
   define('S3_USE_PATH_STYLE', true); // required for MinIO unless using subdomain routing
   ```
   Set `S3_URL_BASE` if you serve public files through a CDN or a different host than the API endpoint.

8. **Verify connectivity** by running `php seed.php` (which uploads sample photos if available) or uploading a photo through the UI.

For alternative storage backends (Ceph, OpenIO, etc.), adjust the endpoint and path-style option as required by your platform.

## Development Notes

- No PHP framework is used; the app relies on simple includes and helper functions.
- CSRF tokens protect all forms and upload/delete actions.
- File uploads go directly to the configured S3-compatible endpoint.
- Exports make use of Dompdf and standard CSV output.

## Web push & notification settings

- Generate VAPID keys with `php scripts/generate_vapid.php` and copy the values into `config.php` (`WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY`, `WEB_PUSH_VAPID_SUBJECT`).
- Users manage per-channel and per-type preferences from `/account/profile.php#notification-preferences`, and the navigation bell dropdown links there.
- Browsers register a push subscription via the service worker (`/service-worker.js`); the manifest (`/manifest.webmanifest`) enables installable PWA behaviour.

## Push notification flow

The browser talks directly to PHP, so you no longer need to run a separate Python process.

### Step-by-step checklist (matches the YouTube tutorial)

1. **Generate VAPID keys once**
   - `php scripts/generate_vapid.php`
   - Copy the public & private key plus subject into `config.php` (`WEB_PUSH_VAPID_*`). Do **not** delete your existing keys if they already work; the new code keeps them intact.
2. **Serve the app over HTTPS** so service workers and Push API registration succeed.
3. **Register a browser**
   - Sign in and open any page so `/assets/js/notifications.js` registers `/service-worker.js`.
   - Accept the permission prompt; the resulting subscription JSON is sent to `/save_subscription.php` where it is stored in `notification_devices` (MySQL) together with your user id.
   - The profile page (`/account/profile.php`) uses `/notifications/push_subscribe.php` to show device status, revoke browsers, or disable push entirely.
4. **Verify storage**
   - Run `SELECT * FROM notification_devices WHERE user_id = ?` to confirm the endpoint, `p256dh`, and `auth` keys were persisted.
5. **Queue notifications**
   - Use `notify_users([...])` anywhere in PHP to emit a notification. It lands in the `notifications` table and automatically enqueues a push delivery job when the recipient allows push for that type.
6. **Send pushes like the video demo**
   - Execute `php scripts/notifications_worker.php` (or run it via cron/systemd) to flush the push queue with the bundled `Minishlink\WebPush` library.
   - Successful deliveries appear instantly via the service worker, matching the workflow shown in the linked tutorial.

The entire pipeline now runs inside PHP/MySQL, so deployment is as simple as uploading the code, keeping your VAPID keys, and running the worker periodically.
