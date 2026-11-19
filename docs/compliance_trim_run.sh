#!/usr/bin/env bash
# Nov 19 release 9: retire 3-B / BAL / VIS, 1-C without checkboxes, 4-A one Location + Wrong location?, no BAL scale gate.
# 1 migration (definitions only), no .env change. Usage (root): BK=/root/pbp-backup-trim-<TS> bash compliance_trim_run.sh   (fresh backup taken first)
set -uo pipefail
APP=/var/www/powerbrandsproductions
BK=${BK:?set BK to a fresh backup dir}
KIT=/tmp/pbp-compliance-hub.tar.gz
PHP=/usr/bin/php8.4
art() { (cd "$APP" && runuser -u pbp -- "$PHP" artisan "$@"); }
pub() { curl -sk -o /dev/null -w '%{http_code}' --resolve powerbrandsproductions.com:443:127.0.0.1 "https://powerbrandsproductions.com$1"; }
restore() {
  echo "!!! FAILED at: $1 -- restoring backup $BK"
  (cd "$APP" && runuser -u pbp -- "$PHP" artisan down 2>/dev/null) || true
  gunzip -c "$BK/pbp_db.sql.gz" | mariadb pbp_db && echo "DB restored"
  rm -rf "$APP" && tar -C /var/www -xzf "$BK/app.tar.gz" && echo "code/.env/storage restored"
  (cd "$APP" && runuser -u pbp -- "$PHP" artisan up) || true
  systemctl reload php8.4-fpm
  echo "after restore /up: $(pub /up)  /login: $(pub /login)"
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
echo "rsync: $(grep -c '^>f' /tmp/pbp-rsync.log) files sent, $(grep -c '^\*deleting' /tmp/pbp-rsync.log) deleted"
grep '^\*deleting' /tmp/pbp-rsync.log || true
chown -R root:pbp "$APP"
find "$APP" \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/vendor" \) -prune -o -type d -exec chmod 755 {} +
find "$APP" \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/vendor" \) -prune -o -type f -exec chmod 644 {} +
chown -R pbp:pbp "$APP/storage" "$APP/bootstrap/cache"; chown root:pbp "$APP/.env"; chmod 640 "$APP/.env"; chmod 700 "$APP/deploy"

echo "== .env (unchanged)"; cmp "$BK/.env" "$APP/.env" || restore ".env changed unexpectedly"
echo "== migrate"; art config:clear || restore "config:clear"
art migrate --force || restore "migrate"
PEND=$(art migrate:status --pending 2>/dev/null | grep -c Pending || true); echo "pending migrations after migrate: $PEND"
echo "== caches"; art config:cache && art route:cache && art view:cache || restore "caches"
systemctl reload php8.4-fpm || restore "fpm reload"
art up || restore "up"
echo "== release check"
art pbp:release-check --schedule --module=production --module=receiving --module=checklists --module=batchlog --module=labels \
  --module=schedule --module=logs --module=bookkeeping \
  --signed-in=/dashboard --signed-in=/checklists --signed-in=/batch-log --signed-in=/labels --signed-in=/schedule --signed-in=/deviations \
  --signed-in=/logs --signed-in=/logs/2-e --signed-in=/logs/1-a --signed-in=/logs/verification --signed-in=/bookkeeping \
  --signed-in=/receiving --signed-in=/receiving/new --signed-in=/logs/1-c --signed-in=/logs/4-a --signed-in=/logs/3-c \
  --signed-in=/production --signed-in=/setup/scales \
  --column=log_entries.verification_status --column=deviations.effectiveness_check --column=bookkeeping_statements.sha256 --absent-route=preview.guest || restore "release check"
ACT=$(mariadb -N pbp_db -e "select count(*) from log_definitions where active=1"); TOT=$(mariadb -N pbp_db -e "select count(*) from log_definitions")
OFF=$(mariadb -N pbp_db -e "select group_concat(code order by code) from log_definitions where active=0")
echo "hub logs: $ACT active of $TOT; retired: $OFF"
[ "$ACT" = 42 ] && [ "$TOT" = 48 ] && [ "$OFF" = "3-B,BAL,CAPA,LBL,VIS,WST" ] || restore "definition counts"
W=$(mariadb -N pbp_db -e "select json_search(fields, 'one', 'wrong_location') is not null from log_definitions where code='4-A'"); echo "4-A has wrong_location: $W"; [ "$W" = 1 ] || restore "4-A fields"
UP=$(pub /up); LOGIN=$(pub /login); echo "/up -> $UP  /login -> $LOGIN"
[ "$UP" = 200 ] && [ "$LOGIN" = 200 ] || restore "public checks"
echo "DEPLOY OK $(TZ=America/New_York date '+%a %b %d %Y %I:%M:%S %p %Z')"
