@echo off
:: Build a single-file Windows executable for the ArmaLogs client.
setlocal
pushd "%~dp0"

if not exist .venv (
    echo Creating virtual environment...
    python -m venv .venv
)

.venv\Scripts\python -m pip install -r requirements.txt pyinstaller -q

.venv\Scripts\python -m PyInstaller ArmaLogsClient.spec --noconfirm --clean
.venv\Scripts\python -m PyInstaller ArmaLogsClientUpdater.spec --noconfirm --clean

"C:\Program Files (x86)\Inno Setup 6\iscc.exe" installer.iss

echo.
echo Build complete: dist\ArmaLogsClient.exe, dist\ArmaLogsClientUpdater.exe and dist\ArmaLogsClientSetup.exe
popd
