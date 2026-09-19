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
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
