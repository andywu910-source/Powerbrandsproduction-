#!/usr/bin/env bash
# PowerBrands Productions: remove the site, pool, database and user. staticpowered is not touched.
# Run as root:  bash rollback.sh    (a DB dump is saved to /root first)
set -uo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }
read -r -p "Type DELETE to remove powerbrandsproductions completely: " a
[ "$a" = DELETE ] || { echo "Cancelled."; exit 1; }
TS=$(date +%Y%m%d-%H%M%S)

if mariadb -e 'USE pbp_db' 2>/dev/null; then
  mariadb-dump --single-transaction pbp_db | gzip > "/root/pbp_db-before-rollback-$TS.sql.gz" \
    && echo "DB backup: /root/pbp_db-before-rollback-$TS.sql.gz"
fi

rm -f /etc/nginx/sites-enabled/powerbrandsproductions /etc/nginx/sites-available/powerbrandsproductions
nginx -t && systemctl reload nginx

rm -f /etc/php/8.4/fpm/pool.d/pbp.conf
php-fpm8.4 -t && systemctl reload php8.4-fpm

mariadb <<'SQL'
DROP DATABASE IF EXISTS pbp_db;
DROP USER IF EXISTS 'pbp_user'@'localhost';
DROP USER IF EXISTS 'pbp_user'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

rm -rf /var/www/powerbrandsproductions
id pbp >/dev/null 2>&1 && userdel pbp
if [ -d /etc/letsencrypt/live/powerbrandsproductions.com ]; then
  certbot delete --cert-name powerbrandsproductions.com -n || true
fi
echo "Rollback done. staticpowered check: $(curl -sk -o /dev/null -w '%{http_code}' --resolve staticpowered.com:443:127.0.0.1 https://staticpowered.com/up)"
