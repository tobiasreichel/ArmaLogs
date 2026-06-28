"""Upload helpers for the ArmaLogs Windows client."""
import hashlib
import logging
import re
import time
from pathlib import Path
from typing import Iterable, Optional

import requests

logger = logging.getLogger("armalogs.uploader")


from .mod_count import detect_workshop_mod_count

LOG_SESSION_RE = re.compile(r"logs_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}");


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        while True:
            chunk = f.read(65536)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def session_id_from_path(path: Path) -> Optional[str]:
    for part in path.parts:
        m = LOG_SESSION_RE.match(part)
        if m:
            return m.group(0)
    return None


def collect_log_files(session_dir: Path) -> list[Path]:
    files: list[Path] = []
    if not session_dir.is_dir():
        return files
    for pattern in ("*.log", "*.mdmp", "*.txt"):
        files.extend(session_dir.glob(pattern))
    return files


def upload_session(
    server_url: str,
    token: str,
    session_dir: Path,
    hostname: str,
    retries: int = 3,
    timeout: int = 120,
) -> dict:
    files = collect_log_files(session_dir)
    if not files:
        logger.info("No log files in %s", session_dir)
        return {"ok": True, "uploaded": [], "skipped": True}

    session_id = session_id_from_path(session_dir)
    fields = {"session_id": session_id or "unknown"}
    if hostname:
        fields["hostname"] = hostname
    mod_count = detect_workshop_mod_count(session_dir)
    if mod_count is not None:
        fields["workshop_mod_count"] = str(mod_count)
        logger.info("Detected %d Workshop mods for %s", mod_count, session_dir.name)

    opened: list[tuple[str, bytes, Path]] = []
    try:
        for f in files:
            opened.append((f.name, f.read_bytes(), f))

        # Try to deduplicate via lightweight pre-check using a list of local SHAs
        payload_files = [
            ("logs[]", (name, data, "application/octet-stream"))
            for name, data, _ in opened
        ]

        last_error: Optional[Exception] = None
        for attempt in range(retries):
            try:
                headers = {"Authorization": f"Bearer {token}", "X-Friend-Token": token}
                logger.info("POST %s files=%d attempt=%d", server_url, len(payload_files), attempt + 1)
                resp = requests.post(
                    server_url,
                    files=payload_files,
                    data=fields,
                    headers=headers,
                    timeout=timeout,
                )
                logger.info("POST %s -> %d %s len=%d", server_url, resp.status_code, resp.reason, len(resp.text))
                resp.raise_for_status()
                data = resp.json()
                logger.info("Server response: %s", data)
                return data
            except requests.RequestException as exc:
                last_error = exc
                logger.warning("Upload attempt %d failed: %s", attempt + 1, exc)
                time.sleep(2 ** attempt)
        raise last_error or RuntimeError("Upload failed")
    finally:
        for _, _, path in opened:
            pass
