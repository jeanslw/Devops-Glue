FROM php:8.3-apache

# 系统依赖 + PHP 扩展
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libsqlite3-dev \
    unzip \
    && docker-php-ext-install zip pdo_sqlite mbstring curl xml \
    && rm -rf /var/lib/apt/lists/*

# Apache 重写 + 允许 .htaccess
RUN a2enmod rewrite \
    && sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 工作目录
WORKDIR /var/www/html

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 先复制依赖清单（利用 Docker 层缓存）
COPY composer.json composer.lock* ./

# 安装 PHP 依赖
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 再复制源码
COPY . .

# Apache 指向 public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# 日志目录
RUN mkdir -p /data/logs/ci-platform && chmod 755 /data/logs/ci-platform

EXPOSE 80
