#!/bin/bash
set -e

# Trust the workspace repo
git config --global --add safe.directory /var/www/html

cd /var/www/html

# Laravel env must be created manually before container startup
if [ ! -f .env ]; then
    echo "Missing .env. Copy .env.example to .env before starting the container."
    exit 1
fi

# Install PHP dependencies if vendor/ is missing
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Install or refresh Node dependencies when node_modules/ is missing or stale
sh scripts/ensure-node-deps.sh

# Generate app key if not set
if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --no-interaction
fi

# Run migrations
php artisan migrate --force

# Seed admin user if password is configured in Laravel env (idempotent — safe to run on every start)
if grep -Eq '^ADMIN_USER_SEEDER_PASSWORD=.+$' .env; then
    php artisan db:seed --class=AdminUserSeeder --no-interaction
fi

exec "$@"
