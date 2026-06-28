"""Windows tray entry point for ArmaLogs client."""
import logging
import sys
import threading
import webbrowser
from pathlib import Path

import pystray
from PIL import Image, ImageDraw

from .config import default_config
from .setup_dialog import run_setup_dialog
from .updater import check_update
from .watcher import Watcher


__version__ = "1.1.1"


def create_image():
    width = 64
    height = 64
    image = Image.new("RGB", (width, height), "#0f1115")
    dc = ImageDraw.Draw(image)
    dc.rectangle([12, 12, 52, 52], fill="#4f8cff")
    dc.polygon([(20, 34), (32, 20), (44, 34), (38, 34), (38, 46), (26, 46)], fill="#ffffff")
    return image


class TrayApp:
    def __init__(self):
        self.cfg = default_config()
        self.watcher: Watcher | None = None
        self._thread: threading.Thread | None = None
        self._title = f"ArmaLogs v{__version__}"
        self.icon = pystray.Icon(
            "armalogs",
            create_image(),
            self._title,
            menu=pystray.Menu(
                pystray.MenuItem(self._title, lambda icon, item: None, enabled=False),
                pystray.MenuItem("", lambda icon, item: None, enabled=False),
                pystray.MenuItem("Upload now", self.upload_now),
                pystray.MenuItem("Check for updates", self.check_updates),
                pystray.MenuItem("Edit settings", self.edit_settings),
                pystray.MenuItem("Open config folder", self.open_config),
                pystray.MenuItem("Exit", self.exit),
            ),
        )

    def open_config(self, icon, item):
        webbrowser.open(f"file://{self.cfg.path.parent}")

    def upload_now(self, icon, item):
        if self.watcher:
            threading.Thread(target=self.watcher.run_once, daemon=True).start()

    def check_updates(self, icon, item):
        if self.watcher:
            threading.Thread(target=self.watcher.check_update_now, daemon=True).start()

    def edit_settings(self, icon, item):
        if self.watcher:
            try:
                self.watcher.stop()
            except Exception:
                pass
            self.watcher = None
        if run_setup_dialog():
            self.cfg = default_config()
            threading.Thread(target=self._watch, daemon=True).start()

    def exit(self, icon, item):
        if self.watcher:
            self.watcher.stop()
        icon.stop()

    def run(self):
        if not self.cfg.is_complete():
            if not run_setup_dialog():
                return
            self.cfg = default_config()
        self._thread = threading.Thread(target=self._watch, daemon=True)
        self._thread.start()
        self.icon.run()

    def _watch(self):
        try:
            self.watcher = Watcher(self.cfg)
            self.watcher.start()
        except Exception:
            logging.exception("Watcher crashed")
            self.icon.title = f"ArmaLogs v{__version__} — watcher error"


def main():
    log_dir = Path.home() / ".armalogs"
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / "client.log"
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
        handlers=[
            logging.FileHandler(str(log_file), mode="a", encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )
    logger = logging.getLogger("armalogs.tray")
    logger.info("Log file: %s", log_file)
    app = TrayApp()
    app.run()


if __name__ == "__main__":
    main()
