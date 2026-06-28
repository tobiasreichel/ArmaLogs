"""Watchdog-based log folder scanner."""
import logging
import socket
import time
from pathlib import Path
from typing import Callable

from watchdog.events import FileSystemEventHandler
from watchdog.observers import Observer

from .config import Config
from .uploader import LOG_SESSION_RE, upload_session

logger = logging.getLogger("armalogs.watcher")


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
        if not server or not token:
            logger.error("Server URL or token missing; update config.json")
            return
        hostname = socket.gethostname()
        for session_dir in self.discover_new_sessions():
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

    def start(self) -> None:
        root = Path(self.cfg.get("log_root"))
        if not root.is_dir():
            raise RuntimeError(f"Log root does not exist: {root}")

        # Upload any backlog before watching
        self.run_once()

        handler = LogFolderHandler(on_session=self._on_new_session)
        self._observer = Observer()
        self._observer.schedule(handler, str(root), recursive=False)
        self._observer.start()
        logger.info("Watching %s", root)

        interval = int(self.cfg.get("scan_interval_seconds", 30))
        try:
            while True:
                time.sleep(interval)
                self.run_once()
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
