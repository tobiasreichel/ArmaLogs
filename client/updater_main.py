"""Standalone updater entry point for ArmaLogs Windows client.

This script is frozen as ArmaLogsClientUpdater.exe and lives next to
ArmaLogsClient.exe in the install directory. The main client launches it
with CLI arguments and exits, so the updater can replace the locked EXE.

Usage (from main client):
    ArmaLogsClientUpdater.exe
        --parent-pid 1234
        --installer-url https://github.com/.../ArmaLogsClientSetup.exe
        --target-exe "C:\Program Files\ArmaLogs\ArmaLogsClient.exe"
        [--installer-path "C:\Temp\ArmaLogsClientSetup.exe"]
        [--work-dir "C:\Temp\armalogs_update_1234"]
        [--exit-event "Global\ArmaLogsUpdateExit_1234"]

Fallback if installer-url/download fails:
    The updater checks the install dir for an existing ArmaLogsClientSetup.exe
    placed there by the main client and runs that instead.
"""
import argparse
import ctypes
import json
import logging
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.request
from pathlib import Path

# Constants for Windows event creation
EVENT_ALL_ACCESS = 0x1F0003

logger = logging.getLogger("armalogs.updater_exe")

INSTALLER_NAME = "ArmaLogsClientSetup.exe"
LOG_DIR = Path.home() / ".armalogs"


def setup_logging(work_dir: Path) -> Path:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    log_file = work_dir / "updater.log"
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[
            logging.FileHandler(str(log_file), mode="w", encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )
    return log_file


def wait_for_parent(pid: int, timeout: int = 10) -> bool:
    if pid <= 0:
        logger.info("No parent PID to wait for")
        return True

    kernel32 = ctypes.windll.kernel32
    SYNCHRONIZE = 0x00100000
    INFINITE = 0xFFFFFFFF
    WAIT_OBJECT_0 = 0x00000000
    WAIT_TIMEOUT = 0x00000102
    WAIT_FAILED = 0xFFFFFFFF

    h = kernel32.OpenProcess(SYNCHRONIZE, False, pid)
    if not h:
        logger.info("Parent PID %d already gone (OpenProcess failed), proceeding", pid)
        return True

    logger.info("Opened parent PID %d process handle; waiting up to %ds for it to exit", pid, timeout)
    ms = int(timeout * 1000)
    rc = kernel32.WaitForSingleObject(h, ms if ms > 0 else INFINITE)
    kernel32.CloseHandle(h)

    if rc == WAIT_OBJECT_0:
        logger.info("Parent PID %d has exited", pid)
        return True
    elif rc == WAIT_TIMEOUT:
        logger.warning("Timed out waiting for parent PID %d; proceeding anyway (PyInstaller zombie likely)", pid)
    elif rc == WAIT_FAILED:
        logger.warning("WaitForSingleObject failed for parent PID %d; proceeding anyway", pid)
    else:
        logger.warning("WaitForSingleObject returned 0x%X for parent PID %d; proceeding anyway", rc, pid)
    return True


def parent_is_alive(pid: int) -> bool:
    """Best-effort check; used only as a fallback."""
    if pid <= 0:
        return False
    kernel32 = ctypes.windll.kernel32
    SYNCHRONIZE = 0x00100000
    h = kernel32.OpenProcess(SYNCHRONIZE, False, pid)
    if not h:
        return False
    rc = kernel32.WaitForSingleObject(h, 0)
    kernel32.CloseHandle(h)
    return rc != 0


def download_with_retry(url: str, dst: Path, retries: int = 3) -> bool:
    for attempt in range(1, retries + 1):
        try:
            logger.info("Downloading installer from %s (attempt %d/%d)", url, attempt, retries)
            req = urllib.request.Request(
                url,
                headers={
                    "Accept": "application/octet-stream",
                    "User-Agent": "ArmaLogs-Updater",
                },
            )
            with urllib.request.urlopen(req, timeout=120) as resp:
                dst.write_bytes(resp.read())
            logger.info("Saved installer to %s (%d bytes)", dst, dst.stat().st_size)
            return True
        except Exception:
            logger.exception("Download attempt %d failed", attempt)
            time.sleep(2 ** attempt)
    return False


def run_installer(installer: Path) -> bool:
    args = [
        str(installer),
        "/SILENT",
        "/NORESTART",
        "/SUPPRESSMSGBOXES",
        "/SP-",
        "/FORCECLOSEAPPLICATIONS",
        '/MERGETASKS="autostart"',
    ]
    logger.info("Running installer: %s", " ".join(args))
    try:
        proc = subprocess.run(args, check=False, timeout=300)
        logger.info("Installer exit code: %d", proc.returncode)
        return proc.returncode == 0
    except subprocess.TimeoutExpired:
        logger.error("Installer timed out after 300s")
        return False
    except Exception:
        logger.exception("Failed to run installer")
        return False


def start_client(target_exe: Path) -> bool:
    logger.info("Starting client: %s", target_exe)
    try:
        subprocess.Popen(
            [str(target_exe)],
            creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
            close_fds=True,
        )
        return True
    except Exception:
        logger.exception("Failed to start client")
        return False


def main() -> int:
    parser = argparse.ArgumentParser(description="ArmaLogs client updater")
    parser.add_argument("--parent-pid", type=int, required=True)
    parser.add_argument("--installer-url", type=str, required=True)
    parser.add_argument("--target-exe", type=str, required=True)
    parser.add_argument("--installer-path", type=str, default="")
    parser.add_argument("--work-dir", type=str, default="")
    args = parser.parse_args()

    target_exe = Path(args.target_exe)
    install_dir = target_exe.parent
    work_dir = Path(args.work_dir) if args.work_dir else Path(sys.executable).resolve().parent
    setup_logging(work_dir)
    logger.info("Starting updater with args: %s", sys.argv)

    try:
        # 1. Wait for parent to release the locked EXE.
        #    We intentionally do NOT treat a timeout as fatal: PyInstaller one-file
        #    executables can leave a bootloader zombie whose PID is still visible,
        #    so if the process handle never signals, we proceed after the timeout.
        wait_for_parent(args.parent_pid, timeout=10)

        # 2. Determine installer path
        installer: Path | None = None
        if args.installer_path:
            candidate = Path(args.installer_path)
            if candidate.exists():
                installer = candidate
                logger.info("Using pre-downloaded installer: %s", installer)

        # 3. Download if not pre-provided
        if installer is None:
            tmp = Path(tempfile.gettempdir()) / INSTALLER_NAME
            if download_with_retry(args.installer_url, tmp):
                installer = tmp

        # 4. Also look next to updater exe (main client may have dropped it there)
        if installer is None:
            fallback = Path(sys.executable).resolve().parent / INSTALLER_NAME
            if fallback.exists():
                logger.info("Using fallback installer from install dir: %s", fallback)
                installer = fallback

        # 5. Backup old client exe
        backup = install_dir / (target_exe.stem + "_old" + target_exe.suffix)
        if target_exe.exists():
            try:
                shutil.copy2(str(target_exe), str(backup))
                logger.info("Backed up old client to %s", backup)
            except Exception:
                logger.exception("Failed to back up old client")

        # 6. Run installer (force close any stale client; updater is in temp so safe)
        if installer is None or not run_installer(installer):
            logger.error("Update installation failed")
            if backup.exists():
                try:
                    shutil.copy2(str(backup), str(target_exe))
                    logger.info("Restored old client from backup")
                    start_client(target_exe)
                except Exception:
                    logger.exception("Failed to restore old client")
            return 1

        # 7. Restart client
        if not start_client(target_exe):
            return 1

        # 8. Cleanup
        try:
            backup.unlink(missing_ok=True)
        except Exception:
            pass
        if installer and installer.parent == Path(tempfile.gettempdir()):
            try:
                installer.unlink(missing_ok=True)
            except Exception:
                pass
        # Remove our temp work dir after a delay so the OS releases handles
        def _cleanup_workdir():
            try:
                time.sleep(2)
                if work_dir.exists() and work_dir != install_dir:
                    shutil.rmtree(work_dir, ignore_errors=True)
            except Exception:
                pass

        threading.Thread(target=_cleanup_workdir, daemon=True).start()

        logger.info("Update complete")
    except Exception:
        logger.exception("Updater crashed")
        return 1
    finally:
        logging.shutdown()
    return 0


if __name__ == "__main__":
    sys.exit(main())
