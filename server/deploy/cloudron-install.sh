#!/usr/bin/env bash
# One-command install/update for ArmaLogs on Cloudron LAMP.
# Run inside the Cloudron Terminal for this app:
#   curl -fsSL https://raw.githubusercontent.com/<user>/<repo>/main/server/deploy/cloudron-install.sh | bash
#
# Or, if you already cloned the repo inside /tmp:
#   bash /tmp/armalogs/server/deploy/cloudron-install.sh

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/<user>/<repo>}"
BRANCH="${BRANCH:-main}"
APP_DIR="/app/data"
PUBLIC_DIR="${APP_DIR}/public"
INCLUDES_DIR="${APP_DIR}/includes"
STORAGE_DIR="${APP_DIR}/storage/logs"

log() { echo "[$(date +%H:%M:%S)] $*"; }

if [[ "$(pwd)" != "/app/data"* ]]; then
    log "WARNING: not running from /app/data. This script is meant for Cloudron Terminal."
    log "Current directory: $(pwd)"
    read -r -p "Continue anyway? [y/N] " reply || true
    if [[ "${reply:-N}" != "y" && "${reply:-N}" != "Y" ]]; then
        exit 1
    fi
fi

# Ensure required directories exist
mkdir -p "${PUBLIC_DIR}" "${INCLUDES_DIR}" "${STORAGE_DIR}"

# Get the latest source
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

if [[ -d /app/data/public/.git ]]; then
    log "Updating from existing git clone..."
    cd /app/data/public
    git fetch origin
    git reset --hard "origin/${BRANCH}"
    SOURCE_DIR=/app/data/public
else
    log "Downloading latest source from ${REPO_URL}/archive/${BRANCH}.tar.gz ..."
    curl -fsSL "${REPO_URL}/archive/${BRANCH}.tar.gz" -o "${WORK_DIR}/source.tar.gz"
    tar -xzf "${WORK_DIR}/source.tar.gz" -C "${WORK_DIR}"
    SOURCE_DIR="$(find "${WORK_DIR}" -maxdepth 1 -type d | grep -v '^${WORK_DIR}$' | head -n1)/server"
fi

# Sync public files
log "Syncing public/..."
rsync -a --delete --exclude='config.php' --exclude='storage' "${SOURCE_DIR}/public/" "${PUBLIC_DIR}/"

# Sync includes files
log "Syncing includes/..."
rsync -a --delete --exclude='config.php' "${SOURCE_DIR}/includes/" "${INCLUDES_DIR}/"

# Create config.php from example if missing
if [[ ! -f "${INCLUDES_DIR}/config.php" ]]; then
    log "Creating config.php from example..."
    cp "${INCLUDES_DIR}/config.example.php" "${INCLUDES_DIR}/config.php"

    # Cloudron LAMP provides these via environment in newer versions,
    # but fall back to asking if not present.
    DB_HOST="${CLOUDRON_MYSQL_HOST:-localhost}"
    DB_NAME="${CLOUDRON_MYSQL_DATABASE:-armalogs}"
    DB_USER="${CLOUDRON_MYSQL_USERNAME:-armalogs}"
    DB_PASSWORD="${CLOUDRON_MYSQL_PASSWORD:-}"

    if [[ -z "${DB_PASSWORD}" ]]; then
        read -r -s -p "Enter MySQL password: " DB_PASSWORD
        echo
    fi

    sed -i "s|'host'    => 'localhost',|'host'    => '${DB_HOST}',|" "${INCLUDES_DIR}/config.php"
    sed -i "s|'name'    => 'armalogs',|'name'    => '${DB_NAME}',|" "${INCLUDES_DIR}/config.php"
    sed -i "s|'user'    => 'armalogs',|'user'    => '${DB_PASSWORD}',|" "${INCLUDES_DIR}/config.php"
    sed -i "s|'pass'    => 'CHANGEME',|'pass'    => '${DB_PASSWORD}',|" "${INCLUDES_DIR}/config.php"
fi

# Import schema if the friends table does not exist
log "Checking database schema..."
mysql "${CLOUDRON_MYSQL_DATABASE:-armalogs}" -e "SELECT 1 FROM friends LIMIT 1" > /dev/null 2>&1 || {
    log "Importing schema..."
    mysql "${CLOUDRON_MYSQL_DATABASE:-armalogs}" < "${SOURCE_DIR}/sql/schema.sql"
}

# Permissions
chown -R www-data:www-data "${PUBLIC_DIR}" "${INCLUDES_DIR}" "${STORAGE_DIR}" 2>/dev/null || true
chmod 770 "${STORAGE_DIR}"
chmod 755 "${PUBLIC_DIR}"
chmod 750 "${INCLUDES_DIR}"

log "Restarting Cloudron app..."
cloudron-support restart 2>/dev/null || supervisorctl restart apache2 2>/dev/null || true

log "Done. Visit https://$(hostname -f)/setup.php to create the first admin."
log "Storage path: ${STORAGE_DIR}"
