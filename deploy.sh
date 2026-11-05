#!/usr/bin/env bash
# Production deploy for hmis.solforbs.com. Run on the server as root:
#   cd /var/www/hmis && bash deploy.sh
# Pulls main, installs dependencies, migrates (additive only), rebuilds
# assets and caches, then restarts the queue worker.
set -euo pipefail

APP_DIR=/var/www/hmis
cd "$APP_DIR"

export COMPOSER_ALLOW_SUPERUSER=1

echo "==> Pulling latest main"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
git fetch --quiet origin main
git reset --hard --quiet origin/main
git log --oneline -1

echo "==> Maintenance mode"
php artisan down --retry=15 || true

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Database migrations"
php artisan migrate --force

echo "==> Front-end assets"
npm ci --no-audit --no-fund --loglevel=error
npm run build
rm -rf node_modules public/hot

echo "==> Permissions and caches"
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache
sudo -u www-data php artisan optimize:clear >/dev/null
sudo -u www-data php artisan optimize
sudo -u www-data php artisan hms:sync-roles

echo "==> Restart queue worker"
php artisan queue:restart

php artisan up
echo "==> Deployed."
