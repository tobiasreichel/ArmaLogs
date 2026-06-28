#!/usr/bin/env python3
"""Deploy ArmaLogs server code to Cloudron via SFTP.

Usage:
    python server/deploy/deploy.py

Reads credentials from a `.env` file in the project root, falling back to
environment variables. Put this in your `.env` (never commit it):

    ARMALOGS_SFTP_HOST=my.reichel.network
    ARMALOGS_SFTP_PORT=222
    ARMALOGS_SFTP_USER=toreic@armalogs.reichel.network
    ARMALOGS_SFTP_PASS=your_password

Optional:
    ARMALOGS_PUBLIC_DIR  local public dir  (default: server/public)
    ARMALOGS_INCLUDES_DIR local includes dir (default: server/includes)
"""
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[2]


def load_env_file(path: Path) -> None:
    """Load KEY=VALUE lines from a .env file into os.environ."""
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('\'"')
        if key and key not in os.environ:
            os.environ[key] = value


load_env_file(ROOT / ".env")

HOST = os.environ.get("ARMALOGS_SFTP_HOST", "")
PORT = int(os.environ.get("ARMALOGS_SFTP_PORT", "22"))
USER = os.environ.get("ARMALOGS_SFTP_USER", "")
PASS = os.environ.get("ARMALOGS_SFTP_PASS", "")

LOCAL_PUBLIC = Path(os.environ.get("ARMALOGS_PUBLIC_DIR", ROOT / "server" / "public"))
LOCAL_INCLUDES = Path(os.environ.get("ARMALOGS_INCLUDES_DIR", ROOT / "server" / "includes"))

REMOTE_PUBLIC = "public"
REMOTE_INCLUDES = "includes"


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote_path: str) -> None:
    parts = remote_path.strip('/').split('/')
    current = ''
    for part in parts:
        current += '/' + part
        try:
            sftp.mkdir(current)
            print(f"mkdir {current}")
        except OSError:
            pass


def upload_dir(sftp: paramiko.SFTPClient, local: Path, remote: str) -> None:
    ensure_remote_dir(sftp, remote)
    for path in sorted(local.rglob("*"), key=lambda p: str(p)):
        rel = path.relative_to(local).as_posix()
        remote_path = f"{remote}/{rel}"
        if path.is_dir():
            ensure_remote_dir(sftp, remote_path)
        else:
            ensure_remote_dir(sftp, str(Path(remote_path).parent).replace('\\', '/'))
            sftp.put(str(path), remote_path)
            print(f"put {path} -> {remote_path}")


def main() -> int:
    if not all([HOST, USER, PASS]):
        print("Set ARMALOGS_SFTP_HOST, ARMALOGS_SFTP_PORT, ARMALOGS_SFTP_USER, ARMALOGS_SFTP_PASS in .env or environment")
        return 1

    transport = paramiko.Transport((HOST, PORT))
    transport.connect(username=USER, password=PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    try:
        upload_dir(sftp, LOCAL_PUBLIC, REMOTE_PUBLIC)
        upload_dir(sftp, LOCAL_INCLUDES, REMOTE_INCLUDES)
        print("Deploy complete.")
    finally:
        sftp.close()
        transport.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
