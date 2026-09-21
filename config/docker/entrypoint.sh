#!/bin/bash
# TZ from compose/env_file: OS localtime + PHP date.timezone (PHP does not follow TZ by itself).
if [ -n "${TZ}" ] && [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
    printf 'date.timezone=%s\n' "${TZ}" > /usr/local/etc/php/conf.d/zz-timezone.ini
fi
# Bind mounts arrive as root. Own the runtime dirs as www-data, but never
# chmod -R files: OIDC private key must stay 0600, SQLite db/WAL keep their own mode.
for d in /data/db /data/cache /data/logs /data/backups /data/logs/ci-platform /data/logs/php_log; do
    mkdir -p "$d"
    chown www-data:www-data "$d" 2>/dev/null || true
    chmod 0755 "$d" 2>/dev/null || true
done
# SQLite may already exist from a previous run (possibly root-owned on first bind-mount).
chown -R www-data:www-data /data/db 2>/dev/null || true
chown www-data:www-data /data/cache /data/cache/* 2>/dev/null || true
if [ -f /data/cache/oidc_rsa.pem ]; then
    chown www-data:www-data /data/cache/oidc_rsa.pem 2>/dev/null || true
    chmod 0600 /data/cache/oidc_rsa.pem 2>/dev/null || true
fi
# 显式初始化数据库（建表 + 种子）：确保 supervisord 的 cron 循环启动前表已就绪，
# 避免首次安装时 cron 抢在 HTTP 首次请求前运行、撞上「表缺失」。
# 以 root 运行属正常（容器入口）；随后 chown 把新建的 SQLite 库归位 www-data，
# 与 Web 侧（php-fpm=www-data）属主一致，避免 root 污染库文件。
#
# 带重试：depends_on: service_healthy 只在 docker compose up 时生效，容器独立重启
# （docker restart / crash 后 restart: unless-stopped）不会重新等 MySQL。此处有界重试，
# 覆盖「MySQL 首次初始化尚未完成」的窗口；最终仍失败则交给 cron 首跑自愈。
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
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
