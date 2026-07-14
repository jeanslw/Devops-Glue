# ========== 阶段1: 安装 Composer 依赖 ==========
# FROM 和 AS vendor
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

# ========== 阶段2: 生成镜像 ==========
FROM php:8.3-fpm-bookworm AS production

# 安装系统依赖 + Nginx + Supervisor
RUN apt-get update && apt-get install -y \
        nginx supervisor \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql opcache zip intl gd bcmath

# 配置 PHP-FPM
RUN sed -i 's|^listen = .*|listen = /run/php/php-fpm.sock|' /usr/local/etc/php-fpm.d/www.conf \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p /run/php

# 复制应用代码
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .

# 构建时放入默认配置
COPY config/docker/nginx.conf /etc/nginx/sites-available/default
COPY config/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY config/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]