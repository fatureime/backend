#!/bin/bash
set -e

# Create necessary directories if they don't exist
mkdir -p /var/www/html/var/cache/prod
mkdir -p /var/www/html/var/cache/dev
mkdir -p /var/www/html/var/cache/test
mkdir -p /var/www/html/var/log

# Set proper permissions
chown -R www-data:www-data /var/www/html/var
chmod -R 777 /var/www/html/var

# Execute the original command
exec "$@"
