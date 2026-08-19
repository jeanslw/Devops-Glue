# ========== Step 1: Install Composer dependencies ==========
# FROM and AS vendor
FROM php:8.3-fpm-bookworm AS vendor

WORKDIR /app

RUN apt-get update && apt-get install -y \
        unzip \
        libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
	
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && rm /usr/local/bin/composer

# ========== Step 2: Generate the image ==========
FROM php:8.3-fpm-bookworm AS production

# Install system dependencies. 
RUN apt-get update && apt-get install -y \
        nginx supervisor \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev  libsqlite3-dev pkg-config \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite opcache zip intl gd bcmath

# Configure PHP-FPM
RUN sed -i 's|^listen = .*|listen = /run/php/php-fpm.sock|' /usr/local/etc/php-fpm.d/www.conf \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p /run/php

# Copy application code
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Stores default configurations
COPY config/docker/nginx.conf /etc/nginx/sites-available/default
COPY config/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY config/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && mkdir -p /app/config/data /data/logs/ci-platform \
    && chmod -R 777 /app/config/data /data/logs/ci-platform \
    && echo '#!/bin/bash' > /entrypoint.sh \
    && echo 'chmod -R 777 /app/config/data /data/logs/ci-platform 2>/dev/null || true' >> /entrypoint.sh \
    && echo 'exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf' >> /entrypoint.sh \
    && chmod +x /entrypoint.sh

EXPOSE 80

CMD ["/entrypoint.sh"]