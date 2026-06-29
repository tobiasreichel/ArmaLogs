"""In-place auto-updater for the frozen Windows client.

Mirrors the ProxTop pattern:
- Version is defined in client.__init__.__version__.
- GitHub Releases publishes the installer asset named ArmaLogsClientSetup.exe.
- On check, download the new installer to temp, then launch the standalone
  ArmaLogsClientUpdater.exe (or updater script) and exit so the locked EXE
  can be replaced.
"""
import json
import logging
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

import winreg  # type: ignore

logger = logging.getLogger("armalogs.updater")

GITHUB_REPO = "tobiasreichel/ArmaLogs"
INSTALLER_NAME = "ArmaLogsClientSetup.exe"
UPDATER_NAME = "ArmaLogsClientUpdater.exe"
APP_GUID = "{A7B2C4D5-E6F7-8901-2345-6789ABCDEF01}_is1"


def installed_dir() -> Path | None:
    """Find the install directory from the uninstall registry key."""
    keys = [
        rf"Software\Microsoft\Windows\CurrentVersion\Uninstall\{APP_GUID}",
        rf"Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\{APP_GUID}",
    ]
    for key_path in keys:
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path) as key:
                value, _ = winreg.QueryValueEx(key, "InstallLocation")
                path = Path(value)
                if path.is_dir():
                    return path
        except Exception:
            continue
    return None


def installed_exe_path() -> Path | None:
    d = installed_dir()
    if not d:
        return None
    exe = d / "ArmaLogsClient.exe"
    return exe if exe.exists() else None


def running_exe_path() -> Path:
    return Path(sys.executable).resolve()


def updater_exe_path() -> Path | None:
    """Locate the standalone updater binary next to the running EXE."""
    candidates = [
        running_exe_path().parent / UPDATER_NAME,
        Path(sys.executable).resolve().parent / UPDATER_NAME,
    ]
    d = installed_dir()
    if d:
        candidates.append(d / UPDATER_NAME)
    for c in candidates:
        if c.exists():
            return c
    return None


def fetch_latest_release() -> dict | None:
    """Fetch the latest GitHub release and find the installer asset."""
    url = f"https://api.github.com/repos/{GITHUB_REPO}/releases/latest"
    try:
        req = urllib.request.Request(
            url,
            headers={
                "Accept": "application/vnd.github+json",
                "User-Agent": "ArmaLogsClient/" + sys.version.split()[0],
            },
        )
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
    for attempt in range(3):
        try:
            logger.info("Downloading update from %s (attempt %d/3)", url, attempt + 1)
            req = urllib.request.Request(
                url,
                headers={
                    "Accept": "application/octet-stream",
                    "User-Agent": "ArmaLogsClient/1.0",
                },
            )
            with urllib.request.urlopen(req, timeout=240) as resp:
                dst.write_bytes(resp.read())
            size = dst.stat().st_size
            logger.info("Saved installer to %s (%s bytes)", dst, size)
            if size < 1_000_000:
                logger.error("Installer suspiciously small (%d bytes)", size)
                return False
            return True
        except Exception:
            logger.exception("Download attempt %d failed", attempt + 1)
            if attempt < 2:
                time.sleep(2 ** attempt)
    return False


def fetch_latest_release_url() -> str:
    """Return the installer browser_download_url of the latest release."""
    info = fetch_latest_release()
    return info.get("url", "") if info else ""


def spawn_updater(installer: Path, installer_url: str = "") -> bool:
    """Launch the standalone updater EXE and return immediately.

    The caller should exit the main app after this returns True.
    """
    my_pid = os.getpid()
    updater = updater_exe_path()
    target = installed_exe_path() or running_exe_path()

    if updater and updater.exists():
        args = [
            str(updater),
            f"--parent-pid={my_pid}",
            f"--installer-url={installer_url}",
            f"--target-exe={target}",
            f"--installer-path={installer}",
        ]
        logger.info("Launching standalone updater: %s", " ".join(args))
        subprocess.Popen(
            args,
            creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
            close_fds=True,
        )
        return True

    logger.warning("Standalone updater not found at %s; falling back to batch updater", updater)
    bat = installer.with_suffix(".bat")
    exe_path = str(target)
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
    subprocess.Popen(
        ["cmd", "/c", str(bat)],
        creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
        close_fds=True,
    )
    return True


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

    if not spawn_updater(tmp, installer_url=download_url):
        return False, "Failed to start updater."
    return True, f"Installing update {latest}. The app will restart in a moment."


if __name__ == "__main__":
    print("Use check_update() from the main app.")
