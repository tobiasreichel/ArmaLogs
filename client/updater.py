"""In-place auto-updater for the frozen Windows client.

- Version is defined in client.__init__.__version__.
- Server publishes /client/version.json:
  {"version":"1.1.0","url":"https://armalogs.reichel.network/client/ArmaLogsClientSetup.exe"}
- On check, download new installer to temp, spawn a tiny updater .bat, and exit
  so the installer can replace the locked running EXE.
"""
import json
import logging
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

import winreg  # type: ignore

logger = logging.getLogger("armalogs.updater")


def installed_exe_path() -> Path | None:
    """Find the installed ArmaLogsClient.exe path from uninstall registry."""
    keys = [
        r"Software\Microsoft\Windows\CurrentVersion\Uninstall\{A7B2C4D5-E6F7-8901-2345-6789ABCDEF01}_is1",
        r"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\{A7B2C4D5-E6F7-8901-2345-6789ABCDEF01}_is1",
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


def fetch_version(server_url: str) -> dict | None:
    url = server_url.rstrip("/") + "/client/version.json.php"
    try:
        with urllib.request.urlopen(url, timeout=15) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            if isinstance(data, dict) and "version" in data and "url" in data:
                return data
    except Exception:
        logger.exception("Failed to fetch version.json from %s", url)
    return None


def compare_versions(current: str, latest: str) -> int:
    """Return >0 if latest newer, 0 equal, <0 if older."""
    try:
        from packaging.version import Version
        return (Version(latest) > Version(current)) - (Version(latest) < Version(current))
    except Exception:
        # Fallback to tuple compare
        cur = tuple(int(x) for x in current.split(".") if x.isdigit())
        lat = tuple(int(x) for x in latest.split(".") if x.isdigit())
        return (lat > cur) - (lat < cur)


def download_file(url: str, dst: Path) -> bool:
    try:
        logger.info("Downloading update from %s", url)
        with urllib.request.urlopen(url, timeout=120) as resp:
            dst.write_bytes(resp.read())
        logger.info("Saved installer to %s (%s bytes)", dst, dst.stat().st_size)
        return True
    except Exception:
        logger.exception("Download failed")
    return False


def spawn_updater(installer: Path) -> None:
    """Write a small .bat updater, run it detached, then exit this process."""
    bat = installer.with_suffix(".bat")
    bat.write_text(
        "@echo off\n"
        "timeout /t 2 /nobreak > nul\n"
        f'"{installer}" /SILENT /NORESTART /SUPPRESSMSGBOXES /SP- /CLOSEAPPLICATIONS\n'
        "timeout /t 5 /nobreak > nul\n"
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


def check_update(server_url: str, current_version: str, force: bool = False) -> tuple[bool, str]:
    """Check for update. If found, download installer and signal caller to restart.

    Returns (should_restart, message).
    """
    info = fetch_version(server_url)
    if not info:
        if force:
            return False, "Could not reach update server."
        return False, ""
    latest = info.get("version", "")
    download_url = info.get("url", "")
    if not latest or not download_url:
        return False, "Invalid version info from server."

    if compare_versions(current_version, latest) <= 0:
        return False, f"Client is up to date ({current_version})."

    tmp = Path(tempfile.gettempdir()) / "ArmaLogsClientSetup.exe"
    if not download_file(download_url, tmp):
        return False, "Update download failed."

    spawn_updater(tmp)
    return True, f"Installing update {latest}. The app will restart in a moment."
