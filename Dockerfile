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
# libldap2-dev 供 php-ldap 扩展编译（LDAP 登录用；不启用 LDAP 不影响其它扩展）
RUN apt-get update && apt-get install -y \
        nginx supervisor tzdata \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev  libsqlite3-dev pkg-config libldap2-dev \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite opcache zip intl gd bcmath ldap

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
COPY config/docker/entrypoint.sh /entrypoint.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && mkdir -p /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chown www-data:www-data /data /data/logs /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chmod 0755 /data /data/logs /data/logs/ci-platform /data/db /data/backups /data/cache \
    && chmod +x /entrypoint.sh

# 运行时数据统一到 /data（logs / db / backups / cache）
# ENV 是默认值；.env 里同名项（如 LOG_PATH）会覆盖，保持一致即可。

ENV LOG_PATH=/data/logs/ci-platform/ \
    DB_PATH=/data/db/data.db \
    BACKUP_DIR=/data/backups \
    GITLAB_ID_CACHE=/data/cache/gitlab_id_cache.php \
    OIDC_KEY_FILE=/data/cache/oidc_rsa.pem

EXPOSE 80

CMD ["/entrypoint.sh"]