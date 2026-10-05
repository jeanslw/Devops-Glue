# Devops-Glue Architecture Overview

## Overall Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│                        CODE PUSH                            │
│  GitLab / Gitee / GitHub / Gitea  →  Webhook Trigger        │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                  CI LAYER：Devops-Glue API (PHP)            │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐   ┌──────────────┐      │  ┌───────────────┐
│  │   Jenkins    │  │  GitLab CI   │   │   Gitea CI   │      │  │  Custom Push  │ ← User CI
│  │ BuildProvider│  │ BuildProvider│   │ BuildProvider│      │  │ (custom_push) │  (pusher)
│  └──────┬───────┘  └──────┬───────┘   └──────┬───────┘      │  └───────┬───────┘
│         └─────────────────┼──────────────────┼──────────────<──────────┼          
│                           ↓                                 │
│              Build → Docker Image → Harbor Registry         │
│                           ↓                                 │
│              scan-sync → ci_pipeline_artifacts              │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                  CD LAYER：cd_service (Python)              │
│                                                             │
│   Select Project + Tag  ──→  Deploy Execution               │
│                                                             │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│   │ SSH Script   │  │Docker Compose│  │  Kubernetes  │      │
│   │  Ansible     │  │  SFTP + up   │  │ kubectl/Helm │      │
│   │              │  │              │  │ ArgoCD/FluxCD│      │
│   └──────────────┘  └──────────────┘  └──────────────┘      │
│                           ↓                                 │
│              cd_deploy_logs (Deployment Records)            │
│                           ↓                                 │
│            DingTalk / WeCom Webhook Notifications           │
└─────────────────────────────────────────────────────────────┘
```

## Component Relationships
```
┌────────────────────────────────────────────────────────────┐
│Shared Database (SQLite/MySQL/MariaDB)                      │
│                                                            │
│  ci_job_git_map   ← CI read-only                           │
│  ci_pipeline_artifacts ← canonical CI artifact facts       │
│  ci_custom_builds ← Custom_Push write                      │
│  cd_servers       ← CD maintains                           │
│  cd_deploy_logs   ← CD writes                              │
│  cd_bots          ← CD maintains                           │
│  admin_users      ← Shared                                 │
└────────────────────────────┬───────────────────────────────┘
			                 │
		              ┌──────┴──────┐
		              ↓             ↓
				┌────────┐      ┌────────┐
				│ PHP CI │      │PythonCD│
				│:8080   │      │:8081   │
				└────────┘      └────────┘
```

> **Database Selection**: PHP CI and CD Service must use the same database instance.
> - **SQLite**: Zero-configuration, suitable for single-host development/testing. For container deployments, mount the `config/data/` directory as a shared volume so both processes can access the same `.db` file.
> - **MySQL 8.0+ / MariaDB 10.4+**: Recommended for production. Supports concurrent reads and writes, no shared volume required.

## Deployment Topology (Distributed)

The CI web layer is **stateless** — token auth (no `$_SESSION`), all state in the shared DB — so it can scale out horizontally. The only single-instance part is the scheduled timer jobs (`tag-cleanup` / `tag-backfill`), moved into a dedicated worker container.

### Single image, two roles

The `devops-glue:latest` image carries everything (nginx + php-fpm + supervisor + app code + CLI scripts). The same image runs as two roles, distinguished only by which supervisor config is bind-mounted onto `/etc/supervisor/conf.d/supervisord.conf`:

| Service | Mounted supervisor config | Programs it runs | Port |
|---|---|---|---|
| `devops-glue` (web) | `supervisord.conf` | nginx + php-fpm | 8080 |
| `devops-glue-worker` (worker) | `supervisord-worker.conf` | tag-cleanup + tag-backfill | — |

Both run the same entrypoint (`db-init` retry → `exec supervisord`) and share the same DB (`data/db` for SQLite, or the MySQL instance), each under its own supervisor process — so scaling the web layer never duplicates the timers.

### Distributed timer lock

To guarantee at-most-one timer run even if several worker replicas are started, both CLI scripts take a **lease lock in `cache`** (`Database::tryAcquireLock` / `releaseLock`) before doing work:

- Key is `cache_key = 'lock:tag-cleanup'` / `'lock:tag-backfill'`, shared across instances (MySQL multi-instance, or a shared SQLite file).
- Acquisition deletes expired leases, then `INSERT`s its own — a PK conflict means another instance holds a valid lease → exit 0 ("skip").
- The lease auto-expires after its TTL (600s), so a crashed holder can't deadlock the schedule; release is token-scoped so a timeout-takeover can't delete the new holder's lock.

```
                 devops-glue × N  (nginx + php-fpm, stateless)   ← scale out
                          │
                          ▼   shared DB (MySQL / SQLite)
        devops-glue-worker × 1  (tag-cleanup + tag-backfill, DB lock)
```

> **SQLite**: still a single file — mount one shared `data/db` volume; the lock serializes via the same file's WAL busy-timeout. For true multi-instance concurrency, use MySQL.

### Scaling out the web layer (docker-compose + K8s reference)

The compose file shares the web config through a YAML anchor so replicas don't duplicate it:

- **`x-web-common: &web-common`** holds everything common to a web replica (image, `env_file`, `TZ`, all volume mounts, restart policy, healthcheck, `depends_on: mysql`). A new replica is one service block plus a single `<<: *web-common` merge line — the commented `devops-glue-web-2` is a ready-made template.
- **`front-nginx`** (commented by default) is the optional front load balancer — `config/docker/nginx-lb.conf` defines a round-robin `upstream` over `devops-glue:80` (add one `server` line per replica), passes `X-Forwarded-*` / `Upgrade` headers, and sets `proxy_buffering off`. Single-instance deployments skip it: `devops-glue` maps `8080:80` directly.
- **No sticky session needed**: auth is token-based (no `$_SESSION`), so any replica can serve any request. Behind the LB, set `TRUSTED_PROXY_HOPS=1` — otherwise the app sees the LB's IP instead of the client (login lockout / audit logs).

A **Kubernetes reference manifest** (`config/k8s/devops-glue.yaml`) mirrors this topology — a web `Deployment` (`replicas: 3`), a single worker, a `front-nginx` `LoadBalancer` entry, an `app.env` `Secret`, plus optional in-cluster MySQL (`StatefulSet`) and a RWX PVC for backups/SQLite. In K8s the OIDC signing key must be injected as a fixed `OIDC_RSA_PRIVATE_KEY` (a multi-line Secret), otherwise each replica auto-generates a different key and SSO breaks.

### Bare-metal Deployment

Without Docker/supervisord, deployment has two parts:

- **Web layer**: regular nginx + php-fpm (stateless, horizontally scalable). Point `nginx` `root` at the project's `public/` (the single entry point `index.php`), `try_files`-rewrite to `index.php` for Slim routing, and fastcgi-forward PHP requests to php-fpm. The repo's `config/docker/nginx.conf` is a ready-made template — just replace the container path `/app` with your deploy path.
- **Timer jobs**: in containers the worker's supervisord sleep-loop drives them; on bare metal, schedule the two CLIs from system cron (or a systemd timer):

```cron
# hourly: clean up stale tags (matches TAG_CLEANUP_INTERVAL, default 3600s)
0 * * * * www-data php /opt/devops-glue/cli/cleanup-pipeline-tags.php >> /var/log/devops-glue/tag-cleanup.log 2>&1
# every 30 min: backfill log-derived tags (matches TAG_BACKFILL_INTERVAL, default 1800s)
*/30 * * * * www-data php /opt/devops-glue/cli/backfill-pipeline-tags.php >> /var/log/devops-glue/tag-backfill.log 2>&1
```

Notes:

- **Switches default off**: both jobs are gated by `stale_tag_cleanup_enabled` / `backfill_tag_enabled` (default off); enable them on the platform-config page first, otherwise the CLI prints "skip" and exits 0.
- **Run user**: use the same user as the web process (php-fpm, e.g. `www-data`); otherwise root running first creates a root-owned SQLite file and the web side fails to write.
- **env loading**: the CLI auto-loads `config/app.env` via `cli/bootstrap.php` (paths relative to `__DIR__`, no cwd dependence), so no `cd` is needed in crontab.
- **Distributed lock safety**: the CLI has a built-in `cache`-table lease lock, so even misconfigured duplicate crons (or mixing with a container worker) run at most one instance at a time; losing the lock just exits 0.
- **Log dir**: the redirected log directory must be writable by the run user.

## CI Build Modes: Pull-based vs Push-based

Devops-Glue supports two orthogonal CI modes that can be enabled simultaneously:

### Pull-based CI (Traditional)

- **Switch**: `build_mode` — enabled CI source set (`jenkins` / `gitlab_ci` / `gitea_ci`, multi-select)
- **Direction**: Devops-Glue actively calls CI APIs to trigger builds, poll status, and fetch image tags
- **Providers**: `JenkinsBuildProvider`, `GitlabCiBuildProvider`, `GiteaCiBuildProvider`
- **Flow**: Devops-Glue → CI API → Build → Harbor → scan-sync → `ci_pipeline_artifacts`.

### Push-based CI (Custom_Push)

- **Switch**: `custom_push_enabled` (independent boolean, orthogonal to `build_mode`)
- **Direction**: User's own CI scripts push build status, log URL, and image tags to Devops-Glue
- **Provider**: `CustomPushBuildProvider` (implements `BuildProviderInterface`)
- **Flow**: User CI → Devops-Glue API → `ci_custom_builds` (metadata) + `ci_pipeline_artifacts` (image tags)

### Orthogonal Design

`build_mode` and `custom_push_enabled` are independent and can be combined freely:

| build_mode (enabled CI sources) | custom_push_enabled | Effect |
|---|---|---|
| jenkins | false | Jenkins pull-based only |
| gitlab_ci | false | GitLab CI pull-based only |
| gitea_ci | false | Gitea Actions pull-based only |
| jenkins,gitlab_ci,gitea_ci | false | All three pull-based CIs |
| jenkins | true | Jenkins + Custom_Push simultaneously |
| gitlab_ci,gitea_ci | true | GitLab CI + Gitea Actions + Custom_Push simultaneously |

### Custom_Push Key Design

- **Metadata only**: Devops-Glue stores build metadata in `ci_custom_builds`; image facts are canonicalized in `ci_pipeline_artifacts`.
- **Log proxy**: `log_url` is a pointer only; Devops-Glue proxies log content and **does not store logs**. Since Devops-Glue cannot verify build authenticity, logs as evidence must be held by the executor (user CI)
- **API endpoints**:
  - `POST /api/build/{path}/report` — report terminal build result and image tag in one call
- **Config-driven**: `CustomPushBuildProvider` is registered via the `build.custom_providers` array in `settings.php`, ready out of the box
- **Auto-discovery**: When `custom_push` is enabled, auto-discovery scans Git platforms for projects configured with custom_push
- **pipeline_iid constraint**: To align with `ci_pipeline_artifacts` constraints, `pipeline_iid` must be an integer; duplicate reports overwrite (UPDATE) the existing record

## Deployment Mode Matrix

| Deployment Type | Mode | Implementation |
|-----------------|------|----------------|
| SSH (single host) | Custom Command | Shell script with `{image}` `{tag}` `{project}` placeholders |
| SSH (single host) | Ansible Playbook | `ansible-playbook -e image={image} -e tag={tag}` |
| Docker Compose | Remote YAML | `cd {path} && docker compose up -d` |
| Docker Compose | Inline YAML | SFTP upload compose YAML → auto-create dir → startup |
| K8s kubectl | SSH apply | SSH to master → `kubectl apply -f` |
| K8s Helm | SSH kubectl | `helm upgrade --install` + version verification |
| K8s Argo CD | REST API | PATCH image → sync → poll until Healthy |
| K8s Flux CD | SSH kubectl | PATCH resource → wait for ready |

## Design Patterns

- **Strategy Pattern**: `BuildProviderInterface` (PHP CI) / `Deployer` (Python CD) abstract base class + Registry
- **Factory Pattern**: `GitProviderFactory` auto-matches Git platform adapter by URL
- **Dual-driver Database**: SQLite / MySQL / MariaDB unified interface, one codebase for three modes, sharing the same database instance with CD Service
- **Orthogonal CI Design**: `build_mode` (pull-based) and `custom_push_enabled` (push-based) are independent switches that can be combined freely and enabled simultaneously

## Problem Statement

Fragmented DevOps toolchain for SMBs:

- Git platforms (GitLab/Gitee/GitHub/Gitea) → Unified integration
- CI engines (Jenkins/GitLab CI pull-based + Custom_Push push-based, theoretically supporting all CI) → Unified integration
- Image registry (Harbor) → Scan & sync
- Deploy targets (SSH/Docker/K8s) → Unified execution
- Notifications (DingTalk/WeCom) → Auto-push

**One sentence: glue scattered DevOps tools into a single layer.**