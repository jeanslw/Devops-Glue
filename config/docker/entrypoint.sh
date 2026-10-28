#!/bin/bash

if [ -n "${TZ}" ] && [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
    printf 'date.timezone=%s\n' "${TZ}" > /usr/local/etc/php/conf.d/zz-timezone.ini
fi

for d in /data/db /data/cache /data/logs /data/backups /data/logs/ci-platform; do
    mkdir -p "$d"
    chown www-data:www-data "$d" 2>/dev/null || true
    chmod 0755 "$d" 2>/dev/null || true
done

chown -R www-data:www-data /data/db 2>/dev/null || true
chown www-data:www-data /data/cache /data/cache/* 2>/dev/null || true
if [ -f /data/cache/oidc_rsa.pem ]; then
    chown www-data:www-data /data/cache/oidc_rsa.pem 2>/dev/null || true
    chmod 0600 /data/cache/oidc_rsa.pem 2>/dev/null || true
fi

DB_INIT_MAX_RETRIES="${DB_INIT_MAX_RETRIES:-10}"
DB_INIT_RETRY_DELAY="${DB_INIT_RETRY_DELAY:-3}"
i=1
while [ "$i" -le "$DB_INIT_MAX_RETRIES" ]; do
    if php /app/cli/db-init.php; then
        echo "✓ 数据库初始化完成"
        break
    fi
    if [ "$i" -ge "$DB_INIT_MAX_RETRIES" ]; then
        echo "⚠️ 数据库初始化最终失败（cron 首跑会自愈重试）" >&2
        break
    fi
    echo "⚠️ 数据库初始化失败（${i}/${DB_INIT_MAX_RETRIES}），${DB_INIT_RETRY_DELAY}s 后重试" >&2
    sleep "$DB_INIT_RETRY_DELAY"
    i=$((i + 1))
done
chown -R www-data:www-data /data/db 2>/dev/null || true
exec "$@"
