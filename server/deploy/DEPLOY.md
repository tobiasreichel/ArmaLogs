# SFTP Deploy

Deploy `server/public/` and `server/includes/` to Cloudron via SFTP.

## Requirements

- Python 3
- `paramiko`

Install:

```bash
cd server/deploy
python -m venv .venv
.venv/Scripts/pip install -r requirements.txt
```

## Usage

Set environment variables:

```powershell
$env:ARMALOGS_SFTP_HOST = "my.reichel.network"
$env:ARMALOGS_SFTP_PORT = "222"
$env:ARMALOGS_SFTP_USER = "toreic@armalogs.reichel.network"
$env:ARMALOGS_SFTP_PASS = "YOUR_PASSWORD"
```

Run:

```bash
.venv/Scripts/python deploy.py
```

The script uploads:
- `server/public/` → `/app/data/public/`
- `server/includes/` → `/app/data/includes/`

It only overwrites files that exist locally; it does not touch `includes/config.php` because that file is not tracked locally.
