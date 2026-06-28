"""Watchdog-based log folder scanner."""
import logging
import re
import socket
import threading
import time
from pathlib import Path
from typing import Callable

import requests
from watchdog.events import FileSystemEventHandler
from watchdog.observers import Observer

from .config import Config
from .uploader import upload_session
from .updater import check_update


logger = logging.getLogger("armalogs.watcher")
LOG_SESSION_RE = re.compile(r"logs_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}")


class LogFolderHandler(FileSystemEventHandler):
    def __init__(self, on_session: Callable[[Path], None]):
        self.on_session = on_session
        self._seen: set[Path] = set()

    def on_created(self, event):
        if event.is_directory:
            path = Path(event.src_path)
            if LOG_SESSION_RE.match(path.name) and path not in self._seen:
                self._seen.add(path)
                self.on_session(path)


class Watcher:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self._observer: Observer | None = None

    def discover_new_sessions(self) -> list[Path]:
        root = Path(self.cfg.get("log_root"))
        if not root.is_dir():
            logger.error("Log root does not exist: %s", root)
            return []
        uploaded = set(self.cfg.get("uploaded_sessions", []))
        found: list[Path] = []
        for child in sorted(root.iterdir()):
            if child.is_dir() and LOG_SESSION_RE.match(child.name):
                sid = child.name
                if sid not in uploaded:
                    found.append(child)
        return found

    def run_once(self) -> None:
        server = self.cfg.get("server_url")
        token = self.cfg.get("token")
        logger.info("run_once: server=%s token_set=%s", server, bool(token))
        if not server or not token:
            logger.error("Server URL or token missing; update config.json")
            return
        hostname = socket.gethostname()
        sessions = self.discover_new_sessions()
        logger.info("run_once: found %d new session(s)", len(sessions))
        for session_dir in sessions:
            logger.info("run_once: uploading %s", session_dir.name)
            try:
                result = upload_session(server, token, session_dir, hostname)
                if result.get("ok") or result.get("skipped"):
                    self.cfg.set(
                        "uploaded_sessions",
                        list(set(self.cfg.get("uploaded_sessions", [])) | {session_dir.name}),
                    )
                    logger.info("Uploaded %s: %s", session_dir.name, result)
                else:
                    logger.error("Server rejected %s: %s", session_dir.name, result)
            except Exception:
                logger.exception("Failed to upload %s", session_dir.name)

    def check_update_now(self) -> None:
        try:
            from client import __version__
            should_restart, msg = check_update(__version__)
            if msg:
                logger.info("Update check: %s", msg)
        except Exception:
            logger.exception("Update check failed")

    def start(self) -> None:
        root = Path(self.cfg.get("log_root"))
        if not root.is_dir():
            raise RuntimeError(f"Log root does not exist: {root}")

        # Upload any backlog before watching
        self.run_once()

        # Check for updates on startup (detached so UI is responsive)
        threading.Thread(target=self.check_update_now, daemon=True).start()

        handler = LogFolderHandler(on_session=self._on_new_session)
        self._observer = Observer()
        self._observer.schedule(handler, str(root), recursive=False)
        self._observer.start()
        logger.info("Watching %s", root)

        interval = int(self.cfg.get("scan_interval_seconds", 30))
        update_interval_seconds = 3600
        last_update_check = time.time()
        try:
            while True:
                time.sleep(interval)
                self.run_once()
                if time.time() - last_update_check >= update_interval_seconds:
                    last_update_check = time.time()
                    threading.Thread(target=self.check_update_now, daemon=True).start()
        except KeyboardInterrupt:
            self.stop()

    def _on_new_session(self, path: Path) -> None:
        logger.info("New session folder detected: %s", path)
        self.run_once()

    def stop(self) -> None:
        if self._observer:
            self._observer.stop()
            self._observer.join()
            self._observer = None
