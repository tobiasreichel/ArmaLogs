#!/usr/bin/env python3
"""Deploy ArmaLogs server code to Cloudron via SFTP.

Usage:
    python server/deploy/deploy.py

Required environment variables:
    ARMALOGS_SFTP_HOST
    ARMALOGS_SFTP_PORT
    ARMALOGS_SFTP_USER
    ARMALOGS_SFTP_PASS

Optional:
    ARMALOGS_PUBLIC_DIR  local public dir  (default: server/public)
    ARMALOGS_INCLUDES_DIR local includes dir (default: server/includes)
"""
import os
import sys
from pathlib import Path

import paramiko

HOST = os.environ.get("ARMALOGS_SFTP_HOST", "")
PORT = int(os.environ.get("ARMALOGS_SFTP_PORT", "22"))
USER = os.environ.get("ARMALOGS_SFTP_USER", "")
PASS = os.environ.get("ARMALOGS_SFTP_PASS", "")

ROOT = Path(__file__).resolve().parents[2]
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
    # Create base dir first
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
        print("Set ARMALOGS_SFTP_HOST, ARMALOGS_SFTP_PORT, ARMALOGS_SFTP_USER, ARMALOGS_SFTP_PASS")
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
