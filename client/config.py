"""Client configuration and JSON persistence."""
import json
import os
from pathlib import Path

DEFAULT_CONFIG = {
    "server_url": "",
    "token": "",
    "log_root": "",
    "scan_interval_seconds": 30,
    "uploaded_sessions": [],
}


class Config:
    def __init__(self, path: str):
        self.path = Path(path)
        self._data = self._load()

    def _load(self) -> dict:
        if self.path.exists():
            try:
                data = json.loads(self.path.read_text(encoding="utf-8"))
                if isinstance(data, dict):
                    return {**DEFAULT_CONFIG, **data}
            except json.JSONDecodeError:
                pass
        return dict(DEFAULT_CONFIG)

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.path.write_text(json.dumps(self._data, indent=2), encoding="utf-8")

    def get(self, key: str, default=None):
        return self._data.get(key, default)

    def set(self, key: str, value) -> None:
        self._data[key] = value
        self.save()

    @property
    def raw(self) -> dict:
        return dict(self._data)

    def is_complete(self) -> bool:
        return bool(
            self.get("server_url") and self.get("token") and self.get("log_root")
        )


CONFIG_DIR = Path(os.environ.get("ARMALOGS_CONFIG_DIR", Path.home() / ".armalogs"))
CONFIG_FILE = CONFIG_DIR / "config.json"


def default_config() -> Config:
    return Config(str(CONFIG_FILE))
