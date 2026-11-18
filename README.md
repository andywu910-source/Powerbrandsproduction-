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
