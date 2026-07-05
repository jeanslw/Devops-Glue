FROM php:8.3-apache

# 系统依赖
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip

# Apache 重写
RUN a2enmod rewrite

# 工作目录
WORKDIR /var/www/html

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 源码
COPY . .

# 安装 PHP 依赖
RUN composer install --no-dev --optimize-autoloader

# Apache 指向 public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# 日志目录
RUN mkdir -p /data/logs/ci-platform && chmod 777 /data/logs/ci-platform

EXPOSE 80
