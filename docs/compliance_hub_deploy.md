# Deploying the Compliance hub (`compliance-hub` branch) to production

Draft, Nov 18, 2026. Nothing here has been run. Run as root on the server (136.112.197.51), in the
same way the manual deploy kits do: **back up, copy, migrate, cache, check, roll back on failure**.

> **Do not use `deploy.sh` or `rollback.sh` for this.** `deploy.sh` is first-time setup only (it stops
> if `/var/www/powerbrandsproductions` already exists). `rollback.sh` **deletes the whole site and
> database** (after one dump). This is an upgrade; use the steps below.

Paths are taken from `deploy.sh`: app `/var/www/powerbrandsproductions`, system user `pbp`,
PHP `/usr/bin/php8.4`, database `pbp_db`, php-fpm `php8.4-fpm`, nginx site `powerbrandsproductions`.

## What changes

- New tables, all added and nothing changed: `log_definitions`, `log_entries` and `log_entry_links`
  (with no-delete triggers), plus `logs_preview_tokens`.
- A new tab, **Compliance** (`/logs`), shown only when `PBP_MODULES` lists `logs`. The existing tab
  is relabeled "Compliance checklists", and `/soon/logs` redirects to the hub.
- `composer.json` and `composer.lock` are unchanged, so the server's current `vendor/` stays.
- The migrations and triggers were tested on SQLite only, **not on MariaDB**. Step 4 is the
  first MariaDB run, which is why step 1 takes a backup.

## 0. Build the kit (on the dev machine, from the local branch)

```bash
cd /workspace/pbp
git archive --format=tar.gz --prefix=pbp/ -o /tmp/pbp-compliance-hub.tar.gz compliance-hub
scp /tmp/pbp-compliance-hub.tar.gz andy@136.112.197.51:/tmp/
```

## 1. Back up (files and database)

```bash
sudo -i
APP=/var/www/powerbrandsproductions; TS=$(date +%Y%m%d-%H%M%S); BK=/root/pbp-backup-$TS
mkdir -p "$BK"
mariadb-dump --single-transaction --routines --triggers pbp_db | gzip > "$BK/pbp_db.sql.gz"
tar -C /var/www -czf "$BK/app.tar.gz" powerbrandsproductions
cp -a "$APP/.env" "$BK/.env"
ls -la "$BK"      # all three files must be there and must not be empty before you go on
```

## 2. Maintenance mode, then copy the code

```bash
cd "$APP" && runuser -u pbp -- /usr/bin/php8.4 artisan down --retry=60
mkdir -p /tmp/pbp-kit && tar -C /tmp/pbp-kit -xzf /tmp/pbp-compliance-hub.tar.gz
rsync -a --delete \
  --exclude='.env' --exclude='storage/' --exclude='vendor/' --exclude='bootstrap/cache/' \
  /tmp/pbp-kit/pbp/ "$APP/"
chown -R root:pbp "$APP"; find "$APP" -path "$APP/storage" -prune -o -path "$APP/bootstrap/cache" -prune -o -type d -exec chmod 755 {} +
find "$APP" -path "$APP/storage" -prune -o -path "$APP/bootstrap/cache" -prune -o -path "$APP/vendor" -prune -o -type f -exec chmod 644 {} +
chown -R pbp:pbp "$APP/storage" "$APP/bootstrap/cache"; chmod 640 "$APP/.env"; chmod 700 "$APP/deploy"
```

## 3. `.env`: switch on the tab, and the preview links only while the review is running

**Private review first:** also set `PBP_LOGS_PREVIEW_ONLY=true`. The hub then exists only for preview-link
sessions; everyone else keeps the old sidebar (Logs "Soon"), and `/logs` is a 404 for them, even signed out.
If the live `.env` has **no** `PBP_MODULES` line, add the full default list plus `logs`:
`PBP_MODULES=production,receiving,checklists,batchlog,labels,schedule,compliance,logs`.

Add `logs` to the existing `PBP_MODULES` line (keep everything that is already on it), and add the flag:

```bash
grep -n '^PBP_MODULES=' "$APP/.env"                       # see the current list
sed -i -E '/^PBP_MODULES=/{/(=|,)logs(,|$)/!s/$/,logs/}' "$APP/.env"
grep -q '^PBP_PREVIEW_LINKS=' "$APP/.env" && sed -i 's/^PBP_PREVIEW_LINKS=.*/PBP_PREVIEW_LINKS=true/' "$APP/.env" \
  || echo 'PBP_PREVIEW_LINKS=true' >> "$APP/.env"
grep -q '^PBP_LOGS_PREVIEW_ONLY=' "$APP/.env" || echo 'PBP_LOGS_PREVIEW_ONLY=true' >> "$APP/.env"
grep -nE '^(PBP_MODULES|PBP_MODULES_OFF|PBP_PREVIEW_LINKS)=' "$APP/.env"   # logs must not be in PBP_MODULES_OFF
```

## 4. Migrate, rebuild the caches, reload, check

```bash
cd "$APP"
runuser -u pbp -- /usr/bin/php8.4 artisan config:clear      # the OLD cached config has no pbp_logs; clear it first
runuser -u pbp -- /usr/bin/php8.4 artisan migrate --force
runuser -u pbp -- /usr/bin/php8.4 artisan tinker --execute 'echo App\Models\LogDefinition::count();'  # want 48 (needs a writable HOME for psysh; or check with mariadb)
runuser -u pbp -- /usr/bin/php8.4 artisan config:cache
runuser -u pbp -- /usr/bin/php8.4 artisan route:cache
runuser -u pbp -- /usr/bin/php8.4 artisan view:cache
systemctl reload php8.4-fpm
runuser -u pbp -- /usr/bin/php8.4 artisan up      # BEFORE the release check: in maintenance mode every page is a 503
# (in preview-only mode /logs is a 404 for this check's admin, so it is not in --signed-in)
runuser -u pbp -- /usr/bin/php8.4 artisan pbp:release-check --module=logs --module=compliance \
  --signed-in=/compliance/weekly --signed-in=/dashboard \
  --column=log_entries.entered_by_user_id --column=logs_preview_tokens.token_hash --absent-route=preview.guest
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: powerbrandsproductions.com' http://127.0.0.1/up      # want 200
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: powerbrandsproductions.com' http://127.0.0.1/login   # want 200
curl -sk -o /dev/null -w '%{http_code}\n' --resolve staticpowered.com:443:127.0.0.1 https://staticpowered.com/up  # want 200
```

If any step fails, go to **Rollback** below.

## 5. Generate the one-time review link

```bash
cd /var/www/powerbrandsproductions
runuser -u pbp -- /usr/bin/php8.4 artisan logs:preview-link andy@EXAMPLE --hours=24
# prints: https://powerbrandsproductions.com/logs/preview/<64 hex characters>
```

- Use an existing, active user's email or id. Use a supervisor or admin account to see the managers-only
  Quality group; a viewer can look but cannot save entries.
- **The URL works once.** Opening it shows a **Continue** button, so a chat or email link preview
  can't use it up. Clicking Continue signs in as that user, for `/logs` and logout only, until the
  link's window ends (at most 24 hours). Every other page returns 403.
- Each issued link and each login through a link is written to the audit trail
  (`logs_preview_link_issued`, and `login` with `via = compliance hub preview link`).
- **Entries saved during the review are real production records.** Log entries can't be deleted,
  only voided, so void any test entries afterwards (as a manager, open the entry and use "Void this entry").
- Send the URL privately. It is the login. The plain token is not stored, so if the link is lost, generate a new one.

## Releasing the hub to everyone (later)

Set `PBP_LOGS_PREVIEW_ONLY=false`, then `config:cache` and reload php8.4-fpm. The sidebar then shows Compliance
(and "Compliance checklists"), and `/soon/logs` redirects to the hub.

## Turning the preview links off (do this when the review is done)

```bash
cd /var/www/powerbrandsproductions
runuser -u pbp -- /usr/bin/php8.4 artisan logs:preview-link --revoke      # ends every link and preview session now
sed -i 's/^PBP_PREVIEW_LINKS=.*/PBP_PREVIEW_LINKS=false/' .env
runuser -u pbp -- /usr/bin/php8.4 artisan config:cache && systemctl reload php8.4-fpm
```

With the flag off, every `/logs/preview/...` URL returns 404, and any open preview session is
signed out on its next request. Normal logins are not affected either way.

To hide the whole Compliance tab while keeping the data, set `PBP_MODULES_OFF=logs` (or remove `logs`
from `PBP_MODULES`), then run `config:cache` and reload php8.4-fpm.

## Rollback (restore the backup from step 1)

```bash
sudo -i; APP=/var/www/powerbrandsproductions; BK=/root/pbp-backup-<TS>
cd "$APP" && runuser -u pbp -- /usr/bin/php8.4 artisan down || true
gunzip -c "$BK/pbp_db.sql.gz" | mariadb pbp_db           # puts the old tables back exactly as they were
# The dump does not know the NEW tables, so restoring it leaves them behind (and the next migrate would fail):
mariadb pbp_db -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS log_entry_links, log_entries, log_definitions, logs_preview_tokens; SET FOREIGN_KEY_CHECKS=1;"
rm -rf "$APP" && tar -C /var/www -xzf "$BK/app.tar.gz"   # puts the code, .env, storage and caches back
cd "$APP" && runuser -u pbp -- /usr/bin/php8.4 artisan up
systemctl reload php8.4-fpm
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: powerbrandsproductions.com' http://127.0.0.1/up   # want 200
```

Restoring the dump also removes the new tables, and any entries made since the deploy. Use the
module switch above instead if the data should stay. (`php artisan migrate:rollback` is not
recommended here: the log tables have no-delete triggers and hold compliance records.)
