@echo off
:: Build a single-file Windows executable for the ArmaLogs client.
setlocal
pushd "%~dp0"

if not exist .venv (
    echo Creating virtual environment...
    python -m venv .venv
)

.venv\Scripts\python -m pip install -r requirements.txt pyinstaller -q

.venv\Scripts\python -m PyInstaller ^
    --onefile ^
    --noconsole ^
    --name ArmaLogsClient ^
    --icon NONE ^
    --add-data "config.json.example;." ^
    main.py

echo.
echo Build complete: dist\ArmaLogsClient.exe
popd
pause
