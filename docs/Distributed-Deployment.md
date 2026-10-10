# Devops-Glue Distributed Deployment Guide (v2.8.9-distributed)

> This is the **dedicated document** for the `release/2.8.9-distributed` branch (tag `v2.8.9-distributed`). It fully describes every difference between this variant and the standard line (`release/2.8.9`). All other CN/EN docs on this branch are identical to the standard-line v2.8.9 versions — use them as-is.

---

## 1. Variant Positioning

- The distributed line was **re-cut from the mainline** at the v2.8.9 point (instead of merging in place), inheriting every mainline v2.8.6 → v2.8.9 change: docs/API alignment, the public `/healthz` probe, container hardening, centralized `PasswordHasher`, legacy-token gating, front-end DOM-XSS hardening, workflow minimum permissions, etc.
- The variant differs from mainline **only in deployment topology**: horizontally scalable web replicas plus a single-instance timer worker.
- The old distributed tag chain is preserved on the archived `release/2.8.9-distributed-keep` branch (local only).
- File-level differences from the standard line:

| File | Difference |
|---|---|
| `config/docker/supervisord.conf` | Keeps only nginx + php-fpm (the 4 timer programs removed) |
| `config/docker/supervisord-worker.conf` | **New**: supervisor config for the worker role |
| `docker-compose.yml` | Adds `x-web-common` anchor, `devops-glue-worker` service, web replica template, `front-nginx` LB |
| `config/docker/nginx-lb.conf` | **New**: front load-balancer nginx config |
| `config/k8s/devops-glue.yaml` | **New**: Kubernetes reference manifest |
| `src/Service/Database.php` | **Adds** `tryAcquireLock()` / `releaseLock()` distributed lease lock |
| `cli/cleanup-pipeline-tags.php` | Work phase wrapped in acquire/release lease lock |
| `cli/backfill-pipeline-tags.php` | Same |
| `cli/cleanup-api-access-logs.php` | Same |
| `docs/ARCHITECTURE.md` / `docs/架构全景图.md` | Distributed scale-out chapter added |
| `docs/分布式部署说明.md` / `docs/Distributed-Deployment.md` | **New**: this document |

---

## 2. Single Image, Two Roles

The same `devops-glue` image plays two roles depending on the mounted supervisor config:

| Role | supervisor config | Processes | Ports | Replicas |
|---|---|---|---|---|
| **Web** | `config/docker/supervisord.conf` | nginx + php-fpm | 80 (exposed via LB) | 1..N (stateless, horizontally scalable) |
| **Worker** | `config/docker/supervisord-worker.conf` | 4 sleep-loop CLIs | none | **1** (single instance) |

The worker reuses the web image; only `supervisord-worker.conf` is bind-mounted read-only onto the same supervisor path (`/etc/supervisor/conf.d/supervisord.conf`) — no `ports`, no rebuild. **All CLIs must run as `www-data`**: running as root lets root create the SQLite database file first with root ownership, which then breaks php-fpm (www-data) writes.

---

## 3. Worker Timer Jobs

| program | CLI | Enable switch | Interval env (default) | Lease lock |
|---|---|---|---|---|
| `tag-cleanup` | `cli/cleanup-pipeline-tags.php` | Back-end switch `stale_tag_cleanup_enabled` ("System Settings") | `TAG_CLEANUP_INTERVAL` (3600s) | ✅ `lock:tag-cleanup` |
| `tag-backfill` | `cli/backfill-pipeline-tags.php` | Back-end switch `backfill_tag_enabled` | `TAG_BACKFILL_INTERVAL` (1800s) | ✅ `lock:tag-backfill` |
| `api-log-cleanup` | `cli/cleanup-api-access-logs.php` | DB config under "System Settings → Platform → Log Settings"; falls back to `API_ACCESS_LOG_RETAIN_DAYS` (default 90) if unset | `API_ACCESS_LOG_CLEANUP_INTERVAL` (86400s) | ✅ `lock:api-log-cleanup` |
| `db-backup` | `cli/backup-db.php` | Root .env `DB_BACKUP_ENABLED` (default off) | `DB_BACKUP_INTERVAL` (86400s) | — (no lock; single-instance worker + gate) |

- `api-log-cleanup` purges `ci_api_access_logs` + `ci_operation_logs` (shared retain-days and switch); **deploy logs belong to the CD system and are not purged on the CI side**.
- Backup output: `db-backup` reuses `DataBackupService`; with CD enabled (shared DB has `cd_*` tables) it produces two zips (`devops-glue_<driver>_<stamp>.zip` + `devops-cd_<driver>_<stamp>.zip`), each independently restorable; written to `BACKUP_DIR` (`/data/backups` in the image), rolling retention of the latest 10 per prefix.
- When a switch is off, the CLI prints "skip" and exits 0 with no side effects — safe during scale-out / migration windows.

---

## 4. Distributed Lease Lock (ci_cache Row-Level Lock)

The core of cross-instance idempotency for timer jobs: `Database::tryAcquireLock($name, $ttl=600)` / `releaseLock($name, $token)` — a lease lock built on the `cache` table using `cache_key` (`lock:<name>`) as the primary key. Works for both multi-instance MySQL and a single shared SQLite file.

**Acquisition protocol:**
1. First `DELETE` any expired lease (`expires_at <= now`, i.e. the holder died / the job overran its TTL);
2. Then a plain `INSERT` of our own lease — a unique-key conflict means a valid lock exists, return `null`, and the CLI prints "skip" and exits 0;
   - **No upsert/REPLACE**: that would delete the old row then re-insert on conflict — effectively "stealing" the lock and breaking mutual exclusion;
3. `value` stores a holder token (`host:pid:4 random bytes`); `releaseLock` deletes by `cache_key + value` precisely — if the job timed out and was taken over, the original holder cannot accidentally delete the new holder's lease;
4. TTL (600s, sized for one job round) expires automatically: a crashed holder never deadlocks, and the next schedule can take over.

**CLI-side conventions:** on acquire failure → print skip, `exit 0`; on completion (success or failure), release precisely in a `finally` block. ⚠️ Never `exit` inside the `try` block — PHP's `exit` skips `finally`, leaving the lock unreleased (only the TTL expiry can recover it).

---

## 5. docker-compose Horizontal Scale-Out

- Shared web config is extracted into a top-level `x-web-common: &web-common` YAML anchor; **each new replica needs only one `<<: *web-common` line** — no full-block copying;
- A commented `devops-glue-web-2` replica template: uncomment to get a second port-less web replica;
- The `front-nginx` load-balancer service + `config/docker/nginx-lb.conf`: round-robin `upstream glue_backend`, WebSocket/SSE upgrade passthrough (`Upgrade`/`Connection` map), `X-Forwarded-For` / `X-Forwarded-Proto` headers, `proxy_buffering off` (SSE friendly).

**Scale-out steps** (matching comments live in the compose file):
1. Comment out the host port mapping of `devops-glue` (web listens on container port 80 only; everything goes through the LB entry);
2. Uncomment the `devops-glue-web-2` replica template;
3. Uncomment the `front-nginx` service block and the matching `server devops-glue-web-2:80;` line in the `nginx-lb.conf` upstream.

**Key points:**
- The app uses no PHP sessions — web replicas need **no sticky sessions**;
- The LB rewrites the client IP: you must set `TRUSTED_PROXY_HOPS=1` in the root .env so real client IPs reach the login-failure rate limiter and audit logs;
- Data volumes are already shared (see the top comment of `x-web-common`); replicas need no separate mounts.

---

## 6. Kubernetes Reference (config/k8s/devops-glue.yaml)

| Object | Description |
|---|---|
| `devops-glue` Deployment | Web role, `replicas: 3` (stateless — scale by changing replicas; no sessions, no stickiness) |
| `devops-glue-worker` Deployment | Worker role, `replicas: 1` (single-instance timers; supervisord-worker.conf injected via ConfigMap) |
| `front-nginx` | LoadBalancer entry (LB config mounted via ConfigMap) |
| `devops-glue-app-env` Secret | `app.env` injection |
| MySQL StatefulSet (optional) | In-cluster database |
| PVC (RWX) | Backup directory / shared single SQLite file |

**⚠️ Multi-replica OIDC pitfall:** you must inject a **fixed** `OIDC_RSA_PRIVATE_KEY` into the Secret (generate once with `openssl genrsa 2048` and hard-code it). Otherwise every replica auto-generates its own signing key at startup, and Jenkins / Harbor / GitLab OIDC SSO token validation fails due to mismatched signing keys.

---

## 7. Database & Backup Notes

- **SQLite**: multi-replica deployments must share the **same** database file (RWX volume / NFS); otherwise each replica holds its own copy of the data and they inevitably diverge. MySQL is recommended for production multi-replica setups.
- Cross-instance idempotency of timer jobs is guaranteed by the lease lock (see §4), doubling as a safety net together with the "single worker container" deployment constraint.
- Backup and restore flows are identical to the standard line (`cli/backup-db.php` / `bin/restore.php`) — see the Admin Manual.

---

## 8. Upgrades & Maintenance

This variant is upgraded by **re-cutting from the mainline** (not by long-running parallel merges):

```
git checkout -B release/<X>-distributed release/<X>      # re-cut from the new mainline baseline
git checkout release/<X>-distributed-keep -- <feature files>  # replay the diff files listed above
git commit && git tag <X>-distributed                    # commit and re-create the tag
```

Mainline doc upgrades are inherited automatically; only the feature files listed in the table above need manual conflict checking. This document describes the variant's features and is maintained along with the variant.

---

*Document version: v2.8.9-distributed | Last updated: 2026-10-10*

