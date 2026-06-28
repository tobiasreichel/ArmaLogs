#!/usr/bin/env bash
set -euo pipefail

echo "=== ArmaLogs server deploy script ==="

if [ "$EUID" -ne 0 ]; then
  echo "Run as root (or use sudo)."
  exit 1
fi

DOMAIN=${1:-armalogs.local}
WEBROOT=/var/www/armalogs
DB_NAME=${ARMALOGS_DB_NAME:-armalogs}
DB_USER=${ARMALOGS_DB_USER:-armalogs}
DB_PASS=${ARMALOGS_DB_PASS:-$(openssl rand -base64 24)}

apt-get update
apt-get install -y apache2 php8.3 php8.3-mysql php8.3-mbstring php8.3-intl mysql-server

# MySQL database/user
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# Layout
mkdir -p "${WEBROOT}/storage/logs"
mkdir -p "${WEBROOT}/public"
mkdir -p "${WEBROOT}/includes"

# Copy files
rsync -a --delete server/public/ "${WEBROOT}/public/"
rsync -a --delete server/includes/ "${WEBROOT}/includes/"

# Config
if [ ! -f "${WEBROOT}/includes/config.php" ]; then
  cp server/includes/config.example.php "${WEBROOT}/includes/config.php"
  sed -i "s/CHANGEME/${DB_PASS}/g" "${WEBROOT}/includes/config.php"
  sed -i "s|'name'    => 'armalogs',|'name'    => '${DB_NAME}',|g" "${WEBROOT}/includes/config.php"
  sed -i "s|'user'    => 'armalogs',|'user'    => '${DB_USER}',|g" "${WEBROOT}/includes/config.php"
fi

# Permissions
chown -R www-data:www-data "${WEBROOT}"
chmod 750 "${WEBROOT}/includes"
chmod 770 "${WEBROOT}/storage"
chmod 755 "${WEBROOT}/public"

# Schema
mysql "${DB_NAME}" < server/sql/schema.sql

# Apache site
cat > /etc/apache2/sites-available/armalogs.conf <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEBROOT}/public

    <Directory ${WEBROOT}/public>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory ${WEBROOT}/includes>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/armalogs_error.log
    CustomLog \${APACHE_LOG_DIR}/armalogs_access.log combined
</VirtualHost>
EOF

a2enmod rewrite
a2dissite 000-default || true
a2ensite armalogs
systemctl restart apache2

echo ""
echo "Deploy complete. Admin password set via /setup.php on first visit."
echo "Database password: ${DB_PASS}"
echo "Web root: ${WEBROOT}"
