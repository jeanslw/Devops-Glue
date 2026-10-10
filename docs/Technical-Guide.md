# Devops-Glue API Technical Guide v2.8.9

> This document is intended for developers, operations engineers, and troubleshooting. It covers all business logic, data flows, database table structures, and common issues.

---

## Table of Contents

1. [System Architecture Overview](#1-system-architecture-overview)
2. [Request Lifecycle](#2-request-lifecycle)
3. [Configuration System](#3-configuration-system)
4. [Database Design](#4-database-design)
5. [Core Business Logic](#5-core-business-logic)
   - [5.1 Liveness / Readiness Probe](#51-liveness--readiness-probe)
   - [5.2 Health Check](#52-health-check)
   - [5.3 Mapping Management (job_git_map)](#53-mapping-management)
   - [5.4 Dual-Channel Build System](#54-dual-channel-build-system)
   - [5.5 Scan Sync (scan-sync)](#55-scan-sync)
   - [5.6 Commit Status Writeback](#56-commit-status-writeback)
   - [5.7 Security Scan Audit](#57-security-scan-audit)
   - [5.8 Platform Version Management](#58-platform-version-management)
   - [5.9 Build Mode Switching](#59-build-mode-switching)
   - [5.10 Authentication & Authorization](#510-authentication--authorization)
   - [5.11 Custom_Push CI Mode](#511-custom_push-ci-mode)
   - [5.11.1 Orthogonal Design](#5111-orthogonal-design)
   - [5.11.2 Core Components](#5112-core-components)
   - [5.11.3 Configuration & Registration](#5113-configuration--registration)
   - [5.11.4 Report API](#5114-report-api)
   - [5.11.5 Log Proxy](#5115-log-proxy)
   - [5.11.6 Admin Panel](#5116-admin-panel)
   - [5.11.7 Permissions & Scope](#5117-permissions--scope)
6. [Key Data Flows](#6-key-data-flows)
7. [Common Troubleshooting](#7-common-troubleshooting)
8. [Appendix: Complete Configuration Reference](#8-appendix-complete-configuration-reference)

---

## 1. System Architecture Overview

```
┌────────────────────────────────────────────────────────────────────┐
│                    HTTP Request                                    │
└─────────────────────────────┬──────────────────────────────────────┘
                              ▼
┌────────────────────────────────────────────────────────────────────┐
│  public/index.php (Entry Point)                                    │
│  ├─ EnvFileLoader 3-layer load (app.env → app.env.{ENV} → app.env.local) │
│  ├─ Static file serving                                            │
│  ├─ Database::init() auto-create tables + seed data                │
│  └─ Slim 4 App + DI container assembly                             │
└─────────────────────────────┬──────────────────────────────────────┘
                              ▼
┌────────────────────────────────────────────────────────────────────┐
│  Middleware Layer                                                  │
│  ├─ CorsMiddleware (CORS)                                          │
│  ├─ RoutingMiddleware (Route matching)                             │
│  └─ ErrorMiddleware (Unified error handling)                       │
└─────────────────────────────┬──────────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Controller Layer                                                   │
│  ├─ MainController   — Health check / map list / platform discovery │
│  ├─ BuildController  — Build trigger / Pipeline / Scan / CS         │
│  ├─ AdminController  — Admin CRUD / Auth / mode switch              │
│  ├─ GitController    — Branch query                                 │
│  └─ HarborController — Registry query / Scan                        │
└─────────────────────────────┬───────────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Service Layer                                                      │
│  ├─ Database          — SQLite/MySQL dual driver + migration        │
│  ├─ MappingManager    — Mapping query / unified filtering           │
│  ├─ AppConfig         — Config access facade                        │
│  ├─ I18nService       — i18n (Symfony Translation)                  │
│  ├─ HarborService     — Harbor API wrapper (v1/v2 detection)        │
│  ├─ JenkinsService    — Jenkins API wrapper                         │
│  ├─ GitlabCiBuildProvider — GitLab CI Build adapter                 │
│  ├─ JenkinsBuildProvider  — Jenkins Build adapter                   │
│  ├─ CustomPushBuildProvider — Custom Push Build adapter             │
│  ├─ BuildProviderRegistry — Build Provider registry                 │
│  ├─ Git/ProviderRegistry   — Git Provider registry                  │
│  ├─ Git/GitlabService      — GitLab API adapter                     │
│  ├─ Git/GithubService      — GitHub API adapter                     │
│  ├─ Git/GiteeService       — Gitee API adapter                      │
│  └─ Git/GiteaService       — Gitea API adapter                      │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│  External Systems                                                   │
│  ├─ Jenkins      (Build engine)                                     │
│  ├─ GitLab CI    (Build engine)                                     │
│  ├─ GitLab/GitHub/Gitee/Gitea (Source + Commit Status)              │
│  └─ Harbor       (Registry + Vulnerability scan)                    │
└─────────────────────────────────────────────────────────────────────┘
```

**Framework:** Slim 4 + PHP-DI 7 + vlucas/phpdotenv 5.6 + GuzzleHTTP 7  
**Database:** SQLite (default) / MySQL 8.0+ / MariaDB 10.4+  
**PHP Minimum:** 8.1+

---

## 2. Request Lifecycle

```
1. public/index.php loads vendor/autoload.php
2. Dotenv three-layer environment variable loading (see §3)
3. Static file serving (PHP built-in server; production handled by Nginx/Apache)
4. Database::init() — lazy PDO initialization (no connection yet)
5. DI container construction (config/container.php defines all service factories)
6. Slim App creation + route registration (config/routes.php)
7. Middleware registration (CORS → Routing → Error handling)
8. Request arrives → route matching → Controller method executes → response returns
```

---

## 3. Configuration System

### 3.1 Environment Variable Loading (Three-Layer Override)

```
File loading order (later overrides earlier):
1. config/app.env          ← Base config (gitignored, contains real passwords)
2. config/app.env.{ENV}    ← Environment override (committed to Git, no passwords)
3. config/app.env.local    ← Local override (gitignored, personal tweaks)

Effective priority (high → low):
app.env.local > real environment variables (OS-injected) > app.env.{ENV} > app.env

Override rules:
- Layer 1 app.env uses createImmutable().load() (safe load: an existing variable is never written,
  so real env vars injected by Docker env_file / shell export / Apache SetEnv win)
- Layers 2/3 app.env.{ENV} / app.env.local use createUnsafeMutable().load()
  ⚠️ Never use createUnsafeImmutable(): phpdotenv's Immutable means "do not write if the variable
    already exists" (RepositoryBuilder::make() → ImmutableWriter), not "allow overwrite"; every key
    already defined in app.env is silently dropped — the symptom is "editing app.env.local does nothing"
- When app.env.local exists, the keys it defines are final (they even beat real environment variables)
- After the override layers, real environment variables are written back from a getenv() snapshot:
  ImmutableWriter only inspects $_ENV / $_SERVER, so with variables_order lacking E/S (or values set
  via putenv()) OS-injected variables would otherwise be shadowed by app.env
- Single implementation: src/Support/EnvFileLoader.php (shared by Bootstrap and the bin/cli scripts)
```

**Practical usage:**

| APP_ENV | Files Loaded | Notes |
|---|---|---|
| production | `app.env` only | Default behavior, no `app.env.production` needed |
| staging | `app.env` → `app.env.staging` → `app.env.local` | `app.env.staging` usually only has `APP_DEBUG=true` |
| development | `app.env` → `app.env.development` → `app.env.local` | Same as above |

### 3.2 Runtime Configuration Persistence

| Config Item | Storage Location | Priority |
|---|---|---|
| `build_mode` | `ci_app_settings` table | DB > `app.env` (fallback) |
| `job_git_map` | `ci_job_git_map` table | DB only source |
| `platform_versions` | `ci_platform_versions` table | DB > JSON > defaults |
| Admin Token | `cache` table (24h TTL) | DB only source |
| Harbor API version | `cache` table (1h TTL) | Auto-detected and cached |

### 3.3 Configuration Read Chain

```
Controller → AppConfig::getXxxConfig()
    → settings.php[$section]
        → $_ENV['XXX']  (injected by Dotenv)
```

`AppConfig` is the sole facade — all controllers/services read config through it, never accessing `$_ENV` directly.

---

## 4. Database Design

### 4.1 Complete Table Definitions

#### ci_job_git_map (Core Mapping Table)

| Field | Type | Description |
|---|---|---|
| `job_name` | TEXT/VARCHAR(255) PK | Jenkins job name or GitLab CI project path |
| `git_platform` | TEXT | gitlab/github/gitee/gitea or custom |
| `build_provider` | TEXT DEFAULT 'jenkins' | jenkins / gitlab_ci |
| `git_remote` | TEXT | Git remote URL (http/ssh) |
| `project_id` | INTEGER | Project ID on Git platform (auto-resolved for GitLab) |
| `web_url` | TEXT | Project homepage URL |
| `current_path` | TEXT | Git repository path (e.g., tools/registry) |
| `harbor_repository` | TEXT | Associated Harbor repository (format: project/repo) |
| `api_version` | TEXT | API version (metadata, doesn't affect actual calls) |
| `status` | TEXT DEFAULT 'active' | active / disabled |

**Index:** `idx_job_git_map_current_path` ON `(current_path)`

#### ci_platform_versions (Platform Versions)

| Field | Type | Description |
|---|---|---|
| `platform` | TEXT PK | Platform name (gitlab/github/gitee/gitea/harbor) |
| `version` | TEXT NOT NULL | API version (v4/v3/v5/v1/v2) |

**Note:** Stores only user-customized non-default versions. Query uses three-tier fallback: DB → default → hardcoded.

#### ci_pipeline_artifacts (Canonical Pipeline → Primary Artifact)

| Field | Type | Description |
|---|---|---|
| `id` | INTEGER AUTO PK | Auto-increment ID |
| `provider` | TEXT NOT NULL | Build provider (jenkins/gitlab_ci/custom_push) |
| `project_id` | TEXT NOT NULL | Canonical project id (per provider normalization) |
| `pipeline_iid` | INTEGER NOT NULL | Pipeline internal ID |
| `project_key` | TEXT NOT NULL | Project path / job_name (delivery key) |
| `repository` | TEXT NOT NULL | Harbor repository name (e.g., mycode/app) |
| `tag` | TEXT NOT NULL | Image tag |
| `status` | TEXT DEFAULT '' | Scan status (success/failed/pending/unknown) |
| `source_updated_at` | DATETIME/TEXT | Source event timestamp (for stale-event protection) |
| `created_at` | DATETIME/TEXT | Creation time |
| `updated_at` | DATETIME/TEXT | Update time |

**Unique key:** `(provider, project_id, pipeline_iid)`
**Indexes:** `(project_key)`, `(created_at)`

#### cache (Generic KV Cache)

| Field | Type | Description |
|---|---|---|
| `cache_key` | TEXT/VARCHAR(255) PK | Cache key |
| `value` | TEXT/MEDIUMTEXT | JSON-serialized value |
| `expires_at` | INTEGER | Unix timestamp expiration |

**Existing cache keys:**
- `admin_token_{token}` — Admin panel token (24h TTL)
- `map_list_{mode}` — Mapping list cache (30s TTL)
- `harbor_api_version` — Harbor API version detection result (1h TTL)

#### ci_app_settings (Application Runtime Configuration)

| Field | Type | Description |
|---|---|---|
| `setting_key` | TEXT/VARCHAR(255) PK | Config key |
| `value` | TEXT/MEDIUMTEXT | Config value |
| `updated_at` | DATETIME/TEXT | Update time |

**Existing settings:** `build_mode` (comma-separated set, e.g. jenkins,gitlab_ci,gitea_ci), `custom_push_enabled` (0/1)

#### admin_users (Admin Accounts)

| Field | Type | Description |
|---|---|---|
| `username` | TEXT PK | Username |
| `password_hash` | TEXT NOT NULL | bcrypt hash |
| `role` | TEXT DEFAULT 'admin' | admin / super_admin |
| `updated_at` | DATETIME/TEXT | Update time |

**Initialization:** `seedAdmin()` creates initial user from `app.env` `ADMIN_USER`/`ADMIN_PASSWORD` when `admin_users` table is empty.

#### ci_security_checks (Security Scan Audit)

| Field | Type | Description |
|---|---|---|
| `id` | INTEGER AUTO PK | Auto-increment ID |
| `project` | TEXT NOT NULL | Project path |
| `sha` | TEXT NOT NULL | Commit SHA |
| `check_type` | TEXT NOT NULL | Check type (sast/secret-scan/sca/iac-audit/harbor-scan) |
| `state` | TEXT NOT NULL | Status (success/failed/pending/error) |
| `context` | TEXT NOT NULL | Check name (commit status context) |
| `description` | TEXT | Short description |
| `tag` | TEXT DEFAULT '' | Associated image tag |
| `writeback_status` | TEXT DEFAULT '' | Commit-status writeback result (success/failed/skipped, empty = historical/unknown) |
| `writeback_message` | TEXT | Error message when writeback fails |
| `created_at` | DATETIME/TEXT | Record time |

**Indexes:** `(project, check_type)`, `(sha)`

#### ci_custom_builds (Custom_Push Build Metadata)

| Field | Type | Description |
|---|---|---|
| `job_name` | TEXT NOT NULL (PK) | Job name or project path |
| `pipeline_iid` | INTEGER NOT NULL (PK) | Pipeline internal ID (integer, aligns with `ci_pipeline_artifacts` constraint) |
| `status` | TEXT | Build status |
| `sha` | TEXT | Commit SHA |
| `exit_code` | INTEGER | Build exit code |
| `log_url` | TEXT | Log URL (pointer only, log content not stored; 302 redirect on access) |
| `web_url` | TEXT | Pipeline Web link |
| `started_at` | DATETIME/TEXT | Build start time |
| `finished_at` | DATETIME/TEXT | Build finish time |
| `variables_json` | TEXT | Custom build variables (JSON serialized) |
| `created_at` | DATETIME/TEXT | Creation time |

**Unique key:** `(job_name, pipeline_iid)`

**Notes:**
- Control fields (`pipeline_iid`/`status`/`finished_at`/`started_at`/`ref`/`sha`/`exit_code`/`log_url`/`web_url`/`tag`/`harbor_repository`) are stored separately from custom variables in `variables_json`
- `pipeline_iid` cannot be modified after creation; duplicate reports with the same `(job_name, pipeline_iid)` overwrite (UPDATE) the existing record, preserving the auto-increment id
- Image tags are not stored in this table; they are canonicalized in `ci_pipeline_artifacts` (shared with the CD layer)

### 4.2 Database Sync Rules

⚠️ **Important:** Table structure changes must be updated in four places simultaneously:

1. `config/AppConfig.php` → Add a `TABLE_*` constant
2. `src/Service/Database.php` → `ensureTables()` method
3. `database/mysql_init.sql`
4. `database/sqlite_init.sql`

### 4.3 Database Backup (Web)

The admin panel's "System Settings → Data Management → Database" card provides a **Backup Database** button (`super_admin` only), implemented by `src/Service/DataBackupService` (the `bin/` tools are not shipped in the image, so the Web side does not depend on the CLI backup tools).

- **Driver**: supports sqlite / mysql, exports per the current `DB_DRIVER`.
- **Output**: when CD is enabled (the shared database has `cd_*` tables), two zips are produced — `devops-glue_<driver>_<datetime>.zip` (app tables) + `devops-cd_<driver>_<datetime>.zip` (`cd_*` tables); when CD is not enabled, only one is produced. Each zip contains a same-named `.sql` pure-data file (`INSERT` statements, no DDL), excluding `cache` / `ci_platform_versions`; backup only, no Web restore (restore / migration still use `bin/backup.php` and `bin/restore.php`).
- **Directory resolution** (`backupDir()`): the `BACKUP_DIR` env var takes priority; defaults to `/data/backups` in Docker (the compose volume maps host `./data/backups`); falls back to the repo-root `backups/` when not in Docker and unset (same as the CLI default). The directory is auto-created and rolls over the latest 10 files per driver prefix.
- **Endpoint & audit**: `POST /api/admin/backup` (super_admin gated), records a `backup_database` entry in the operation log on success.
- **Privileges required**: same as 4.4 — the MySQL account must hold database-level (or global) `SELECT`/`ALL` on the shared database, otherwise `cd_*` tables are silently skipped (a pre-backup self-check logs a WARN).

### 4.4 Database Backup (Container Cron, CI/CD Split)

`cli/backup-db.php` (shipped in the image, scheduled by supervisord's `db-backup` loop) reuses the Web-side `DataBackupService` — the same logic as the admin "Backup Database" button. When CD is enabled (the shared database has `cd_*` tables) it produces **two independent zips** (CI app tables + the CD system's `cd_*` tables), so restoring one never touches the other; when CD is not enabled, only one zip is produced.

- **Switch**: `DB_BACKUP_ENABLED` in the root `.env` (default off; when off it prints a skip message and exits 0, avoiding surprising existing deployments with extra disk writes); the interval is overridden by `DB_BACKUP_INTERVAL`, default 86400 seconds. You can also run `php cli/backup-db.php --force` manually.
- **Output**: same as the Web side (reuses `DataBackupService`, "same logic") — when CD is enabled it produces `devops-glue_<driver>_<datetime>.zip` (mode=app, app tables) + `devops-cd_<driver>_<datetime>.zip` (mode=cd, `cd_*` tables; written only when `cd_*` tables exist); when CD is not enabled, only one zip is produced. Each zip contains a same-named `.sql` pure-data file; each prefix rolls over the latest 10 files. Both still unconditionally exclude `cache` (session tokens are ephemeral credentials) and `ci_platform_versions` (derived cache).
- **Base tables only**: the enumeration filters `table_type='BASE TABLE'`, so views (e.g. `v_glue_deploy_logs`) are never exported as tables — otherwise a fake `INSERT` against the view would be generated and restore would inevitably fail.
- **Privileges required**: `information_schema.tables` only returns tables the current account has some privilege on. The account must hold database-level (or global) `SELECT`/`ALL` on the shared database, otherwise `cd_*` tables are silently skipped — a pre-backup self-check logs a WARN for this (see `DataBackupService::mysqlVisibilityWarning()`). The Docker default `devops` account already gets database-level `ALL` at first init; bare-metal users must run `GRANT ALL PRIVILEGES ON devops_glue.* TO '<account>';` themselves.
- **Restore**: `php bin/restore.php <devops-glue_*.zip> --yes` restores the CI app tables; `php bin/restore.php <devops-cd_*.zip> --yes --cd` restores the CD tables (both `.zip` and `.sql` inputs are supported). The file's META mode is validated, so a CI backup cannot be used for a CD restore and vice versa.

> Note: `cli/backup-lib.php` is the shared backup/restore library (used by `bin/backup.php`, `bin/restore.php`, `cli/backup-db.php`), shipped in the image; the offline tools under `bin/` are not shipped but require this library.

### 4.5 Sync Schema (Web Button / CLI)

The **🔧 Sync Schema** button on the "Data Management → 🗄️ Database" card (`super_admin` only) takes the very same `Database::migrateNow()` path as `cli/migrate.php`: it aligns the schema and the built-in seed with the **current code version**.

- **What it does**: `ensureTables()` (missing tables via `CREATE TABLE IF NOT EXISTS`, per-column `ALTER ADD COLUMN` from `$columnMigrations`, missing indexes via `createIndex()`, dropping the retired legacy table `ci_pipeline_tags`) → `seedRbac()` / `seedAdmin()` → `markSchemaCurrent()` + `markSeedCurrent()` once `verifySeed()` passes.
- **What it never does**: no column renames/drops, no changes to column types or primary keys (e.g. `admin_users.id`), no versioned up/down, no dry-run, no cross-database migration — those are "manual migrations", to be run offline with database tools after a backup.
- **The two entry points differ**: the Web button executes DDL with the **application's own database account**; when that account lacks `CREATE`/`ALTER` it answers **409** + `sys.migrate_denied` (explicitly pointing at the CLI) and records a failed `migrate_schema` operation-log entry. `cli/migrate.php` ignores `DB_AUTO_MIGRATE` and can be run with a privileged account from a deployment pipeline (K8s Job / initContainer, Helm pre-upgrade hook, one-shot Compose service).
- **Login prerequisite**: the button requires a login, and a missing table makes login itself fail with a 500 — for empty/partial databases use `php cli/db-init.php` and `php cli/migrate.php`; the button is for "already logged in, just need to align the schema".
- **Terminology**: "Sync Schema" = this button (and `cli/migrate.php`); "Database Migration" = the CLI phrasing of the same action; "Manual Migration" = renames, type changes, column drops, primary-key changes, cross-database moves.

---

## 5. Core Business Logic

### 5.1 Liveness / Readiness Probe
**Route:** `GET /healthz`  
**Controller:** `HealthzController::__invoke()`

Unauthenticated external probe for Uptime Kuma / cloud LB / container orchestration. It only does a DB probe, an applied-version read and a schema-alignment check — it does not probe Jenkins/Git/Harbor and returns in milliseconds.

```
1. DB probe (Database::createPdo() + SELECT 1)
   └─ on success Database::schemaState(): one SQL reads schema_version / seed_version and decides schema_current
   └─ either step throws (including a missing ci_app_settings) → db=false
2. Only when schema_current=false: Database::missingTables() probes the core tables one by one
   (the happy path never pays for these N queries, keeping the probe in the millisecond range)
3. Summary
   └─ db=true  + schema_current≠false → HTTP 200 + status=ok
   └─ db=true  + schema_current=false → HTTP 200 + status=degraded (stale version, no traffic pulled)
   └─ db=false                        → HTTP 503 + status=degraded (reported truthfully, not 500)
```

**Response structure:**
```json
{
  "status": "ok",
  "db": true,
  "app_version": "2.8.9",
  "schema_version": "2.8.9",
  "seed_version": "2.8.9",
  "schema_current": true,
  "auto_migrate": true,
  "time": 1760000000
}
```

- `schema_current`: the result of comparing `schema_version` (auto-migrate mode) or `seed_version` (with `DB_AUTO_MIGRATE=false` no `schema_version` is written — otherwise a pure manual deployment would report degraded forever) against `APP_VERSION`; `null` when neither record exists (unknown, never treated as degraded).
- `seed_version`: recorded only with `DB_AUTO_MIGRATE=false` (`null` in auto-migrate mode, where `schema_version` is authoritative).
- `tables_missing`: **only present when `schema_current=false` and tables really are missing**, listing the missing core tables; not probed and not emitted when `schema_current` is `true`/`null`.
- `auto_migrate`: whether auto-migrate mode is on, so a probe reader can spot the "manual mode + stale version" combination directly.
- **Why a stale version does not return 503**: during a rolling upgrade "new code + old schema" is an expected intermediate state; returning 503 as well would make the LB / K8s pull every replica, far more harmful than the degraded state itself. Monitoring should alert on the `status` / `schema_current` fields instead.

---

### 5.2 Health Check
**Route:** `GET /api/health`
**Controller:** `MainController::healthDetail()`
**Auth:** Requires a Bearer token (admin login token or any valid API token; no specific scope)

**Check Process:**

```
1. Jenkins check (only when build_mode includes jenkins; pure-CI modes return null and skip)
   ├─ Short-timeout client GET /api/json to verify connectivity (retried once after 0.5s on failure)
   └─ Calls getVersion() to get version number

2. Git platform check
   ├─ Collects actually-used platforms from job_git_map
   ├─ Only checks configured AND actually-used platforms
   └─ Sends HEAD request to each platform API URL (3s timeout)
       └─ HTTP status < 500 considered reachable

3. Harbor check (multi-component)
   ├─ core:       GET /api/v2.0/projects
   ├─ jobservice: GET /api/v2.0/jobservice/ping (404 still considered OK)
   └─ registry:   GET /api/v2.0/registries
   └─ Any component false → harbor: false

4. Stats cards
   ├─ total_maps:    SELECT count(*) FROM ci_job_git_map
   ├─ active_maps:   count WHERE status='active'
   ├─ git_platforms: count(DISTINCT git_platform)
   └─ harbor_repos:  count(DISTINCT harbor_repository)

5. Summary judgment
   └─ allOk = jenkins && git && harbor
   └─ HTTP 200 (ok) / 503 (degraded)
```

**Response structure:**
```json
{
  "status": "ok",
  "checks": { "jenkins": true, "jenkins_version": "2.555.3", "git": [...], "harbor": true, "harbor_components": {...} },
  "stats": { "total_maps": 4, "active_maps": 4, "git_platforms": 2, "harbor_repos": 4 },
  "build_mode": "jenkins,gitlab_ci,gitea_ci", "build_modes": ["jenkins","gitlab_ci","gitea_ci"], "build_mode_source": "database",
  "db_driver": "mysql", "app_version": "2.8.9", "app_env": "production",
  "time": "2026-07-25 12:00:00"
}
```

**Supplement: quick health info (health/static)**

**Route:** `GET /api/health/static` (Controller: `MainController::healthStatic()`)
Performs no external probing; returns the stats cards + system info + Custom_Push status from `buildHealthStaticData()` in milliseconds so the frontend can render the non-probe cards first. Detailed probing is done by `/api/health`. **Admin login tokens only; API tokens always get 403 (fail-closed).**

---

### 5.3 Mapping Management
**Core table:** `ci_job_git_map`  
**Admin endpoints:** `/api/admin/job_git_map` (GET/POST/PUT/DELETE)  
**Authenticated endpoint:** `/api/main/map/list` (grouped by Git repository, requires Token)

#### 5.3.1 Data Writing
- **Manual add/edit:** Admin UI → AdminController CRUD
- **Auto-discovery:** `POST /api/admin/discover` → `AutoDiscover::discover()` scans all Jenkins jobs, parses SCM config to extract Git URLs → `saveDiscovered()` writes to `ci_job_git_map`

#### 5.3.2 Data Reading & Grouping
`MainController::mapList()` logic:

1. Read `ci_job_git_map` → filter `status=active` → filter by `build_mode`
2. Group by key extracted from `job_name` or `current_path` or `git_remote`
3. Each group contains: `git_platform`, `git_remote`, `project_id`, `web_url`, `harbor_repository`, `jobs`
4. Write to `cache` table (30s TTL) to reduce repeated query overhead
5. Skip cache in `gitlab_ci` mode (to avoid stale Jenkins data contamination)

#### 5.3.3 MappingManager's Role
All business-layer queries must go through `MappingManager`:
- `activeMaps()` — returns all active mappings under current `build_mode`
- `resolveProject(path)` — resolves `[provider, projectId]` from project path
- `usedGitPlatforms()` — collects Git platforms used in active mappings
- `activeJobNames()` — returns list of active job names

#### 5.3.4 Git Platform Auto-Detection
When `git_platform` is not explicitly specified, the system auto-detects:

1. Extract domain from `git_remote` URL
2. Match using each `GitProvider::matchUrl()` (keywords: gitlab/github.com/gitee.com/...)
3. If no match → fall back to `DEFAULT_GIT_PLATFORM` env variable

---

### 5.4 Dual-Channel Build System
**Modes:** jenkins / gitlab_ci / gitea_ci (multi-select set)  
**Unified route entry:** `/api/build/{path}/...`

#### 5.4.1 Project Resolution
First step of all Build operations is `resolve(path)`:

```
MappingManager::resolveProject(path)
├─ Iterates ci_job_git_map, matching job_name or current_path
├─ Returns build_provider (jenkins/gitlab_ci)
└─ Returns projectId (jenkins=path, gitlab_ci=project_id or path)
```

#### 5.4.2 BuildProvider Interface
```php
interface BuildProviderInterface {
    public function getName(): string;          // 'jenkins' or 'gitlab_ci'
    public function trigger(string $projectId, string $ref, array $variables): array;
    public function getPipelines(string $projectId, int $limit = 20): array;
    public function getJobs(string $projectId, int $pipelineId): array;
    public function getJobTrace(string $projectId, int $jobId): string;
    public function getVariables(string $projectId): array;
    public function retry(string $projectId, int $pipelineId): array;
    public function cancel(string $projectId, int $pipelineId): array;
}
```

#### 5.4.3 Trigger Build
**Route:** `POST /api/build/{path}/trigger`

Parameter merge priority: POST body root-level > POST body `variables` nested > Query String  
If only `ref` is present without other parameters, auto-convert to `{branches: ref}`.

#### 5.4.4 Pipeline List
**Route:** `GET /api/build/{path}/pipelines?list=id|build|time|success`

`list` parameter options:
- `id` — returns `[10, 9, 8]` all pipeline numbers
- `success` — same but only successful ones
- `build` — returns `["#10", "#9"]` successful pipelines
- `time` — returns `["#10 [2026-07-25]", "#9 [...]]"` success + timestamp
- (not provided) — returns full JSON (with build_provider, project_id, pipelines array)

#### 5.4.5 Failure Points
| Issue | Cause | Symptom |
|---|---|---|
| `Build system 'xxx' not configured` | build_mode inconsistent with actual Provider availability | 400 |
| Project resolution failed | No mapping in `ci_job_git_map` | Falls back to path itself as projectId |
| Jenkins 504 timeout | `BUILD_TIMEOUT` too small or network slow | 500 |

---

### 5.5 Scan Sync (scan-sync)
**Route:** `POST /api/build/{path}/scan-sync`  
**Method:** `BuildController::scanSync()`

**Business Process:**

```
1. Query ci_job_git_map for harbor_repository + build_provider + git_platform
2. Parse harbor_repository (format: project/repo)
3. Get tag list from Harbor
   ├─ Body has tag → validate it exists in the list
   └─ Body has no tag → take the first (latest)
4. Attempt to get SHA and IID of latest pipeline (failure won't block main flow)
5. Harbor scan report retrieval
   ├─ Harbor unreachable → state=pending
   ├─ Scan not enabled → state=pending
   ├─ Has vulnerabilities → state=failed, record vulnerability count
   └─ No vulnerabilities → state=success
6. Commit Status writeback (via Git Provider, independent of CI system)
   └─ context='harbor-scan', description='#10 → v1.0 · 3 vulns'
7. Record to ci_pipeline_artifacts (provider + project_id + pipeline_iid + project_key + repository + tag + status)
```

**⚠️ Key Constraints:**
- `harbor_repository` must be configured (otherwise 400)
- `tag` must actually exist in Harbor (strict validation; downgrades to pass if Harbor unreachable)
- `harbor_repository` format must be `project/repo` (two segments separated by `/`)

---

### 5.6 Commit Status Writeback
**Route:** `POST /api/build/{path}/commit-status`  
**Method:** `BuildController::commitStatus()`

**Use case:** Any security scan step in CI pipeline, such as Gitleaks secret scanning, Trivy/Snyk dependency vulnerabilities, SonarQube SAST.

**Required Parameters:**

| Parameter | Description |
|---|---|
| `sha` | Commit SHA (40 hex chars) |
| `state` | pending / success / failed / error |
| `context` | Check name, e.g., "secret-scan", "sast" |
| `description` | Short description |

**Optional Parameters:**

| Parameter | Description |
|---|---|
| `target_url` | Detail link (e.g., SonarQube report URL) |
| `check_type` | Audit classification label, defaults to context |
| `tag` | Associated image tag |

**Execution Flow:**

```
1. Parameter validation (sha/state/context/description required, state validity check)
2. Query ci_job_git_map for git_platform
   └─ Not configured → 400 "git_platform not configured, cannot write back"
3. Git Provider matching
   └─ Not registered → 400 "Git platform xxx not configured or unavailable"
4. Call GitProvider::setCommitStatus(sha, state, context, description, target_url)
   ├─ GitLab: POST /api/v4/projects/{id}/statuses/{sha}
   ├─ GitHub:  POST /repos/{owner}/{repo}/statuses/{sha}
   ├─ Gitee:   POST /api/v5/repos/{owner}/{repo}/statuses/{sha}
   └─ Gitea:   POST /api/v1/repos/{owner}/{repo}/statuses/{sha}
5. Record to ci_pipeline_artifacts (when tag is provided)
6. Write to ci_security_checks audit table (UPSERT, unique by project+sha+check_type)
7. Return result (with git_platform, commit_status sub-object)
```

**Commit status writeback is independent of the CI system** — it only depends on Git platform configuration. Jenkins projects can also write back commit status through this endpoint.

---

### 5.7 Security Scan Audit
**Route:** `GET /api/admin/security_checks?project=&check_type=&state=&writeback=&page=1&per_page=20`  
**Method:** `AdminController::securityChecksList()`

**Data source:** `ci_security_checks` table (written by commit-status and scan-sync)

**Filter Parameters:**
- `project` — fuzzy match (LIKE %...%)
- `check_type` — exact match
- `state` — exact match (success/failed/pending/error)
- `writeback` — exact match on writeback result (success/failed/skipped)
- `exclude` — exclude specific states (comma-separated, e.g. `exclude=pending`)
- `page` / `per_page` — pagination (per_page capped at 100)

**Response:**
```json
{
  "checks": [...],
  "total": 50,
  "page": 1,
  "per_page": 20,
  "total_pages": 3,
  "filter_opts": {
    "check_types": ["harbor-scan", "sast", "secret-scan"],
    "states": ["success", "failed", "pending", "error"],
    "writeback_statuses": ["success", "failed", "skipped"]
  }
}
```

**⚠️ Note:** `state` is `failed` (not `failure`, following the GitHub Commit Status API convention); `writeback_status` uses `skipped` for "not written back", and empty means historical/unknown.

---

### 5.8 Platform Version Management
**Route:** `GET/PUT /api/admin/platform_versions`  
**Method:** `AdminController::platformVersionsList()` / `platformVersionsUpdate()`

**Read Priority (three-tier fallback):**
1. `ci_platform_versions` table (user-customized)
2. `platform_versions.json` (JSON file, deprecated but compatible)
3. Per-Provider hardcoded defaults (e.g., GitLab=v4, Gitee=v5, GitHub=v3)

**Additional field in response:** `configured` — indicates whether the platform's `base_url` is configured (non-empty)

---

### 5.9 Build Mode Switching
**Route:** `GET /api/admin/build_mode` + `PUT /api/admin/build_mode`  
**Method:** `AdminController::getBuildMode()` / `updateBuildMode()`

**Storage:** `ci_app_settings` table
- key=`build_mode`, value = comma-separated set of {jenkins, gitlab_ci, gitea_ci} (controls pull-based CI)
- key=`custom_push_enabled`, value ∈ {0,1} (independent boolean switch, controls push-based CI)

> **Orthogonal Design:** `build_mode` (the enabled pull-CI set, e.g. jenkins/gitlab_ci/gitea_ci) only controls the pull-based CI channel; `custom_push_enabled` is an independent boolean switch. The two are not mutually exclusive and can be enabled simultaneously. The `build_mode` UI renders checkboxes for configured CIs (plus a Select All); `custom_push_enabled` is a separate checkbox. See also [§5.10](#510-custom_push-ci-mode).

**Read Logic (AppConfig::getBuildModes()):**
```
1. Read ci_app_settings WHERE setting_key='build_mode'
2. Has value → parse comma-separated set (legacy `both` → jenkins,gitlab_ci), return, source='database'
3. No value → read app.env BUILD_MODE, seed to DB → return, source='env'
4. DB exception → fall back to app.env, source='env'
```

**Write Validation (frontend & backend consistent):**
- `modes` must be a subset of {jenkins, gitlab_ci, gitea_ci}
- Enabling `jenkins` → requires JENKINS_BASE_URL configured (otherwise 400)
- Enabling `gitlab_ci` → requires GITLAB_BASE_URL + GITLAB_TOKEN configured (otherwise 400)
- Enabling `gitea_ci` → requires GITEA_BASE_URL + GITEA_TOKEN configured (otherwise 400)

**DI Container Behavior (container.php):**
- No longer decides Provider registration based on `BUILD_MODE`
- Jenkins / GitLab CI / Gitea Actions each check their own config non-empty to register
- Actual routing dispatch uses `MappingManager::activeMaps()` filtered by the DB `build_mode` set

**Impact scope:** Mode switching only affects mapping list filtering and Jenkins check in health check.

---

### 5.10 Authentication & Authorization
#### 5.10.1 Login
**Route:** `POST /api/admin/login`  
**Method:** `AdminController::login()`

**Verification Priority:**
1. Query `admin_users` table, `password_verify()` to check bcrypt hash
2. `app.env` fallback only when `admin_users` is empty (first deployment); when the DB is unreachable the login endpoint errors out instead. Otherwise the `app.env` password is never accepted; recover a forgotten password via an offline patch — contact the author to obtain it.
3. Auth success → generate 64-char hex token → write to `cache` table (`admin_token_{token}`, 24h TTL)
4. Pre-load user permissions to request attribute via `TokenService::loadPermissions()`

> **Note:** This `app.env` fallback only applies to the Devops-Glue API global admin login flow. To create a CD-specific account, create the account in the admin backend and assign CD permissions first, then write it into the CD service's own `app.env` if that service supports it.

#### 5.10.2 Token Verification (AuthMiddleware + TokenService)
**Middleware:** `AuthMiddleware` (applied to the `/api/health`, `/api/health/static`, `/api/main`, `/api/admin` (except login/logout), `/api/build`, `/api/git`, `/api/harbor` route groups)
**Service:** `TokenService` (encapsulates token validation, permission loading, token revocation)

```
1. Extract token from Authorization: Bearer xxx
2. Call TokenService::validate() to verify token in cache table (key=admin_token_{token}, expires_at > now)
   ├─ Hit (interactive login session) → call TokenService::loadPermissions() to query role permissions
   └─ Miss → fall back to TokenService::validateApiToken() to verify an API token;
      on hit, ApiScopeResolver resolves the scope required by the route:
      ├─ null (e.g. /api/admin/*) → 403 (fail-closed; admin endpoints reject API tokens)
      ├─ required scope missing → 403 (recorded in the API access audit log)
      └─ pass → the scope maps to the permission used for in-controller secondary checks
3. Write currentUser, currentRole, userPermissions to request attribute
4. If DB unavailable AND app.env ADMIN_PASSWORD empty → allow (first-start no-password scenario)
5. If DB unavailable AND admin_users table empty → allow
6. Otherwise → 401
```

**On password change:** `TokenService::revoke()` deletes all old tokens from cache table, forces re-login.

#### 5.10.3 Docs Page Authentication
**Swagger UI (`/api/docs`) and OpenAPI JSON (`/api/openapi.json`):**
- Uses routes.php closure `$checkAuth`, independent of `AuthMiddleware`
- If `app.env` `ADMIN_PASSWORD` is empty → allow directly
- Otherwise verify token (supports Bearer or Query String `?token=`)

#### 5.10.4 Build/Git/Harbor Endpoint Authentication (new in v2.4.3)
The `/api/build`, `/api/git`, and `/api/harbor` route groups are now protected by `AuthMiddleware`. All requests to these endpoints must include the `Authorization: Bearer <token>` header.

**Authentication chain:**
```
Client → CI:  Authorization: Bearer <ci_user_token>   ← User token, verified by CI
CI → GitLab:  PRIVATE-TOKEN: <gitlab_api_token>       ← CI's own service token
CI → Harbor:  Basic Auth: admin:password               ← CI's own service credentials
CI → Jenkins: Basic Auth: user:api_token               ← CI's own service token
```

> Downstream services (GitLab/Harbor/Jenkins) use independent service-account authentication and never receive CI user tokens. This is a standard BFF/Gateway pattern that prevents user tokens from leaking into downstream logs.

#### 5.10.5 RBAC Permission System (v2.4, Data-Driven)
The system uses a role-based access control (RBAC) model with **all tables stored in the database and fully manageable from the admin UI** (no code changes required when CD adds new menus/modules).

| Table | Purpose |
|---|---|
| `roles` | Role definitions: `id`, `name`, `description`, `is_system`, `created_at` |
| `permissions` | Permission catalog: `perm_key` (dot/hyphen-delimited, e.g. `cd.deploy.k8s` / `cd.build-manage`), `description`, `parent_key`, `created_at` |
| `role_permissions` | Many-to-many join: `role_id`, `perm_key` |
| `implied_rules` | **Implied permission rules** (separate table): `source_key`, `target_key` — having `source_key` automatically grants `target_key` |

**System role:**
- `super_admin` is the only built-in system role (`is_system=1`) — cannot be edited or deleted from the UI
- It uses `'*'` as a wildcard permission set (defined in `AppConfig::DEFAULT_ROLES`)
- The root user from `seedAdmin()` defaults to `super_admin`

**Built-in permission catalog (seeded into DB on first boot):**

| Category | Count | Keys |
|---|---|---|
| CI | 8 | `ci.manage`, `ci.users.manage`, `ci.users.manage_admin`, `ci.mapping.edit`, `ci.platform.edit`, `ci.mode.edit`, `ci.discover`, `ci.trigger` |
| CD Level-1 menus | 8 | `cd.build-manage`, `cd.deploy-manage`, `cd.server-manage`, `cd.webshell`, `cd.deploy-record`, `cd.image-registry`, `cd.resource-monitor`, `cd.notification-manage` |
| CD Level-2 submenus | 9 | `cd.deploy.single`/`docker`/`k8s`, `cd.monitor.app`/`system`/`custom`/`alert`, `cd.bot` (Bot Config), `cd.webhook` (WebHook Config) |

> **Permission key naming convention**
> - Level-1 menus: `{module}.{menu-name}` (hyphens, e.g. `cd.build-manage`)
> - Level-2 menus: `{module}.{group}.{menu-name}` (underscores/hyphens mixed, e.g. `cd.deploy.single`)
> - Regex validation: `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_-]*)+$`

**Implied rules (correct direction):**
- Only **child → parent** direction is allowed (tick a level-2 menu → its level-1 menu is automatically visible)
  - `cd.deploy.*` → `cd.deploy-manage`
  - `cd.monitor.*` → `cd.resource-monitor`
  - `cd.bot` / `cd.webhook` → `cd.notification-manage`
- ❌ **Never** add parent → child direction (this causes a reverse-dependency bug: "cannot revoke child permissions while parent menu is kept")
- One special rule: `cd.build-manage` → `ci.trigger` (Build Mgmt implies Trigger Build)

**Database seeding & fallback:**
- On first boot, `seedAdmin()` UPSERTs `AppConfig::DEFAULT_PERMISSIONS` and `IMPLIED_PERMISSIONS` into DB
- **All read paths go through DB** (permission list, implied-relation expansion, role permissions); only when DB is broken do we fall back to the PHP constants (avoid hard failures)
- Dynamically-registered permissions (via admin UI) go into DB the same way; built-in keys (in `DEFAULT_PERMISSIONS`) are protected and cannot be deleted
- When CD adds a new menu permission:
  - Way 1 (preferred): Admin UI → Permission Registration → Implied Rules page add child→parent → done (**zero code change**)
  - Way 2 (persistent seed): Update `AppConfig.php` constants + sync i18n `role.perm_{key}` in `lang/*/messages.php` (dots become underscores), restart backend for auto-UPSERT

**API endpoints (v2.4, grouped under RBAC tag in Swagger UI):**

| Method | Path | Description | Permission Required |
|---|---|---|---|
| GET | `/api/admin/permissions` | List all permissions (`perm_key` + `description` + `parent_key` + `created_at` + `is_builtin`) + `implied` relations dict + `builtin_implied` (built-in implied rules) | Bearer Token + `ci.permissions.list` |
| POST | `/api/admin/permissions` | Register new permission (body: `{ perm_key, description, parent_key? }`) | super_admin |
| DELETE | `/api/admin/permissions/{perm_key}` | Delete permission (cascades `role_permissions` + `implied_rules`; built-in keys are protected) | super_admin |
| POST | `/api/admin/implied_rules` | Register implied rule (body: `{ source_key, target_key }`) | super_admin |
| DELETE | `/api/admin/implied_rules` | Delete implied rule (query: `source_key=&target_key=`; built-in implied rules are protected, user-added ones can be deleted) | super_admin |
| GET | `/api/admin/roles` | Role list — each role carries its `permissions` array | Bearer Token + admin login |
| POST | `/api/admin/roles` | Create custom role (body: `{ name, description, permissions }`; backend auto-expands implied keys) | `ci.users.manage_admin` |
| PUT | `/api/admin/roles/{id}` | Update custom role (name/description/permissions; `is_system=1` is locked) | `ci.users.manage_admin` |
| DELETE | `/api/admin/roles/{id}` | Delete custom role (400 `role.in_use` when any user still binds it) | `ci.users.manage_admin` |
| PUT | `/api/admin/users/{username}` | Update user (body: `password` and/or `role`) | `ci.users.manage` |
| GET | `/api/admin/me/permissions` | Current user's role + permission list (super_admin → `permissions: "*"` wildcard; others → implied-expanded array) | Valid Bearer Token only |

**API Token endpoints (v2.5.0, independent of RBAC, super_admin only):**

| Method | Path | Description | Permission Required |
|---|---|---|---|
| GET | `/api/admin/api_tokens/scopes` | Return the selectable scope catalog | super_admin |
| GET | `/api/admin/api_tokens` | List tokens (no plaintext) | super_admin |
| POST | `/api/admin/api_tokens` | Create a token (returns one-time plaintext) | super_admin |
| POST | `/api/admin/api_tokens/{id}/revoke` | Revoke a token (soft delete, disable and keep the record) | super_admin |
| DELETE | `/api/admin/api_tokens/{id}` | Delete a token (hard delete) | super_admin |

> API tokens are sent via the standard `Authorization: Bearer <token>` header, independent of RBAC, and carry scopes directly; `/api/admin/*` admin endpoints are always fail-closed (403) for API tokens. See [API_Reference.md](API_Reference.md), "API Token Management".

**Implied-relation expansion logic** (`expandPermissions()`):
```php
// Pull implied_rules table in one shot
foreach ($perms as $pk) {
    if (isset($implied[$pk])) {
        $result = array_merge($result, $implied[$pk]);
    }
}
```
Primary path: DB; falls back to `AppConfig::IMPLIED_PERMISSIONS` constants on DB failure.

**Permission check logic** (`hasPermission($permKey)`):
```php
if ($role === 'super_admin') return true;  // hardcoded bypass
// Otherwise: read userPermissions directly from request attribute (pre-loaded by AuthMiddleware),
//            expandPermissions() unfolds implied keys, then in_array() compares
```
The expanded permission array is loaded once in AuthMiddleware and cached in `$this->userPermissions`, reused within a single request.

---

### 5.11 Custom_Push CI Mode
Custom_Push is a **push-based CI** mode that complements the **pull-based CI** (Jenkins/GitLab CI). Users push build status, log URL, and image tags to Devops-Glue via their own CI scripts. Devops-Glue only stores metadata and log URL pointers; it does not participate in build execution nor store log content.

#### 5.11.1 Orthogonal Design
| Dimension | build_mode | custom_push_enabled |
|---|---|---|
| Type | Pull-based CI | Push-based CI |
| Direction | Devops-Glue → CI system | User CI → Devops-Glue |
| Control | jenkins / gitlab_ci / gitea_ci (multi-select) | Independent boolean switch |
| Relationship | Independent, can be enabled simultaneously | Independent, can be enabled simultaneously |

For example: `build_mode=jenkins,gitea_ci` + `custom_push_enabled=true` means Jenkins + Gitea Actions pull-based CI and Custom_Push push-based CI are active simultaneously.

#### 5.11.2 Core Components
- **`CustomPushBuildProvider`** (`src/Service/Build/CustomPushBuildProvider.php`): Implements `BuildProviderInterface`, handles `trigger`, `getPipelines`, `updateStatus`, and other methods.
- **`ci_custom_builds` table**: Stores build metadata with `(job_name, pipeline_iid)` as the unique key; `pipeline_iid` is an integer type.
- **`ci_pipeline_artifacts` table**: Canonical pipeline-artifact facts; the CD layer reads via `GET /api/build/{path}/tag`.

#### 5.11.3 Configuration & Registration
Configured via the `build.custom_providers` array in `settings.php`. CustomPushBuildProvider is configured by default:

```php
'build' => [
    'custom_providers' => [
        [
            'name'   => 'custom_push',
            'class'  => 'App\\Service\\Build\\CustomPushBuildProvider',
            'config' => [
                'variables' => [
                    'env'       => ['type' => 'choice', 'choices' => ['dev', 'staging', 'prod'], 'description' => 'Image target environment (optional)', 'required' => false],
                ],
            ],
        ],
    ],
],
```

The DI container (`container.php`) automatically registers all custom providers by iterating over the `build.custom_providers` configuration.

#### 5.11.4 Report API
**Result report: `POST /api/build/{path}/report`**

| Field | Type | Required | Description |
|---|---|---|---|
| `pipeline_iid` | INTEGER | ✅ | Build ID (provided by user CI) |
| `status` | string | ✅ | `success`/`failed`/`aborted` (terminal, no pending/running) |
| `finished_at` | datetime | ✅ | Build completion time |
| `started_at` | datetime | ❌ | Build start time |
| `ref` | string | ❌ | Build branch/tag |
| `sha` | string | ❌ | Commit SHA |
| `exit_code` | INTEGER | ❌ | Build exit code |
| `log_url` | string(URI) | ❌ | Log URL (pointer only, Devops-Glue does not store log content) |
| `web_url` | string(URI) | ❌ | User CI build page link |
| `tag` | string | ✅ (success) | Image tag — required when `status=success`; written to `ci_pipeline_artifacts` |
| `harbor_repository` | string | — | Harbor repository path — resolved from `job_git_map` (body value ignored); must be `project/repo`; both repo and `tag` verified to actually exist in Harbor on report (400 if either missing) |
| `env` | string | ❌ | Image target environment (custom variable, optional) |

> Fields outside the **control fields** (`pipeline_iid`/`status`/`finished_at`/`started_at`/`ref`/`sha`/`exit_code`/`log_url`/`web_url`/`tag`/`harbor_repository`) are automatically stored in `variables_json`.
> When `status=success`, `tag` and a resolvable `harbor_repository` are mandatory, and `ci_pipeline_artifacts` is written (provider, project_id, pipeline_iid, project_key, repository, tag, status); duplicate reports overwrite (UPDATE) the existing record.
> All JSON keys use lowercase snake_case.

#### 5.11.5 Log Proxy
Devops-Glue does not store log content, only `log_url` pointers. When accessing build logs, the system proxies log content via 302 redirect, ensuring the evidence chain is held by the executor (user CI).

#### 5.11.6 Admin Panel
- **Status card**: System monitoring page shows Custom_Push status ✅ (configured) or ⚪ (not configured)
- **Auto-discovery**: `AutoDiscover` scans Git platforms and automatically identifies projects with `build_provider=custom_push` when `custom_push_enabled=true`
- **Multi-select checkboxes**: `build_mode` checkboxes (jenkins/gitlab_ci/gitea_ci + Select All) show only configured CIs
- **Refresh requirements**: Frontend changes require browser hard refresh (Ctrl+F5); config/controller changes require backend restart

#### 5.11.7 Permissions & Scope
The report endpoint reuses the `build.report` scope, authenticated via API Token `Authorization: Bearer <token>` header.

### 5.12 Security Hardening (Response Headers / Audit Log)
**Unified security response headers** (`config/docker/nginx.conf`): `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy` (disables camera/microphone/geolocation etc.), plus CSP:

```
default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self';
object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
```

- `'unsafe-inline'` cannot be removed for now: `admin.html` has many inline `onclick` and inline `style`. First-party JS has been verified to contain no `eval` / `new Function`, so `unsafe-eval` is not enabled. Once inline handlers are extracted to external event bindings, this can be narrowed to `script-src 'self'; style-src 'self'`.
- ⚠️ **Mind the inheritance semantics when editing nginx headers**: a child block (`location` / `if`) containing **any** `add_header` discards all inherited parent `server`-level `add_header` directives — they are not additive. Because the static-asset location already has `add_header Cache-Control`, the 5 security headers must be repeated verbatim there (marked in the file as a "security header group"; change one, change the other).
- HSTS stays commented inside the HTTPS block; enable it after HTTPS is turned on.

---

## 6. Key Data Flows

### 6.1 Build → Scan → Writeback Full Chain

```
CI Pipeline Triggered
    │
    ├── Build Docker image → Push to Harbor
    │
    ├── Security scan step (Gitleaks / Trivy / SonarQube)
    │   │
    │   └── POST /api/build/{project}/commit-status
    │       ├─ sha={SHA}, state=failed/success
    │       ├─ context=secret-scan, check_type=secret-scan
    │       ├─ → Git Provider setCommitStatus() writeback
    │       └─ → ci_security_checks table record
    │
    └── Harbor scan sync
        │
        └── POST /api/build/{project}/scan-sync
            ├─ Query Harbor scan report (vulnerability count)
            ├─ → Git Provider setCommitStatus(context=harbor-scan)
            └─ → ci_pipeline_artifacts record tag→pipeline mapping
```

### 6.2 Mapping Config → API Call Chain

```
Admin panel edits mapping
    │
    └── AdminController CRUD
        └── AppConfig::saveJobGitMap()
            └── ci_job_git_map table full write

API call (e.g., /api/build/project/tag)
    │
    └── BuildController → resolve(project)
        └── MappingManager::resolveProject(project)
            └── Iterate ci_job_git_map matching job_name/current_path
                ├─ Returns build_provider → BuildProviderRegistry::create()
                └─ Returns git_platform → GitProviderRegistry::create()
```

### 6.3 Build Mode Switch Effect Chain

```
Admin panel: PUT /api/admin/build_mode {mode: "jenkins"}
    │
    └── AdminController::updateBuildMode()
        ├─ Validate Provider availability
        └── AppConfig::setBuildMode("jenkins")
            └── ci_app_settings UPSERT {key:"build_mode", value:"jenkins"}

Next API call:
    │
    └── MappingManager::activeMaps()
        └── AppConfig::getBuildMode() → "jenkins"
            └── Returns only mappings where build_provider != 'gitlab_ci'
```

---

## 7. Common Troubleshooting

### 7.1 Database

| Symptom | Possible Cause | Diagnosis |
|---|---|---|
| Startup error: `DB_DRIVER must be sqlite or mysql` | `app.env` not configured or incorrect | `echo $DB_DRIVER`, verify value |
| SQLite: `unable to open database` | Directory lacks write permission | `chmod 777 config/data/` |
| MySQL: `Access denied` | Wrong password or insufficient user privileges | `mysql -u root -p` to verify |
| Table not found error | `DB_AUTO_MIGRATE=false` and tables not manually created | Check `app.env` `DB_AUTO_MIGRATE` value |
| `admin_users` has data but login fails | Password hash mismatch with in-memory state | Change password via admin panel, or delete table row for `seedAdmin` to recreate |

### 7.2 External Service Connectivity

| Symptom | Possible Cause | Diagnosis |
|---|---|---|
| Jenkins health check returns false | JENKINS_BASE_URL not reachable | `curl $JENKINS_BASE_URL/api/json` |
| Git platform reachable=false | Wrong base_url or network issue | `curl -I $GITLAB_BASE_URL` |
| Harbor check failed | Harbor service not running or wrong URL | Test `/api/v2.0/projects` etc. individually |
| Harbor scan HTTP 503 | Harbor scanner not configured | Check scanner in Harbor admin panel |
| Git Provider `setCommitStatus` returns 404 | Wrong `project_id` or no permission | Check if project exists on Git platform |

### 7.3 Build

| Symptom | Possible Cause | Diagnosis |
|---|---|---|
| `Build system 'xxx' not configured` | build_mode inconsistent with Provider availability | `/api/admin/build_mode` check has_jenkins/has_gitlab_ci |
| Build behavior unchanged after mode switch | `build_provider` field in `ci_job_git_map` not synced | Check each mapping item's `build_provider` |
| Pipeline list empty | Project doesn't exist or has no pipeline in CI system | Check directly on CI system |
| scan-sync: `tag not found in Harbor` | Tag cleaned up or not yet synced | Confirm tag exists in Harbor |

### 7.4 Mapping Configuration

| Symptom | Possible Cause | Diagnosis |
|---|---|---|
| Project mapping not found | `job_name` or `current_path` doesn't match | `GET /api/admin/job_git_map` view all mappings |
| `git_platform` auto-detection failed | URL doesn't contain platform keyword | Manually specify `git_platform` in mapping |
| Mapping changes not taking effect | Cache (30s TTL) not expired yet | Wait 30s or restart service, or switch to gitlab_ci mode (skip cache) |

### 7.5 Authentication

| Symptom | Possible Cause | Diagnosis |
|---|---|---|
| Login always 401 | Password mismatch between `admin_users` and `app.env` | Reset via offline patch — contact the author to obtain it |
| Token suddenly invalid | Service restart, password changed, or 24h expired | Re-login |
| Swagger UI inaccessible | Not logged in | Access `/api/docs` will auto-redirect to login page |

### 7.6 Performance

| Symptom | Possible Cause | Solution |
|---|---|---|
| `/api/health` slow (>3s) | External service timeout | Reduce timeout values (default 3-5s), check network |
| Mapping list API slow | Cache miss + slow DB query | Verify cache table exists and is writable |
| Harbor pagination fetching large data | Many projects/repos/tags | Page limits already set (projects 1000, repos 1000, tags 2000), excess logs warning |

---

## 8. Appendix: Complete Configuration Reference

### 8.1 All Environment Variables

```ini
# ============ CI System ============
JENKINS_BASE_URL=http://your-jenkins:8080
JENKINS_USER=admin
JENKINS_TOKEN=your_token
BUILD_MODE=jenkins,gitlab_ci,gitea_ci   # enabled CI sources, comma-separated (initial seed value)
BUILD_TIMEOUT=300                  # Build timeout (seconds)

# ============ Git Platforms ============
GITLAB_BASE_URL=http://your-gitlab
GITLAB_TOKEN=your_token
GITHUB_BASE_URL=https://api.github.com
GITHUB_TOKEN=
GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=
GITEA_BASE_URL=https://your-gitea
GITEA_TOKEN=
DEFAULT_GIT_PLATFORM=gitlab        # Fallback platform when URL cannot be recognized

# ============ Harbor ============
# A robot account (robot$xxx) can call the REST API only on Harbor v2.2.0+ (secret-based);
# on v1.x / v2.0.x / v2.1.x the robot token is a JWT (Docker/Helm CLI only) — use a normal account.
HARBOR_BASE_URL=http://your-harbor
HARBOR_USER=admin
HARBOR_PASSWORD=your_password

# ============ Admin Panel ============
ADMIN_USER=admin
ADMIN_PASSWORD=                    # Created on first startup; DB takes precedence afterwards

# ============ Database ============
DB_DRIVER=mysql                    # sqlite or mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=devops_glue
DB_USER=root
DB_PASS=your_password
DB_PATH=config/data/data.db        # SQLite only
DB_AUTO_MIGRATE=true               # Auto-create tables; false requires manual scripts in database/
BACKUP_DIR=/data/backups           # Backup output directory (shared by Web backup zip and CLI backup .sql)
DB_BACKUP_ENABLED=0                # Docker: container cron backup loop (CI/CD split) switch (0=off, 1=on)
DB_BACKUP_INTERVAL=86400           # Loop interval (seconds), default 86400 (daily)

# ============ Application ============
APP_ENV=production                 # production / staging / development
APP_DEBUG=false
APP_LOCALE=zh_CN                   # Default locale (zh_CN / en)
API_BASE_URL=http://127.0.0.1:8080 # Swagger / OpenAPI external address
LOG_PATH=/applogs/                 # Log directory
LOG_RETAIN_DAYS=30                 # App log retention days; Logger self-cleans old app-*.log (0=keep forever)
```

### 8.2 Complete Route Table

| Method | Route | Auth | Description |
|---|---|---|---|
| GET | `/` | No | Homepage HTML |
| GET | `/admin` | No | Admin page HTML |
| GET | `/healthz` | No | Liveness/readiness probe (DB + schema version only) |
| GET | `/api/health` | Token | Health check (detailed probing; any valid token) |
| GET | `/api/health/static` | Token | Quick health info (no external probing; admin login tokens only, API tokens get 403) |
| GET | `/api/docs` | Yes | Swagger UI |
| GET | `/api/openapi.json` | Yes | OpenAPI 3.0 spec |
| GET | `/api/i18n/{locale}` | No | i18n language pack |
| **Main** | | | |
| GET/POST | `/api/main/jobs/list` | Token | Job list |
| GET/POST | `/api/main/map/list` | Token | Three-party mapping (30s cache) |
| GET/POST | `/api/main/git/platforms` | Token | Git platform list |
| GET/POST | `/api/main/git/discovery` | Token | Platform access detection |
| **Admin** | | | |
| POST | `/api/admin/login` | No | Login to get token |
| POST | `/api/admin/logout` | No | Logout, revoke token |
| PUT | `/api/admin/password` | Token | Change password |
| GET | `/api/admin/job_git_map` | Token | Mapping list (search/pagination) |
| POST | `/api/admin/job_git_map` | Token | Add mapping |
| PUT | `/api/admin/job_git_map` | Token | Update mapping |
| DELETE | `/api/admin/job_git_map` | Token | Delete mapping |
| GET | `/api/admin/platform_versions` | Token | Platform version list |
| GET | `/api/admin/platform_versions/probe` | Token | Actually probe Harbor / Jenkins versions (slow standalone endpoint) |
| PUT | `/api/admin/platform_versions` | Token | Update platform version |
| POST | `/api/admin/discover` | Token | Auto-discover projects |
| GET | `/api/admin/security_checks` | Token | Security scan list (filter/pagination, incl. writeback status) |
| GET | `/api/admin/build_mode` | Token | Get build mode |
| PUT | `/api/admin/build_mode` | Token | Update build mode |
| GET | `/api/admin/custom_builds` | Token | Custom_Push build metadata list (filter/pagination) |
| GET | `/api/admin/users` | Token | User list |
| POST | `/api/admin/users` | Token | Create user |
| PUT | `/api/admin/users/{username}` | Token | Update user |
| PUT | `/api/admin/users/{username}/password` | Token | Reset user password |
| PUT | `/api/admin/users/{username}/status` | Token | Enable/disable user |
| DELETE | `/api/admin/users/{username}` | Token | Delete user |
| GET | `/api/admin/roles` | Token | Role list |
| POST | `/api/admin/roles` | Token | Create role |
| PUT | `/api/admin/roles/{id}` | Token | Update role |
| DELETE | `/api/admin/roles/{id}` | Token | Delete role |
| GET | `/api/admin/permissions` | Token | Permission list (with is_builtin / created_at / implied) |
| POST | `/api/admin/permissions` | Token | Register permission |
| DELETE | `/api/admin/permissions/{perm_key}` | Token | Delete permission |
| POST | `/api/admin/implied_rules` | Token | Add implied rule |
| DELETE | `/api/admin/implied_rules` | Token | Delete implied rule |
| GET | `/api/admin/me/permissions` | Token | Get current user permissions |
| GET | `/api/admin/operation_logs` | Token | Operation audit log (filter/pagination) |
| GET | `/api/admin/deploy_logs` | Token | CD deployment record audit list (via the v_glue_deploy_logs view, filter/pagination) |
| GET | `/api/admin/api_access_logs` | Token | API token call audit list (filter/pagination) |
| GET | `/api/admin/platform_config` | Token | Platform access status (sanitized) + per-platform tag / API call log settings |
| PUT | `/api/admin/platform_config` | Token | Update per-platform tag / API call log settings (only keys explicitly provided) |
| POST | `/api/admin/tag_cleanup` | Token | Manually trigger the expired-tag cleanup cron |
| POST | `/api/admin/tag_backfill` | Token | Manually trigger the historical-tag backfill cron |
| GET | `/api/admin/system_info` | Token | System info (version/env/DB driver/Custom_Push) |
| POST | `/api/admin/migrate` | Token | Manually sync the schema (missing tables + columns + indexes + seed; answers 409 pointing at the CLI when the account lacks DDL privileges; super_admin only) |
| POST | `/api/admin/backup` | Token | Manually run a database backup (super_admin only) |
| GET | `/api/admin/backups` | Token | List database backup files (super_admin only) |
| GET | `/api/admin/api_tokens/scopes` | Token | API token scope catalog |
| GET | `/api/admin/api_tokens` | Token | API token list |
| POST | `/api/admin/api_tokens` | Token | Create API token |
| POST | `/api/admin/api_tokens/{id}/revoke` | Token | Revoke API token |
| DELETE | `/api/admin/api_tokens/{id}` | Token | Delete API token |
| **Build** | | | |
| GET/POST | `/api/build/jobs/list` | Token | Job list (with ci_provider) |
| GET | `/api/build/config-mode` | Token | Build mode status |
| GET/POST | `/api/build/projects` | Token | Build provider project list |
| GET/POST | `/api/build/{path}/pipelines` | Token | Pipeline list |
| GET/POST | `/api/build/{path}/pipelines/{id}` | Token | Pipeline details + jobs |
| GET/POST | `/api/build/{path}/pipelines/{id}/logs` | Token | Pipeline logs (Gitea CI only; `id`=run id) |
| POST | `/api/build/{path}/pipelines/{id}/resolve-tag` | Token | Lazy fallback resolution of image tag from logs (sha cache + force re-resolve) |
| POST | `/api/build/{path}/pipelines/{id}/retry` | Token | Retry pipeline (GitLab CI only) |
| POST | `/api/build/{path}/pipelines/{id}/cancel` | Token | Cancel pipeline (GitLab CI only) |
| GET/POST | `/api/build/{path}/logs/{id}` | Token | Build logs (`id`=job id, not run id) |
| GET/POST | `/api/build/{path}/trigger` | Token | Trigger build |
| GET/POST | `/api/build/{path}/variables` | Token | Build parameters |
| GET/POST | `/api/build/{path}/branches` | Token | Git branch list |
| POST | `/api/build/{path}/scan-sync` | Token | Harbor scan sync |
| POST | `/api/build/{path}/commit-status` | Token | Commit status writeback |
| POST | `/api/build/{path}/report` | Token | Custom_Push CI result report |
| GET/POST | `/api/build/{path}/tag` | Token | Pipeline → tag query |
| GET/POST | `/api/build/{path}/tags` | Token | Pipeline → tag batch list |
| **Git** | | | |
| GET/POST | `/api/git/{path}/branches` | Token | Branch list |
| **Harbor** | | | |
| GET/POST | `/api/harbor/projects` | Token | Project list |
| GET/POST | `/api/harbor/{project}/repositories` | Token | Repository list |
| GET/POST | `/api/harbor/{project}/repositories/{repo}/tags` | Token | Tag list |
| POST | `/api/harbor/{project}/repositories/{repo}/tags/{tag}/scan` | Token | Trigger scan |
| GET | `/api/harbor/{project}/repositories/{repo}/tags/{tag}/scan` | Token | Scan report |
| **RBAC** (API token + rbac.user.write scope only) | | | |
| POST | `/api/rbac/users` | API Token | Create CD service account |
| PUT | `/api/rbac/users/{username}` | API Token | Update CD service account |
| DELETE | `/api/rbac/users/{username}` | API Token | Delete CD service account |
| GET | `/api/rbac/users` | API Token | CD service account list |
| GET | `/api/rbac/users/{username}` | API Token | CD service account detail |
| GET | `/api/rbac/roles` | API Token | Available role list |
| **OAuth2 / OIDC** | | | |
| GET/POST | `/oauth/authorize` | No* | Authorization-code flow: consent page / submit consent (*submission verifies account credentials, shares the login rate limiter) |
| POST | `/oauth/token` | No* | Exchange authorization code for access token (*validates client credentials and the one-time code) |
| GET/POST | `/oauth/userinfo` | access token | OAuth2 user info |
| GET | `/oauth/userinfo/emails` | access token | GitHub-style emails sub-endpoint (Grafana fallback) |
| GET | `/.well-known/openid-configuration` | No | OIDC discovery document |
| GET | `/.well-known/jwks.json` | No | OIDC signing public keys |

### 8.3 Database Migration Checklist

When adding/modifying table structures, update the following files and **bump `APP_VERSION`** (marks this version's schema changes; `schema_version` records the version already applied to the current database, and the migration runs only when the two differ):

- [ ] `config/AppConfig.php` → Add `TABLE_*` constant (new tables)
- [ ] `src/Service/Database.php` → `CREATE TABLE IF NOT EXISTS` in `ensureTables()`
- [ ] `src/Service/Database.php` → `$columnMigrations` map (new columns; back-fills existing databases)
- [ ] `database/mysql_init.sql`
- [ ] `database/sqlite_init.sql`
- [ ] `config/AppConfig.php` → Bump `APP_VERSION`

---

*Document version: v2.8.9 | Last updated: 2026-10-10*