# ArmaLogs client

Windows tray app that watches the Arma Reforger log folder and uploads new session folders to the server.

## Install

```powershell
cd client
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements.txt
```

## Configure

Copy `config.json.example` to `%USERPROFILE%\.armalogs\config.json` and fill in:

```json
{
  "server_url": "https://your-host/upload.php",
  "token": "paste-friend-token-here",
  "log_root": "C:/Users/yourname/Documents/My Games/ArmaReforger/logs",
  "scan_interval_seconds": 30
}
```

## Run

```powershell
python -m client.main
```

Or double-click `run.bat`.

## Build standalone executable

```powershell
pip install pyinstaller
pyinstaller --onefile --noconsole --name ArmaLogsClient main.py
```

The executable lands in `dist/ArmaLogsClient.exe`.
