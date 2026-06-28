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
    """Best-effort list of subscribed Workshop mods.

    Tries, in order:
    1. Arma Reforger `addons` folder (e.g. `Documents\My Games\ArmaReforger\addons`).
       Folder names are `ModName_WorkshopID` — the most reliable local source.
    2. Arma Reforger Launcher preset JSON (enabled addons list).
    3. Steam workshop app manifest for appid 1874880.

    Does NOT fall back to console.log because that lists engine addon GUIDs,
    not Workshop subscriptions.
    """
    logger.debug("Detecting Workshop mods for log root: %s", log_root)

    mods = _mods_from_addons_folder(log_root)
    if mods:
        logger.info("Using addons folder with %d Workshop mod(s)", len(mods))
        return mods

    mods = _mods_from_launcher_preset(log_root)
    if mods:
        logger.info("Using launcher preset with %d Workshop mod(s)", len(mods))
        return mods

    mods = _mods_from_steam_appworkshop()
    if mods:
        logger.info("Using Steam appworkshop with %d Workshop mod(s)", len(mods))
        return mods

    logger.warning("No Workshop mod source found for %s", log_root)
    return []


def _mods_from_addons_folder(log_root: Path) -> list[WorkshopMod]:
    """Parse the local Arma Reforger addons folder for mod names + Workshop IDs."""
    candidates: list[Path] = [
        log_root.parent / "addons",
        Path.home() / "Documents" / "My Games" / "ArmaReforger" / "addons",
        Path.home() / "My Games" / "ArmaReforger" / "addons",
    ]
    seen: set[str] = set()
    mods: list[WorkshopMod] = []
    for addons_dir in candidates:
        if not addons_dir.is_dir():
            continue
        logger.debug("Scanning addons folder: %s", addons_dir)
        for entry in sorted(addons_dir.iterdir()):
            if not entry.is_dir():
                continue
            name = entry.name.strip()
            # Names are like: ModName_5E73C7BC12345678 or {ID} mod_name
            m = re.match(r"^(.+?)_([A-Fa-f0-9]{16})$", name)
            if m:
                mod_name = m.group(1)
                wid = m.group(2).upper()
            else:
                # Some folders are just the workshop ID, or no ID
                m2 = re.match(r"^([A-Fa-f0-9]{16})$", name)
                if m2:
                    wid = m2.group(1).upper()
                    mod_name = wid
                else:
                    # Look for .workshop_id file inside
                    wid_file = entry / ".workshop_id"
                    if wid_file.is_file():
                        wid = wid_file.read_text(encoding="utf-8").strip()
                        mod_name = name
                    else:
                        continue
            key = wid.lower()
            if key not in seen:
                seen.add(key)
                mods.append(WorkshopMod(wid, mod_name, "addons"))
        if mods:
            break
    return mods


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

