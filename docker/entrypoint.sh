#!/bin/sh
# Ensure the data directory has the correct permissions
chown -R www-data:www-data /var/www/html/data

# Process default config if config.php doesn't exist
if [ ! -f /var/www/html/data/config.php ]; then
    cp /var/www/html/config-dist.php /var/www/html/data/config.php
fi

# Ensure plugins folder exists in data
if [ ! -d /var/www/html/data/plugins ]; then
    mkdir -p /var/www/html/data/plugins
fi

# Create viewcache directory if not exists
if [ ! -d /var/www/html/data/viewcache ]; then
    mkdir -p /var/www/html/data/viewcache
fi

# Start PHP-FPM
php-fpm
