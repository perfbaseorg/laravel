#!/bin/bash

# Set up perfbase
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
wget -O install.sh https://cdn.perfbase.com/install.sh
chmod +x install.sh
./install.sh --dev
rm install.sh

composer update
composer install

# Start PHP-FPM in background
php-fpm &

# Start NGINX in foreground
nginx -g "daemon off;"
