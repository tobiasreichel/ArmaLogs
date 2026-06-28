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

# Optional:
#    ARMALOGS_SFTP_HOST  app SFTP host (NOT the Cloudron dashboard domain)
#    ARMALOGS_SFTP_PORT  SFTP port (default 22)
#    ARMALOGS_SFTP_USER  SFTP username
#    ARMALOGS_SFTP_PASS  SFTP password
#    ARMALOGS_DOMAIN     public HTTPS domain, used only to clear OPcache
#
# NOTE: For Cloudron, use the app's individual SFTP host (e.g. sftp.armalogs.reichel.network),
# not the Cloudron dashboard domain (my.reichel.network).
# The domain used for opcache_reset may differ from the SFTP host.
"""
import os
import secrets
import shutil
import subprocess
import sys
from pathlib import Path
from urllib.request import urlopen

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
DOMAIN = os.environ.get("ARMALOGS_DOMAIN", HOST)

LOCAL_PUBLIC = Path(os.environ.get("ARMALOGS_PUBLIC_DIR", ROOT / "server" / "public"))
LOCAL_INCLUDES = Path(os.environ.get("ARMALOGS_INCLUDES_DIR", ROOT / "server" / "includes"))

REMOTE_PUBLIC = "public"
REMOTE_INCLUDES = "includes"


def clear_remote_opcache(domain: str, sftp: paramiko.SFTPClient) -> None:
    """Upload a temporary PHP script that calls opcache_reset(), hit it, then delete it."""
    token = secrets.token_urlsafe(32)
    script_name = f"opcache_clear_{token[:16]}.php"
    local_path = ROOT / ".tmp_deploy" / script_name
    local_path.parent.mkdir(parents=True, exist_ok=True)
    local_path.write_text("<?php\nif (!function_exists('opcache_reset')) {\n    echo 'nocache';\n    exit;\n}\nopcache_reset();\necho 'ok';\n", encoding="utf-8")
    remote_path = f"{REMOTE_PUBLIC}/{script_name}"
    sftp.put(str(local_path), remote_path)
    local_path.unlink()

    url = f"https://{domain}/{script_name}"
    try:
        resp = urlopen(url, timeout=15)
        body = resp.read().decode("utf-8", errors="ignore").strip()
        print(f"OPcache clear: {body}")
    except Exception as exc:
        print(f"WARNING: could not clear OPcache via {url}: {exc}")
    finally:
        try:
            sftp.remove(remote_path)
        except OSError:
            pass


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

    print("Linting PHP files before deploy...")
    php_files = sorted(set(LOCAL_PUBLIC.rglob("*.php")) | set(LOCAL_INCLUDES.rglob("*.php")))
    php_cmd = shutil.which("php")
    if php_cmd is None:
        print("WARNING: php not found in PATH; skipping lint. Install PHP or rely on CI.")
    else:
        for path in php_files:
            result = subprocess.run(
                [php_cmd, "-l", str(path)],
                capture_output=True,
                text=True,
                cwd=ROOT,
            )
            if result.returncode != 0:
                print(f"PHP lint failed for {path}:")
                print(result.stdout)
                print(result.stderr)
                return 1
        print(f"Linted {len(php_files)} PHP files.")

    transport = paramiko.Transport((HOST, PORT))
    transport.connect(username=USER, password=PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    try:
        upload_dir(sftp, LOCAL_PUBLIC, REMOTE_PUBLIC)
        upload_dir(sftp, LOCAL_INCLUDES, REMOTE_INCLUDES)
        clear_remote_opcache(DOMAIN, sftp)
        print("Deploy complete.")
    finally:
        sftp.close()
        transport.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
