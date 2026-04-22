#!/bin/bash
set -e

# Trust the workspace repo
git config --global --add safe.directory /var/www/html

# Start Laravel dev server if the app is ready
if [ -f /var/www/html/artisan ] && [ -d /var/www/html/vendor ]; then
    cd /var/www/html
    php artisan migrate --force
    php artisan serve --host=0.0.0.0 --port=8000 &
fi

exec "$@"
