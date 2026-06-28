# Build a single-file Windows executable for the ArmaLogs client.
#
# Run on a Windows machine with Python installed:
#   .\build-exe.ps1
#
# Output: client/dist/ArmaLogsClient.exe

param(
    [string]$Spec = "ArmaLogsClient.spec"
)

$ErrorActionPreference = "Stop"
Push-Location $PSScriptRoot

try {
    if (-not (Test-Path .venv)) {
        Write-Host "Creating virtual environment..." -ForegroundColor Cyan
        python -m venv .venv
    }

    .venv\Scripts\python -m pip install -r requirements.txt pyinstaller

    .venv\Scripts\python -m PyInstaller ArmaLogsClient.spec --noconfirm --clean

    Write-Host "Build complete: dist\ArmaLogsClient.exe" -ForegroundColor Green
} finally {
    Pop-Location
}
