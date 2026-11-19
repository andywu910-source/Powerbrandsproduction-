# PowerBrands Productions (powerbrandsproductions.com)

Internal production and compliance app for PowerBrands NY, built with Laravel 12, PHP 8.4 and MariaDB.
Plain CSS/JS in `public/` (no npm build).

Modules: Production (batch workflow, scale register), Receiving, Checklists and Batch Log,
Labels, Weekly Schedule, and Compliance checklists. Roles: admin, supervisor, operator, viewer.

## Local setup
```
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan pbp:create-admin
```
Settings are documented in `config/pbp.php` and `.env.example`. Never commit `.env`.

## Deploys
**Deploys still happen the current way: manual deploy kits** run on the server by Andy's developer
bot. Each kit checks the live files first, backs everything up (files plus a database dump) and
rolls back automatically if a health check fails. `deploy.sh`, `rollback.sh` and `deploy/` are the
first-time server setup scripts. There is no CI/CD and no deploy hook; pushing to this repo does
not change the live site.

## Working with branches
Do your work on a branch and open a pull request into `main`. Changes only go live after they are
packaged into a deploy kit and deployed manually.

Snapshot: this first commit matches the live site as of release `m4-compliance-v2`.

## Weekly Bookkeeping

Admin-only page at `/bookkeeping` (sidebar: Bookkeeping). It reuses the existing login:
email and password, bcrypt hashes, database sessions, secure/HttpOnly/SameSite cookies, CSRF on
every form, and the `admin` role. There is no separate bookkeeping account.

Create the first admin the same way as the rest of the site (password is never hardcoded):

```
php artisan pbp:create-admin
```

That reads `PBP_ADMIN_INITIAL_PASSWORD_HASH` (a bcrypt hash) or, if you pass
`--password-file=/path/outside/the/repo`, writes a random password to that file (mode 600) and
forces a password change on first login. Other admins are added from Setup → Users.

The page is its own module. It stays **off** until the server `.env` lists it, and it is not
forced on by `modules_release_on`:

```
PBP_MODULES=production,receiving,checklists,batchlog,labels,schedule,compliance,bookkeeping
```

Remove `bookkeeping` from that list, or set `PBP_MODULES_OFF=bookkeeping`, to turn it off.
A non-admin who opens the URL is rejected with 403. A logged-out visitor is redirected to
`/login`. When the module is off, an admin gets 404.

Put these in the server `.env` only (see `.env.example`). Do not commit them:

| Variable | Purpose |
| --- | --- |
| `DISTRU_API_KEY` | Distru public API key. Read on the server. Brand search shows a setup notice until this is set. |
| `BOOKKEEPING_SIGNATURE_HTML` | Optional HTML signature. An admin can override it on the page. `[signature]` in the template is replaced with it. |
| `BOOKKEEPING_MAIL_TRANSPORT` | `smtp` or `gmail`. Leave empty to keep sending disabled. |
| `BOOKKEEPING_SMTP_HOST`, `BOOKKEEPING_SMTP_PORT`, `BOOKKEEPING_SMTP_USERNAME`, `BOOKKEEPING_SMTP_PASSWORD`, `BOOKKEEPING_SMTP_ENCRYPTION` | SMTP (Gmail app passwords work here: host `smtp.gmail.com`, port 587, encryption `tls`). |
| `BOOKKEEPING_GMAIL_CLIENT_ID`, `BOOKKEEPING_GMAIL_CLIENT_SECRET`, `BOOKKEEPING_GMAIL_REFRESH_TOKEN` | Gmail API, used when transport is `gmail`. |
| `BOOKKEEPING_MAIL_FROM_ADDRESS` | Defaults to `billing@powerbrandsny.com`. |

Bank-statement uploads are stored on the private disk (`storage/app/private/bookkeeping/`, outside
the web root) and are downloaded only through an admin route. They are not committed and not
served from `public/`. Invoice PDFs are fetched from Distru at send time and are not stored.
Nothing is emailed unless an admin checks the confirmation box, mail credentials are set, and
the Distru key is set. Tests refuse to open a mail connection.

### Deployment

The live site is **not** deployed from GitHub. It runs on the Debian server at
`/var/www/powerbrandsproductions` (nginx → `public/`, PHP 8.4 FPM pool `pbp`, MariaDB).
`deploy.sh` is first-time setup only. Later releases are manual deploy kits: the operator's
bot checks the live files, backs up files and the database, and rolls back if a health check
fails. There is no CI deploy hook.

Merging a pull request into `main` does **not** make it live. Production is currently an
unpushed local branch, so this change has to be hand-merged, then shipped in a deploy kit.
On that server, run `php artisan migrate --force` (the kit does this) and add `bookkeeping`
to `PBP_MODULES` plus the variables above in `/var/www/powerbrandsproductions/.env`.
`config:cache` is part of deploy, so edit `.env` before caching config.

Hand-merge touch list in shared files: `routes/web.php` (one `require`), `app/Support/Modules.php`
(`bookkeeping` added to the module allow-list), `resources/views/partials/sidebar.blade.php`
(admin link), `.env.example`, and this README. Everything else is new files under
`routes/bookkeeping.php`, `app/Http/Controllers/BookkeepingController.php`,
`app/Services/Bookkeeping/`, `app/Models/Bookkeeping*`, `resources/views/bookkeeping/`,
`public/css/bookkeeping.css`, `public/js/bookkeeping.js`, `config/bookkeeping.php`, and
`database/migrations/2026_11_19_000000_create_bookkeeping_tables.php`.
