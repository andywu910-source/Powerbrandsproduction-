#!/usr/bin/env bash
# Compliance hub (preview-only) upgrade. Auto-restores the backup on any failure.
set -uo pipefail
APP=/var/www/powerbrandsproductions
BK=${BK:?set BK=/root/pbp-backup-compliance-hub-<TS> (a FRESH backup taken just before this run)}
KIT=/tmp/pbp-compliance-hub.tar.gz
PHP=/usr/bin/php8.4
art() { (cd "$APP" && runuser -u pbp -- "$PHP" artisan "$@"); }
restore() {
  echo "!!! FAILED at: $1 -- restoring backup $BK"
  (cd "$APP" && runuser -u pbp -- "$PHP" artisan down 2>/dev/null) || true
  gunzip -c "$BK/pbp_db.sql.gz" | mariadb pbp_db && echo "DB restored"
  mariadb pbp_db -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS log_entry_links, log_entries, log_definitions, logs_preview_tokens; SET FOREIGN_KEY_CHECKS=1;" && echo "new tables dropped"
  rm -rf "$APP" && tar -C /var/www -xzf "$BK/app.tar.gz" && echo "code/.env/storage restored"
  (cd "$APP" && runuser -u pbp -- "$PHP" artisan up) || true
  systemctl reload php8.4-fpm
  echo "after restore /up: $(curl -sk -o /dev/null -w '%{http_code}' --resolve powerbrandsproductions.com:443:127.0.0.1 https://powerbrandsproductions.com/up)"
  exit 1
}
[ -s "$BK/pbp_db.sql.gz" ] && [ -s "$BK/app.tar.gz" ] && [ -s "$BK/.env" ] || { echo "backup missing"; exit 1; }

echo "== down"; art down --retry=60 || restore "artisan down"
echo "== copy code"
rm -rf /tmp/pbp-kit && mkdir -p /tmp/pbp-kit && tar -C /tmp/pbp-kit -xzf "$KIT" || restore "extract"
rsync -a --delete --itemize-changes \
  --exclude='/.env' --exclude='/storage/' --exclude='/vendor/' --exclude='/bootstrap/cache/' \
  --exclude='/README.md' --exclude='/.gitignore' --exclude='/.env.example' \
  /tmp/pbp-kit/pbp/ "$APP/" > /tmp/pbp-rsync.log || restore "rsync"
echo "rsync changes: $(grep -c . /tmp/pbp-rsync.log) (deleted: $(grep -c '^\*deleting' /tmp/pbp-rsync.log))"
grep '^\*deleting' /tmp/pbp-rsync.log || true
chown -R root:pbp "$APP"
find "$APP" \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/vendor" \) -prune -o -type d -exec chmod 755 {} +
find "$APP" \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/vendor" \) -prune -o -type f -exec chmod 644 {} +
chown -R pbp:pbp "$APP/storage" "$APP/bootstrap/cache"; chown root:pbp "$APP/.env"; chmod 640 "$APP/.env"; chmod 700 "$APP/deploy"

echo "== .env"
grep -q '^PBP_MODULES=' "$APP/.env" && restore "unexpected PBP_MODULES line (expected none)"
printf '\n# Compliance hub, private review only (Nov 18, 2026)\nPBP_MODULES=production,receiving,checklists,batchlog,labels,schedule,compliance,logs\nPBP_PREVIEW_LINKS=true\nPBP_LOGS_PREVIEW_ONLY=true\n' >> "$APP/.env"
grep -nE '^(PBP_MODULES|PBP_MODULES_OFF|PBP_PREVIEW_LINKS|PBP_LOGS_PREVIEW_ONLY|APP_ENV|APP_URL)=' "$APP/.env"
tail -5 "$APP/.env" | cut -c1-80

echo "== migrate"; art config:clear || restore "config:clear"
art migrate --force || restore "migrate"
DEFS=$(mariadb -N pbp_db -e "select count(*) from log_definitions")
echo "log definitions: $DEFS"; [ "$DEFS" = 48 ] || restore "log definitions = $DEFS (want 48)"
echo "== caches"; art config:cache && art route:cache && art view:cache || restore "caches"
systemctl reload php8.4-fpm || restore "fpm reload"
art up || restore "up"
echo "== release check"
art pbp:release-check --module=production --module=receiving --module=checklists --module=batchlog --module=labels \
  --module=schedule --module=compliance --module=logs \
  --signed-in=/dashboard --signed-in=/compliance/weekly --signed-in=/batch-log --signed-in=/labels --signed-in=/schedule \
  --column=log_entries.entered_by_user_id --column=logs_preview_tokens.token_hash --absent-route=preview.guest || restore "release check"
sleep 1
UP=$(curl -sk -o /dev/null -w '%{http_code}' --resolve powerbrandsproductions.com:443:127.0.0.1 https://powerbrandsproductions.com/up)
echo "/up -> $UP"; [ "$UP" = 200 ] || restore "/up check"
echo "DEPLOY OK"
