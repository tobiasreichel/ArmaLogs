# ArmaLogs Windows Client

Small system-tray app that watches your Arma Reforger log folder and uploads new sessions to the server.

## Download (no Python needed)

If someone already built the release for you:

1. Download `ArmaLogsClient.exe`
2. Double-click it
3. Fill in:
   - **Server URL**: your buddy's server, e.g. `https://armalogs.reichel.network`
   - **Friend Token**: the token created in the admin dashboard
   - **Logs Folder**: usually `C:\Users\YOURNAME\Documents\My Games\ArmaReforger\logs`
4. Click **Save & Start**

The app sits in your system tray and uploads logs automatically. Right-click the tray icon to force an upload or exit.

## Build from source

```powershell
cd client
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
python -m client.main
```

## Build the .exe

```powershell
cd client
.\build-exe.ps1
```

Or double-click `build-exe.bat`. The executable appears in `client/dist/ArmaLogsClient.exe`.

## Where is my config stored?

`%USERPROFILE%\.armalogs\config.json`

You can delete that file to make the setup dialog appear again.

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Server URL, token, and log folder are required" | Re-run and fill all fields |
| "Folder not found" | Browse to `Documents\My Games\ArmaReforger\logs` manually |
| Nothing uploads | Right-click tray icon → **Upload now** |
| Antivirus blocks it | False positive from PyInstaller; allow the app |
