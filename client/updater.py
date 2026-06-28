"""In-place auto-updater for the frozen Windows client.

Mirrors the ProxTop pattern:
- Version is defined in client.__init__.__version__.
- GitHub Releases publishes the installer asset named ArmaLogsClientSetup.exe.
- On check, download the new installer to temp, spawn a tiny updater .bat, and
  exit so the installer can replace the locked running EXE.
"""
import json
import logging
import os
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

import winreg  # type: ignore

logger = logging.getLogger("armalogs.updater")

GITHUB_REPO = "tobiasreichel/ArmaLogs"
INSTALLER_NAME = "ArmaLogsClientSetup.exe"
APP_GUID = "{A7B2C4D5-E6F7-8901-2345-6789ABCDEF01}_is1"


def installed_exe_path() -> Path | None:
    """Find the installed ArmaLogsClient.exe path from uninstall registry."""
    keys = [
        rf"Software\Microsoft\Windows\CurrentVersion\Uninstall\{APP_GUID}",
        rf"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\{APP_GUID}",
    ]
    for key_path in keys:
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path) as key:
                value, _ = winreg.QueryValueEx(key, "InstallLocation")
                exe = Path(value) / "ArmaLogsClient.exe"
                if exe.exists():
                    return exe
        except Exception:
            continue
    return None


def running_exe_path() -> Path:
    return Path(sys.executable).resolve()


def fetch_latest_release() -> dict | None:
    """Fetch the latest GitHub release and find the installer asset."""
    url = f"https://api.github.com/repos/{GITHUB_REPO}/releases/latest"
    try:
        req = urllib.request.Request(url, headers={"Accept": "application/vnd.github+json"})
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        assets = data.get("assets", [])
        tag = data.get("tag_name", "")
        for asset in assets:
            if asset.get("name") == INSTALLER_NAME:
                return {
                    "version": tag.lstrip("v"),
                    "url": asset.get("browser_download_url", ""),
                }
        logger.warning("No asset named %s in release %s", INSTALLER_NAME, tag)
    except Exception:
        logger.exception("Failed to fetch GitHub release info from %s", url)
    return None


def compare_versions(current: str, latest: str) -> int:
    """Return >0 if latest newer, 0 equal, <0 if older."""
    try:
        from packaging.version import Version
        return (Version(latest) > Version(current)) - (Version(latest) < Version(current))
    except Exception:
        cur = tuple(int(x) for x in current.split(".") if x.isdigit())
        lat = tuple(int(x) for x in latest.split(".") if x.isdigit())
        return (lat > cur) - (lat < cur)


def download_file(url: str, dst: Path) -> bool:
    try:
        logger.info("Downloading update from %s", url)
        req = urllib.request.Request(url, headers={"Accept": "application/octet-stream"})
        with urllib.request.urlopen(req, timeout=180) as resp:
            dst.write_bytes(resp.read())
        logger.info("Saved installer to %s (%s bytes)", dst, dst.stat().st_size)
        return True
    except Exception:
        logger.exception("Download failed")
    return False


def spawn_updater(installer: Path) -> None:
    """Write a small .bat updater, run it detached, then exit this process."""
    bat = installer.with_suffix(".bat")
    my_pid = os.getpid()
    exe = installed_exe_path()
    exe_path = str(exe) if exe else str(Path(sys.executable).resolve())
    bat.write_text(
        "@echo off\n"
        "setlocal\n"
        f'echo Waiting for parent PID {my_pid} to exit...\n'
        f':wait\n'
        f'tasklist /FI "PID eq {my_pid}" /NH | findstr /I "{my_pid}" >nul\n'
        f'if not errorlevel 1 (\n'
        f'    timeout /t 1 /nobreak >nul\n'
        f'    goto wait\n'
        f')\n'
        "echo Running installer...\n"
        f'"{installer}" /SILENT /NORESTART /SUPPRESSMSGBOXES /SP- /FORCECLOSEAPPLICATIONS /MERGETASKS="autostart"\n'
        "timeout /t 2 /nobreak >nul\n"
        f'start "" "{exe_path}"\n'
        "timeout /t 2 /nobreak >nul\n"
        f'del /f /q "{installer}"\n'
        'del /f /q "%~f0"\n',
        encoding="utf-8",
    )
    logger.info("Launching silent installer %s", installer)
    subprocess.Popen(
        ["cmd", "/c", str(bat)],
        creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
        close_fds=True,
    )


def check_update(current_version: str, force: bool = False) -> tuple[bool, str]:
    """Check GitHub Releases. If found, download installer and signal caller to restart.

    Returns (should_restart, message).
    """
    info = fetch_latest_release()
    if not info:
        if force:
            return False, "Could not reach GitHub releases."
        return False, ""
    latest = info.get("version", "")
    download_url = info.get("url", "")
    if not latest or not download_url:
        return False, "Invalid release info from GitHub."

    if compare_versions(current_version, latest) <= 0:
        return False, f"Client is up to date ({current_version})."

    tmp = Path(tempfile.gettempdir()) / INSTALLER_NAME
    if not download_file(download_url, tmp):
        return False, "Update download failed."

    spawn_updater(tmp)
    return True, f"Installing update {latest}. The app will restart in a moment."
