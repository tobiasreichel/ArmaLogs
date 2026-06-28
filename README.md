# ArmaLogs

Remote log collection for Arma Reforger.

- **Server**: PHP 8.1 + MySQL 8 / MariaDB 10.5 backend, single Apache/Nginx virtual host.
- **Client**: Windows tray app that watches each friend’s Arma Reforger log folder and uploads new logs.

## Quick start

1. Deploy the server:
   ```bash
   cp server/includes/config.example.php server/includes/config.php
   # edit server/includes/config.php
   mysql < server/sql/schema.sql
   # browse /setup.php to create the admin account
   ```
2. Give each Windows client:
   - server URL
   - friend token (created in the admin web UI)
   - path to their Arma Reforger log folder
3. The client runs in the system tray and uploads new log folders as they appear.

## Layout

```
server/
  public/            # web root
    index.php
    setup.php        # one-time admin creation
    login.php
    logout.php
    upload.php       # client POST endpoint
    api/             # admin JSON API
      friends.php
      logs.php
      stats.php
  includes/          # not in web root
    config.php
    db.php
    auth.php
    helpers.php
  sql/schema.sql
  deploy/
    apache.conf
    nginx.conf
    install.sh
client/              # Windows tray app
  main.py
  config.py
  uploader.py
  watcher.py
  requirements.txt
```

## License

MIT
