# Deploying ArmaLogs to Cloudron LAMP

These steps assume you have a Cloudron LAMP app installed at `armalogs.reichel.network`.

## 1. Cloudron LAMP layout

In Cloudron LAMP:

- **Web root** (DocumentRoot): `/app/data/public`
- **Writable persistent storage**: `/app/data/`
- **PHP version**: check the Cloudron app info (usually 8.1–8.3)
- **MySQL**: Cloudron provisions a database automatically. Credentials are exposed via the Cloudron CLI or you can create a database user manually.

## 2. Upload the server code

### Option A: Cloudron File Manager
1. Open your Cloudron admin → Apps → LAMP → File Manager.
2. Delete the contents of `/app/data/public/`.
3. Upload the contents of the local `server/public/` folder into `/app/data/public/`.
4. Upload the contents of the local `server/includes/` folder into `/app/data/includes/` (sibling to `public/`).

### Option B: SSH / SFTP
Use the Cloudron-provided SFTP/SSH credentials (usually `app@<server-ip>` or via the File Manager → Terminal).

```bash
# From your local project root
scp -r server/public/*    root@your.cloudron.box:/app/data/public/
scp -r server/includes/*  root@your.cloudron.box:/app/data/includes/
```

Or open the Cloudron Terminal and run:

```bash
cd /app/data
rm -rf public/*
cp -r /tmp/upload/public/* public/
cp -r /tmp/upload/includes includes/
```

## 3. Configure the app

Create `/app/data/includes/config.php` from the example:

```bash
cp /app/data/includes/config.example.php /app/data/includes/config.php
```

Edit it with the Cloudron Terminal or File Manager editor.

### MySQL credentials

Cloudron LAMP exposes a MySQL database. To get credentials:

```bash
# Inside the Cloudron Terminal for the LAMP app
cat /app/data/.env 2>/dev/null || true
cloudron env 2>/dev/null || true
# or look at Cloudron app settings → Services → MySQL
```

Typical Cloudron LAMP credentials look like:

```php
'db' => [
    'host' => 'mysql',
    'port' => 3306,
    'name' => 'armalogs',
    'user' => 'armalogs',
    'pass' => '...',
    'charset' => 'utf8mb4',
],
```

If Cloudron did **not** auto-create a database/user, create one in the MySQL shell:

```bash
mysql -u root -p
CREATE DATABASE IF NOT EXISTS armalogs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'armalogs'@'%' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON armalogs.* TO 'armalogs'@'%';
FLUSH PRIVILEGES;
EXIT;
```

Then fill those credentials into `/app/data/includes/config.php`.

### Storage path

In Cloudron, change the storage path to a writable persistent location:

```php
'paths' => [
    'storage'  => '/app/data/storage/logs',
    'includes' => '/app/data/includes',
],
```

Create it:

```bash
mkdir -p /app/data/storage/logs
```

### Session cookie `secure`

Because `armalogs.reichel.network` will be served over HTTPS, keep:

```php
'session' => [
    'cookie_secure' => true,
    ...
]
```

If you are testing over plain HTTP temporarily, set it to `false`.

### Final Cloudron config example

```php
<?php
return [
    'db' => [
        'host'    => 'mysql',
        'port'    => 3306,
        'name'    => 'armalogs',
        'user'    => 'armalogs',
        'pass'    => 'YOUR_STRONG_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'storage'  => '/app/data/storage/logs',
        'includes' => '/app/data/includes',
    ],
    'limits' => [
        'uploads_per_hour' => 50,
        'bytes_per_day'    => 500 * 1024 * 1024,
        'max_file_bytes'   => 100 * 1024 * 1024,
    ],
    'security' => [
        'argon2id_options' => [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ],
    ],
    'session' => [
        'name'            => 'armalogs_admin',
        'lifetime_s'      => 60 * 60 * 8,
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'samesite'        => 'Lax',
    ],
    'setup' => [
        'enabled' => true,
    ],
];
```

## 4. Import the database schema

Upload `server/sql/schema.sql` to `/tmp/` via the File Manager, then run:

```bash
mysql -u armalogs -p armalogs < /tmp/schema.sql
```

Or paste the SQL directly into the Cloudron Terminal using a MySQL client.

## 5. Protect the includes folder

Create `/app/data/public/.htaccess` with at least:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Deny access to includes if it is ever accidentally placed under public
RewriteRule ^includes/ - [F,L]
```

If your `includes/` folder is already outside `/app/data/public/` (recommended), this is just defense-in-depth.

Also block direct access to `.git`, `.env`, and `config.php`:

```apache
<FilesMatch "^\.(env|git)|config\.php$">
    Require all denied
</FilesMatch>
```

## 6. Set permissions

In the Cloudron Terminal:

```bash
chown -R www-data:www-data /app/data/public /app/data/includes /app/data/storage
chmod -R 755 /app/data/public /app/data/includes
chmod -R 775 /app/data/storage
```

## 7. First run

Visit:

```
https://armalogs.reichel.network/setup.php
```

Create the first admin account. After that, `setup.php` disables itself.

Then log in at:

```
https://armalogs.reichel.network/login.php
```

Create a friend in the dashboard to get a token.

## 8. Configure the Windows client

On each Windows machine, copy `client/config.json.example` to `%USERPROFILE%\.armalogs\config.json`:

```json
{
  "server_url": "https://armalogs.reichel.network/upload.php",
  "token": "PASTE_FRIEND_TOKEN_HERE",
  "log_root": "C:/Users/USERNAME/Documents/My Games/ArmaReforger/logs",
  "scan_interval_seconds": 30
}
```

Then run `client/run.bat` or build a standalone executable.

## 9. Upload size limits

Cloudron LAMP may limit PHP upload/post sizes. If you get `413 Request Entity Too Large` or upload failures, raise limits in a custom `php.ini` or `.user.ini` inside `/app/data/public/`:

```ini
upload_max_filesize = 100M
post_max_size = 110M
max_file_uploads = 50
max_execution_time = 300
memory_limit = 256M
```

Restart the LAMP app from Cloudron if the change is not picked up automatically.

## 10. HTTPS / reverse proxy notes

Cloudron terminates TLS at its reverse proxy and forwards to the LAMP app over HTTP. The app sees `$_SERVER['HTTPS']` as off even though the client uses HTTPS. The upload endpoint and auth code do not rely on `HTTPS`, so this is fine. If you force `cookie_secure` while testing over plain HTTP, login will fail; keep `cookie_secure => true` only for production HTTPS.

## 11. Backup

Cloudron already backs up `/app/data/` on schedule. The uploaded logs and MySQL database are included. Verify your backup retention in Cloudron app settings.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `500 Internal Server Error` | Check `/app/data/logs/apache/` or Cloudron app logs; look for missing PHP extensions (`pdo_mysql`, `mbstring`). |
| `Access denied` to includes | Confirm `includes/` is outside `public/` or add `.htaccess` deny rule. |
| Uploads fail with `UPLOAD_ERR_INI_SIZE` | Raise `upload_max_filesize` / `post_max_size` in `.user.ini`. |
| Login cookie not sticking | Set `cookie_secure` to `false` if testing over HTTP; keep `true` for HTTPS. |
| Database connection error | Verify host (`mysql` or `127.0.0.1`), user, password, and that the schema was imported. |
