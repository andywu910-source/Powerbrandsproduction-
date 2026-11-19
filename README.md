# PowerBrands Productions

Internal production & compliance app for PowerBrands NY (Laravel 12, PHP 8.4, MariaDB).

- Plain CSS/JS in `public/` (no Vite/npm build). Look copied from staticpowered.com.
- Auth: email/password sessions, roles `admin | supervisor | operator`, admin-only Users page.
  Google sign-in hooks are marked with `GOOGLE SIGN-IN (future)` comments.
- First admin: `php artisan pbp:create-admin` (uses `PBP_ADMIN_INITIAL_PASSWORD_HASH` or `--password-file`).
- Server setup: `deploy.sh`, then `certbot.sh`; undo with `rollback.sh`. See DEPLOY.md (shipped separately).
- Sections Products / Batch Logs / SOP & Compliance Logs are "Coming soon" placeholders.
