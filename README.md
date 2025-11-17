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

## Python notification service

- A FastAPI microservice lives under `notifications_service/`. Install its dependencies (`pip install -r notifications_service/requirements.txt`) and run it with `uvicorn notifications_service.main:app --reload --port 8001`.
- Configure `NOTIFICATIONS_SERVICE_URL`, `NOTIFICATIONS_VAPID_PUBLIC_KEY`, `NOTIFICATIONS_VAPID_PRIVATE_KEY`, and `NOTIFICATIONS_VAPID_EMAIL` via environment variables or `config.php`.
- PHP helpers in `includes/notification_service.php` forward toast and push events to the microservice, while the new `/service-worker.js` displays received pushes.

### End-to-end setup checklist

1. **Prepare Python environment**
   - `cd notifications_service`
   - `python3 -m venv .venv && source .venv/bin/activate`
   - `pip install -r requirements.txt`
2. **Start the FastAPI worker**
   - `uvicorn notifications_service.main:app --host 0.0.0.0 --port 8001`
   - The bundled SQLite database (`notifications_service/notifications.db`) and tables are created automatically.
3. **Expose the service to PHP**
   - Ensure `NOTIFICATIONS_SERVICE_URL` in `config.php` points to the running FastAPI instance (default `http://127.0.0.1:8001`).
   - Keep the existing VAPID keys in `config.php`; the service reads them via environment variables or `config.php` constants when proxied through PHP.
4. **Serve the PHP app over HTTPS** so the browser can register service workers and push subscriptions.
5. **Verify browser registration**
   - Load any authenticated page so `/assets/js/notifications.js` can register the service worker (`/service-worker.js`).
   - Approve the notification permission prompt; the script sends the resulting Push API subscription JSON to `/save_subscription.php`.
6. **Confirm persistence**
   - `save_subscription.php` stores the authenticated `user_id` in the PHP session (matching `SESSION_NAME`) and forwards the subscription to the Python API (`/api/notifications/register-subscription`).
   - Check `notifications_service/notifications.db` with `sqlite3` to view rows in `push_subscriptions` or tail `logs/notifications.log` if the PHP proxy reports transport errors.
7. **Send a test push**
   - Call `POST /api/notifications/push` with `{ "user_id": <id>, "title": "Hello", "body": "Test" }` via curl or the provided helper in `includes/notification_service.php` (`notify_push`).
   - The service worker displays the payload to the browser that registered the subscription.

These steps keep your existing VAPID keys intact while ensuring every saved subscription is tied to the correct authenticated user.
