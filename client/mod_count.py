"""Detect the real number of Workshop mods a player has subscribed/loaded.

Arma Reforger's engine reports ~396 addon projects even when only ~120 Workshop
items are subscribed, because many Workshop items ship multiple .gproj addons.
This module reads the launcher preset / Steam Workshop manifest to get the
actual subscription count.
"""
import json
import logging
import re
from pathlib import Path
from typing import Optional

logger = logging.getLogger("armalogs.mod_count")


def detect_workshop_mod_count(log_root: Path) -> Optional[int]:
    """Best-effort true Workshop subscription count.

    Tries, in order:
    1. Arma Reforger Launcher preset JSON (enabled addons list).
    2. Steam workshop app manifest for appid 1874880.
    3. Count unique addons backslash Name_GUID folders in console.log (fallback, not Workshop).
    """
    count = _from_launcher_preset(log_root)
    if count is not None:
        return count

    count = _from_steam_appworkshop()
    if count is not None:
        return count

    return None


def _from_launcher_preset(log_root: Path) -> Optional[int]:
    """Look for launcher profiles near the log root or in the default path."""
    candidates: list[Path] = []

    # Default Bohemia Interactive launcher profile location
    default = Path.home() / "AppData" / "Local" / "Bohemia Interactive" / "Arma Reforger Launcher" / "profiles"
    if default.is_dir():
        candidates.extend(default.glob("*.json"))

    # Also check under log root parent (some users customize location)
    launcher_dir = log_root.parent / "launcher" / "profiles"
    if launcher_dir.is_dir():
        candidates.extend(launcher_dir.glob("*.json"))

    best: Optional[int] = None
    for path in candidates:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        addons = data.get("addons") if isinstance(data, dict) else None
        if isinstance(addons, list):
            n = len(addons)
            logger.info("Launcher preset %s has %d addon entries", path, n)
            if best is None or n > best:
                best = n
    return best


def _from_steam_appworkshop() -> Optional[int]:
    """Count subscribed items in Steam's appworkshop file for Arma Reforger."""
    steam_roots = [
        Path("C:/Program Files (x86)/Steam"),
        Path("C:/Program Files/Steam"),
        Path.home() / "AppData" / "Local" / "Steam",
    ]
    for steam_root in steam_roots:
        path = steam_root / "steamapps" / "workshop" / "appworkshop_1874880.acf"
        if not path.is_file():
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except Exception:
            continue
        # Count quoted WorkshopItemIDs inside the file
        items = re.findall(r'"WorkshopItemID"\s+"(\d+)"', text)
        if items:
            n = len(items)
            logger.info("Steam appworkshop has %d subscribed Workshop items", n)
            return n
    return None
