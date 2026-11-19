#!/usr/bin/env bash
# PowerBrands Productions: first-time setup on the existing Debian 13 server.
# Run as root from the extracted folder:   bash deploy.sh [/path/to/.env.production]
# Does NOT touch staticpowered (/var/www/powerbrandsny, its nginx site or php-fpm pool).
set -euo pipefail

APP=/var/www/powerbrandsproductions
ENV_SRC="${1:-/home/andy/.env.production}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE=/etc/nginx/sites-available/powerbrandsproductions
POOL=/etc/php/8.4/fpm/pool.d/pbp.conf
PHP=/usr/bin/php8.4

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
as_pbp() { runuser -u pbp -- "$@"; }

say "Pre-flight checks"
[ "$(id -u)" -eq 0 ] || fail "Run as root (sudo -i first)."
[ -x "$PHP" ] || fail "$PHP not found."
command -v nginx >/dev/null || fail "nginx not installed."
command -v mariadb >/dev/null || fail "mariadb client not installed."
[ -f "$ENV_SRC" ] || fail "Env file not found: $ENV_SRC"
[ -f "$SRC/artisan" ] || fail "Run this from the extracted powerbrandsproductions folder."
[ -e "$APP" ] && fail "$APP already exists. Run rollback.sh first, or this is an upgrade (not handled here)."
[ -e "$SITE" ] && fail "$SITE already exists."
[ -e "$POOL" ] && fail "$POOL already exists."
for m in pdo_mysql mbstring dom xml curl openssl tokenizer ctype fileinfo; do
  "$PHP" -m | grep -qix "$m" || fail "PHP extension missing: $m (apt install php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl)"
done
DBPASS="$(grep -E '^DB_PASSWORD=' "$ENV_SRC" | head -1 | cut -d= -f2-)"
[[ "$DBPASS" =~ ^[A-Za-z0-9]{20,}$ ]] || fail "DB_PASSWORD in $ENV_SRC is missing or not alphanumeric."
grep -q '^APP_KEY=base64:' "$ENV_SRC" || fail "APP_KEY missing in $ENV_SRC."

say "System user pbp"
if ! id pbp >/dev/null 2>&1; then
  useradd --system --user-group --home-dir "$APP" --no-create-home --shell /usr/sbin/nologin pbp
fi

say "Copy app to $APP"
mkdir -p "$APP"
cp -a "$SRC/." "$APP/"
rm -f "$APP/.env"
install -o root -g pbp -m 640 "$ENV_SRC" "$APP/.env"

say "Permissions"
chown -R root:pbp "$APP"
find "$APP" -type d -exec chmod 755 {} +
find "$APP" -type f -exec chmod 644 {} +
chmod 640 "$APP/.env"
mkdir -p "$APP"/storage/{logs,app/private,app/public,framework/{cache/data,sessions,views,testing}} "$APP/bootstrap/cache"
chown -R pbp:pbp "$APP/storage" "$APP/bootstrap/cache"
chmod -R u+rwX,g+rwX,o-rwx "$APP/storage" "$APP/bootstrap/cache"
chmod 700 "$APP/deploy"

say "Database pbp_db and user pbp_user"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS pbp_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pbp_user'@'localhost' IDENTIFIED BY '${DBPASS}';
CREATE USER IF NOT EXISTS 'pbp_user'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
ALTER USER 'pbp_user'@'localhost' IDENTIFIED BY '${DBPASS}';
ALTER USER 'pbp_user'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON pbp_db.* TO 'pbp_user'@'localhost';
GRANT ALL PRIVILEGES ON pbp_db.* TO 'pbp_user'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
unset DBPASS

say "PHP-FPM pool pbp"
install -o root -g root -m 644 "$APP/deploy/php-fpm-pbp.conf" "$POOL"
if ! php-fpm8.4 -t >/dev/null 2>&1; then
  rm -f "$POOL"; php-fpm8.4 -t || true; fail "php-fpm config test failed; pool file removed."
fi
systemctl reload php8.4-fpm
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -S /run/php/php8.4-fpm-pbp.sock ] && break; sleep 1; done
[ -S /run/php/php8.4-fpm-pbp.sock ] || fail "php-fpm socket /run/php/php8.4-fpm-pbp.sock did not appear."

say "Laravel: migrate, first admin, caches"
cd "$APP"
as_pbp "$PHP" artisan migrate --force
as_pbp "$PHP" artisan pbp:create-admin
sed -i -e '/^PBP_ADMIN_INITIAL_PASSWORD_HASH=/d' -e '/^# One-time: bcrypt hash/d' "$APP/.env"
as_pbp "$PHP" artisan config:cache
as_pbp "$PHP" artisan route:cache
as_pbp "$PHP" artisan view:cache

say "nginx site (HTTP first)"
install -o root -g root -m 644 "$APP/deploy/nginx-powerbrandsproductions.conf" "$SITE"
ln -sfn "$SITE" /etc/nginx/sites-enabled/powerbrandsproductions
if ! nginx -t; then
  rm -f /etc/nginx/sites-enabled/powerbrandsproductions
  fail "nginx -t failed; site disabled again. staticpowered is untouched."
fi
systemctl reload nginx

say "Checks"
sleep 1
NEW=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: powerbrandsproductions.com' http://127.0.0.1/up || true)
LOGIN=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: powerbrandsproductions.com' http://127.0.0.1/login || true)
OLD=$(curl -sk -o /dev/null -w '%{http_code}' --resolve staticpowered.com:443:127.0.0.1 https://staticpowered.com/up || true)
echo "powerbrandsproductions /up    -> $NEW (want 200)"
echo "powerbrandsproductions /login -> $LOGIN (want 200)"
echo "staticpowered /up             -> $OLD (want 200)"
[ "$NEW" = 200 ] && [ "$LOGIN" = 200 ] || fail "New site check failed. See $APP/storage/logs and /var/log/nginx/powerbrandsproductions.error.log"

rm -f "$ENV_SRC"
echo
echo "DONE. Deleted $ENV_SRC (the live copy is $APP/.env)."
echo "Next: once DNS points to this server, run:  bash certbot.sh"
