#!/usr/bin/env python3
"""Generate all ar root."""
import os
import sys

ROOT = r'C:/Users/toreic/dev/armalogs'

FILES = {}

FILES['README.md'] = '''# Arma Reforger Log Collectoorger issues on your server.

- **Server**: LAMP (Linux, Apache, MySQL, PHP 8.1+) on a public VPS.
- **Client**: Windows tray app that watches each friend's Arma Reforger log folder and upload start

1. Deploy the server (see `server/deploy/README.md`).
2. Run the setup wizard at `https://your-host/setup.php` to create the admin account.
3. Create a friend (and capture thei Windows client (see `client/README.md`) an their token.

## Layout

```
server/   PHP + Apache frontend, MySQL schema, install script install.bat
```
'''

print('Generator ready. FILES dict has', len(FILES), 'entries.')


def main():
    for rel, content in FILES.items():
        path = os.path.join(ROOT, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8', newline='') as f:
            f.write(content)
        print('wrote', rel)


if __name__ == '__main__':
    main()
