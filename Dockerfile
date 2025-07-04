ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm

ENV PATH="/composer/vendor/bin:$PATH"
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/composer

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    git \
    wget \
    unzip \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql zip mbstring bcmath \
    && apt-get clean

# Set the safe directory for git
RUN git config --global --add safe.directory /var/www/html

# Install Perfbase
RUN bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"

# Set working directory
WORKDIR /app

# Install PHP dependencies
COPY composer.json ./composer.json
RUN composer install --prefer-dist --no-progress --no-scripts

# Copy project files to container
COPY . .

# Add Composer's global bin directory to PATH
ENV PATH="/composer/vendor/bin:$PATH"

# Default Entrypoint
ENTRYPOINT []
