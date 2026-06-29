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

Fallback if installer-url/download fails:
    The updater checks the install dir for an existing ArmaLogsClientSetup.exe
    placed there by the main client and runs that instead.
"""
import argparse
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

logger = logging.getLogger("armalogs.updater_exe")

INSTALLER_NAME = "ArmaLogsClientSetup.exe"
LOG_DIR = Path.home() / ".armalogs"


def setup_logging() -> Path:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    log_file = LOG_DIR / "updater.log"
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[
            logging.FileHandler(str(log_file), mode="a", encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )
    return log_file


def wait_for_parent(pid: int, timeout: int = 60) -> bool:
    if pid <= 0:
        logger.info("No parent PID to wait for")
        return True
    logger.info("Waiting for parent PID %d to exit (timeout %ds)", pid, timeout)
    start = time.time()
    while time.time() - start < timeout:
        try:
            # os.kill(pid, 0) raises if process is gone
            os.kill(pid, 0)
        except OSError:
            logger.info("Parent PID %d has exited", pid)
            return True
        time.sleep(1)
    logger.warning("Timed out waiting for parent PID %d", pid)
    return False


def download_with_retry(url: str, dst: Path, retries: int = 3) -> bool:
    for attempt in range(1, retries + 1):
        try:
            logger.info("Downloading installer (attempt %d/%d): %s", attempt, retries, url)
            req = urllib.request.Request(
                url,
                headers={
                    "Accept": "application/octet-stream",
                    "User-Agent": "ArmaLogsClientUpdater/1.0",
                },
            )
            with urllib.request.urlopen(req, timeout=240) as resp:
                dst.write_bytes(resp.read())
            size = dst.stat().st_size
            logger.info("Installer saved to %s (%d bytes)", dst, size)
            if size < 1_000_000:
                logger.error("Installer suspiciously small (%d bytes), treating as failure", size)
                return False
            return True
        except Exception:
            logger.exception("Download attempt %d failed", attempt)
            if attempt < retries:
                time.sleep(2 ** attempt)
    return False


def run_installer(installer: Path) -> bool:
    if not installer.exists():
        logger.error("Installer not found: %s", installer)
        return False
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
        result = subprocess.run(args, check=False, timeout=300)
        logger.info("Installer exit code: %d", result.returncode)
        return result.returncode == 0
    except subprocess.TimeoutExpired:
        logger.error("Installer timed out after 5 minutes")
    except Exception:
        logger.exception("Installer failed")
    return False


def start_client(exe: Path) -> bool:
    if not exe.exists():
        logger.error("Client exe not found: %s", exe)
        return False
    logger.info("Starting client: %s", exe)
    try:
        subprocess.Popen(
            [str(exe)],
            creationflags=subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP,
            close_fds=True,
        )
        return True
    except Exception:
        logger.exception("Failed to start client")
    return False


def main() -> int:
    setup_logging()
    logger.info("Starting updater with args: %s", sys.argv)

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

    # 1. Wait for parent to release the locked EXE
    wait_for_parent(args.parent_pid)

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
    logging.shutdown()
    return 0


if __name__ == "__main__":
    sys.exit(main())
