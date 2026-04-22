#!/bin/bash
set -e

# Trust the workspace repo
git config --global --add safe.directory /var/www/html

cd /var/www/html

# Copy .env from example if not present
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Install PHP dependencies if vendor/ is missing
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Install Node dependencies if node_modules/ is missing
if [ ! -d node_modules ]; then
    npm install
fi

# Generate app key if not set
if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --no-interaction
fi

# Run migrations
php artisan migrate --force

# Seed admin user if password is provided (idempotent — safe to run on every start)
if [ -n "$ADMIN_USER_SEEDER_PASSWORD" ]; then
    php artisan db:seed --class=AdminUserSeeder --no-interaction
fi

exec "$@"
