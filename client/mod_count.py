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


class WorkshopMod:
    def __init__(self, workshop_id: str, name: str, source: str):
        self.workshop_id = str(workshop_id)
        self.name = str(name)
        self.source = source

    def to_dict(self) -> dict:
        return {"workshop_id": self.workshop_id, "name": self.name, "source": self.source}


def detect_workshop_mod_count(log_root: Path) -> Optional[int]:
    """Best-effort true Workshop subscription count."""
    mods = detect_workshop_mods(log_root)
    if mods:
        return len(mods)
    return None


def detect_workshop_mods(log_root: Path) -> list[WorkshopMod]:
    """Best-effort list of subscribed/loaded Workshop mods.

    Tries, in order:
    1. Arma Reforger Launcher preset JSON (enabled addons list).
    2. Steam workshop app manifest for appid 1874880.
    3. Fallback: parse unique addon GUIDs from console.log (not Workshop, source='log').
    """
    mods = _mods_from_launcher_preset(log_root)
    if mods:
        return mods

    mods = _mods_from_steam_appworkshop()
    if mods:
        return mods

    mods = _mods_from_console_log(log_root)
    if mods:
        return mods

    return []


def _mods_from_launcher_preset(log_root: Path) -> list[WorkshopMod]:
    """Look for launcher profiles near the log root or in the default path."""
    candidates: list[Path] = []

    default = Path.home() / "AppData" / "Local" / "Bohemia Interactive" / "Arma Reforger Launcher" / "profiles"
    if default.is_dir():
        candidates.extend(default.glob("*.json"))

    launcher_dir = log_root.parent / "launcher" / "profiles"
    if launcher_dir.is_dir():
        candidates.extend(launcher_dir.glob("*.json"))

    seen: set[str] = set()
    mods: list[WorkshopMod] = []
    for path in candidates:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        addons = data.get("addons") if isinstance(data, dict) else None
        if not isinstance(addons, list):
            continue
        for entry in addons:
            if not isinstance(entry, dict):
                continue
            wid = entry.get("workshopId") or entry.get("id") or entry.get("name")
            name = entry.get("name") or entry.get("title") or wid
            if wid is None:
                continue
            key = str(wid).lower()
            if key not in seen:
                seen.add(key)
                mods.append(WorkshopMod(wid, name, "launcher"))
    return mods


def _mods_from_steam_appworkshop() -> list[WorkshopMod]:
    """Read subscribed items in Steam's appworkshop file for Arma Reforger."""
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
        mods = _parse_appworkshop(text)
        if mods:
            logger.info("Steam appworkshop has %d item(s)", len(mods))
            return mods
    return []


def _parse_appworkshop(text: str) -> list[WorkshopMod]:
    """Parse WorkshopItemID blocks from appworkshop_*.acf."""
    mods: list[WorkshopMod] = []
    seen: set[str] = set()
    # Each item looks like:
    #   "WorkshopItemID"
    #   {
    #       "appid"  "1874880"
    #       "PublishedFileId"  "1234567890"
    #       "name"  "Cool Mod"
    #       ...
    #   }
    blocks = re.split(r'"WorkshopItemID"\s*\{', text)
    for block in blocks[1:]:
        m = re.search(r'"PublishedFileId"\s*"(\d+)"', block)
        if not m:
            continue
        wid = m.group(1)
        name_match = re.search(r'"name"\s*"([^"]+)"', block)
        name = name_match.group(1) if name_match else wid
        key = wid.lower()
        if key not in seen:
            seen.add(key)
            mods.append(WorkshopMod(wid, name, "steam"))
    return mods


def _mods_from_console_log(log_root: Path) -> list[WorkshopMod]:
    """Fallback: count unique addon GUIDs from console.log files."""
    guids: set[str] = set()
    for log_path in log_root.rglob("console.log"):
        try:
            text = log_path.read_text(encoding="utf-8", errors="ignore")
        except Exception:
            continue
        for line in text.splitlines():
            # Match lines like: BACKEND: Registering addon 'SomeName_GUID'
            m = re.search(r"Registering addon\s+'([^']+_[A-Fa-f0-9]{8,})'", line)
            if m:
                guids.add(m.group(1))
    mods = [WorkshopMod(str(i + 1), name, "log") for i, name in enumerate(sorted(guids))]
    if mods:
        logger.info("console.log fallback has %d addon(s)", len(mods))
    return mods

