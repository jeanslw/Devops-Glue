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

RUN apt-get update && apt-get install -y \
        supervisor tzdata \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev  libsqlite3-dev pkg-config libldap2-dev \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite opcache zip intl gd bcmath ldap

RUN rm -f /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Stores default configurations
COPY config/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY config/docker/supervisord-worker.conf /etc/supervisor/conf.d/supervisord.conf
COPY config/docker/entrypoint.sh /entrypoint.sh

RUN mkdir -p /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chown www-data:www-data /data /data/logs /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chmod 0755 /data /data/logs /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chmod +x /entrypoint.sh
	
ENV LOG_PATH=/data/logs/ci-platform/ \
    DB_PATH=/data/db/data.db \
    BACKUP_DIR=/data/backups \
    GITLAB_ID_CACHE=/data/cache/gitlab_id_cache.php \
    OIDC_KEY_FILE=/data/cache/oidc_rsa.pem

EXPOSE 9000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm", "-F"]
