# Devops-Glue Technical Guide v2.4.0

> This document is intended for developers, operations engineers, and troubleshooting. It covers all business logic, data flows, database table structures, and common issues.

---

## Table of Contents

1. [System Architecture Overview](#1-system-architecture-overview)
2. [Request Lifecycle](#2-request-lifecycle)
3. [Configuration System](#3-configuration-system)
4. [Database Design](#4-database-design)
5. [Core Business Logic](#5-core-business-logic)
   - [5.1 Health Check](#51-health-check)
   - [5.2 Mapping Management (job_git_map)](#52-mapping-management)
   - [5.3 Dual-Channel Build System](#53-dual-channel-build-system)
   - [5.4 Scan Sync (scan-sync)](#54-scan-sync)
   - [5.5 Commit Status Writeback](#55-commit-status-writeback)
   - [5.6 Security Scan Audit](#56-security-scan-audit)
   - [5.7 Platform Version Management](#57-platform-version-management)
   - [5.8 Build Mode Switching](#58-build-mode-switching)
   - [5.9 Authentication & Authorization](#59-authentication--authorization)
6. [Key Data Flows](#6-key-data-flows)
7. [Common Troubleshooting](#7-common-troubleshooting)
8. [Appendix: Complete Configuration Reference](#8-appendix-complete-configuration-reference)

---

## 1. System Architecture Overview

```
┌──────────────────────────────────────────────────────┐
│                    HTTP Request                       │
└──────────┬───────────────────────────────────────────┘
           ▼
┌──────────────────────────────────────────────────────┐
│  public/index.php (Entry Point)                       │
│  ├─ Dotenv three-layer loading (.env→.env.{ENV}→.env.local) │
│  ├─ Static file serving                               │
│  ├─ Database::init() auto-create tables + seed data   │
│  └─ Slim 4 App + DI container assembly                │
└──────────┬───────────────────────────────────────────┘
           ▼
┌──────────────────────────────────────────────────────┐
│  Middleware Layer                                      │
│  ├─ CorsMiddleware (CORS)                             │
│  ├─ RoutingMiddleware (Route matching)                │
│  └─ ErrorMiddleware (Unified error handling)          │
└──────────┬───────────────────────────────────────────┘
           ▼
┌──────────────────────────────────────────────────────┐
│  Controller Layer                                     │
│  ├─ MainController   — Health check / map list / platform discovery │
│  ├─ BuildController  — Build trigger / Pipeline / Scan / CS │
│  ├─ AdminController  — Admin CRUD / Auth / mode switch │
│  ├─ GitController    — Branch query                    │
│  └─ HarborController — Registry query / Scan           │
└──────────┬───────────────────────────────────────────┘
           ▼
┌──────────────────────────────────────────────────────┐
│  Service Layer                                        │
│  ├─ Database          — SQLite/MySQL dual driver + migration │
│  ├─ MappingManager    — Mapping query / unified filtering │
│  ├─ AppConfig         — Config access facade          │
│  ├─ I18nService       — i18n (Symfony Translation)    │
│  ├─ HarborService     — Harbor API wrapper (v1/v2 detection) │
│  ├─ JenkinsService    — Jenkins API wrapper           │
│  ├─ GitlabCiBuildProvider — GitLab CI Build adapter   │
│  ├─ JenkinsBuildProvider  — Jenkins Build adapter     │
│  ├─ BuildProviderRegistry — Build Provider registry   │
│  ├─ Git/ProviderRegistry   — Git Provider registry    │
│  ├─ Git/GitlabService      — GitLab API adapter       │
│  ├─ Git/GithubService      — GitHub API adapter       │
│  ├─ Git/GiteeService       — Gitee API adapter        │
│  └─ Git/GiteaService       — Gitea API adapter        │
└──────────┬───────────────────────────────────────────┘
           ▼
┌──────────────────────────────────────────────────────┐
│  External Systems                                     │
│  ├─ Jenkins      (Build engine)                       │
│  ├─ GitLab CI    (Build engine)                       │
│  ├─ GitLab/GitHub/Gitee/Gitea (Source + Commit Status)│
│  └─ Harbor       (Registry + Vulnerability scan)      │
└──────────────────────────────────────────────────────┘
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
Loading order (later overrides earlier):
1. config/.env          ← Base config (gitignored, contains real passwords)
2. config/.env.{ENV}    ← Environment override (committed to Git, no passwords)
3. config/.env.local    ← Local override (gitignored, personal tweaks)

Override rules:
- Layer 1 uses createImmutable().load() (safe load, won't overwrite existing env vars)
- Layers 2/3 use createUnsafeImmutable().load() (allows overwrite)
```

**Practical usage:**

| APP_ENV | Files Loaded | Notes |
|---|---|---|
| production | `.env` only | Default behavior, no `.env.production` needed |
| staging | `.env` → `.env.staging` → `.env.local` | `.env.staging` usually only has `APP_DEBUG=true` |
| development | `.env` → `.env.development` → `.env.local` | Same as above |

### 3.2 Runtime Configuration Persistence

| Config Item | Storage Location | Priority |
|---|---|---|
| `build_mode` | `ci_app_settings` table | DB > `.env` (fallback) |
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

#### ci_pipeline_tags (Pipeline ↔ Tag Mapping)

| Field | Type | Description |
|---|---|---|
| `project` | TEXT NOT NULL (PK) | Project path |
| `pipeline_iid` | INTEGER NOT NULL (PK) | Pipeline internal ID |
| `tag` | TEXT NOT NULL | Image tag |
| `harbor_repository` | TEXT | Harbor repository name (e.g., mycode/app) |
| `status` | TEXT DEFAULT '' | Scan status (success/failed/pending/unknown) |
| `created_at` | DATETIME/TEXT | Creation time |

**Indexes:** `(project)`, `(created_at)`

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

**Existing settings:** `build_mode` (jenkins/gitlab_ci/both)

#### admin_users (Admin Accounts)

| Field | Type | Description |
|---|---|---|
| `username` | TEXT PK | Username |
| `password_hash` | TEXT NOT NULL | bcrypt hash |
| `role` | TEXT DEFAULT 'admin' | admin / super_admin |
| `updated_at` | DATETIME/TEXT | Update time |

**Initialization:** `seedAdmin()` creates initial user from `.env` `ADMIN_USER`/`ADMIN_PASSWORD` when `admin_users` table is empty.

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
| `created_at` | DATETIME/TEXT | Record time |

**Indexes:** `(project, check_type)`, `(sha)`

### 4.2 Database Sync Rules

⚠️ **Important:** Table structure changes must be updated in four places simultaneously:

1. `config/AppConfig.php` → Add a `TABLE_*` constant
2. `src/Service/Database.php` → `ensureTables()` method
3. `database/mysql_init.sql`
4. `database/sqlite_init.sql`

---

## 5. Core Business Logic

### 5.1 Health Check

**Route:** `GET /api/health`  
**Controller:** `MainController::health()`

**Check Process:**

```
1. Jenkins check (only in jenkins/both mode)
   ├─ Calls getAllJobs() to verify connectivity
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
  "build_mode": "both", "build_mode_source": "database",
  "db_driver": "mysql", "app_version": "2.4.0", "app_env": "production",
  "time": "2026-07-25 12:00:00"
}
```

---

### 5.2 Mapping Management

**Core table:** `ci_job_git_map`  
**Admin endpoints:** `/api/admin/job_git_map` (GET/POST/PUT/DELETE)  
**Public endpoint:** `/api/main/map/list` (grouped by Git repository)

#### 5.2.1 Data Writing

- **Manual add/edit:** Admin UI → AdminController CRUD
- **Auto-discovery:** `POST /api/admin/discover` → `AutoDiscover::discover()` scans all Jenkins jobs, parses SCM config to extract Git URLs → `saveDiscovered()` writes to `ci_job_git_map`

#### 5.2.2 Data Reading & Grouping

`MainController::mapList()` logic:

1. Read `ci_job_git_map` → filter `status=active` → filter by `build_mode`
2. Group by key extracted from `job_name` or `current_path` or `git_remote`
3. Each group contains: `git_platform`, `git_remote`, `project_id`, `web_url`, `harbor_repository`, `jobs`
4. Write to `cache` table (30s TTL) to reduce repeated query overhead
5. Skip cache in `gitlab_ci` mode (to avoid stale Jenkins data contamination)

#### 5.2.3 MappingManager's Role

All business-layer queries must go through `MappingManager`:
- `activeMaps()` — returns all active mappings under current `build_mode`
- `resolveProject(path)` — resolves `[provider, projectId]` from project path
- `usedGitPlatforms()` — collects Git platforms used in active mappings
- `activeJobNames()` — returns list of active job names

#### 5.2.4 Git Platform Auto-Detection

When `git_platform` is not explicitly specified, the system auto-detects:

1. Extract domain from `git_remote` URL
2. Match using each `GitProvider::matchUrl()` (keywords: gitlab/github.com/gitee.com/...)
3. If no match → fall back to `DEFAULT_GIT_PLATFORM` env variable

---

### 5.3 Dual-Channel Build System

**Modes:** jenkins / gitlab_ci / both  
**Unified route entry:** `/api/build/{path}/...`

#### 5.3.1 Project Resolution

First step of all Build operations is `resolve(path)`:

```
MappingManager::resolveProject(path)
├─ Iterates ci_job_git_map, matching job_name or current_path
├─ Returns build_provider (jenkins/gitlab_ci)
└─ Returns projectId (jenkins=path, gitlab_ci=project_id or path)
```

#### 5.3.2 BuildProvider Interface

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

#### 5.3.3 Trigger Build

**Route:** `POST /api/build/{path}/trigger`

Parameter merge priority: POST body root-level > POST body `variables` nested > Query String  
If only `ref` is present without other parameters, auto-convert to `{branches: ref}`.

#### 5.3.4 Pipeline List

**Route:** `GET /api/build/{path}/pipelines?list=id|build|time|success`

`list` parameter options:
- `id` — returns `[10, 9, 8]` all pipeline numbers
- `success` — same but only successful ones
- `build` — returns `["#10", "#9"]` successful pipelines
- `time` — returns `["#10 [2026-07-25]", "#9 [...]]"` success + timestamp
- (not provided) — returns full JSON (with build_provider, project_id, pipelines array)

#### 5.3.5 Failure Points

| Issue | Cause | Symptom |
|---|---|---|
| `Build system 'xxx' not configured` | build_mode inconsistent with actual Provider availability | 400 |
| Project resolution failed | No mapping in `ci_job_git_map` | Falls back to path itself as projectId |
| Jenkins 504 timeout | `BUILD_TIMEOUT` too small or network slow | 500 |

---

### 5.4 Scan Sync (scan-sync)

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
7. Record to ci_pipeline_tags (project + pipeline_iid + tag + status)
```

**⚠️ Key Constraints:**
- `harbor_repository` must be configured (otherwise 400)
- `tag` must actually exist in Harbor (strict validation; downgrades to pass if Harbor unreachable)
- `harbor_repository` format must be `project/repo` (two segments separated by `/`)

---

### 5.5 Commit Status Writeback

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
5. Record to ci_pipeline_tags (when tag is provided)
6. Write to ci_security_checks audit table (UPSERT, unique by project+sha+check_type)
7. Return result (with git_platform, commit_status sub-object)
```

**Commit status writeback is independent of the CI system** — it only depends on Git platform configuration. Jenkins projects can also write back commit status through this endpoint.

---

### 5.6 Security Scan Audit

**Route:** `GET /api/admin/security_checks?project=&check_type=&state=&page=1&per_page=20`  
**Method:** `AdminController::securityChecksList()`

**Data source:** `ci_security_checks` table (written by commit-status and scan-sync)

**Filter Parameters:**
- `project` — fuzzy match (LIKE %...%)
- `check_type` — exact match
- `state` — exact match (success/failed/pending/error)
- `page` / `per_page` — pagination

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
    "states": ["success", "failure", "pending", "error"]
  }
}
```

**⚠️ Note:** The frontend `STATE_ICONS` uses `failed` rather than `failure` (GitHub Commit Status API convention), because the backend `validStates` and database actually store `failed`.

---

### 5.7 Platform Version Management

**Route:** `GET/PUT /api/admin/platform_versions`  
**Method:** `AdminController::platformVersionsList()` / `platformVersionsUpdate()`

**Read Priority (three-tier fallback):**
1. `ci_platform_versions` table (user-customized)
2. `platform_versions.json` (JSON file, deprecated but compatible)
3. Per-Provider hardcoded defaults (e.g., GitLab=v4, Gitee=v5, GitHub=v3)

**Additional field in response:** `configured` — indicates whether the platform's `base_url` is configured (non-empty)

---

### 5.8 Build Mode Switching

**Route:** `GET /api/admin/build_mode` + `PUT /api/admin/build_mode`  
**Method:** `AdminController::getBuildMode()` / `updateBuildMode()`

**Storage:** `ci_app_settings` table, key=`build_mode`, value ∈ {jenkins, gitlab_ci, both}

**Read Logic (AppConfig::getBuildMode()):**
```
1. Read ci_app_settings WHERE setting_key='build_mode'
2. Has value → return, source='database'
3. No value → read .env BUILD_MODE, seed to DB → return, source='env'
4. DB exception → fall back to .env, source='env'
```

**Write Validation (frontend & backend consistent):**
- `mode` must be jenkins / gitlab_ci / both
- Setting `jenkins` or `both` → requires JENKINS_BASE_URL configured (otherwise 400)
- Setting `gitlab_ci` or `both` → requires GITLAB_BASE_URL + GITLAB_TOKEN configured (otherwise 400)

**DI Container Behavior (container.php):**
- No longer decides Provider registration based on `BUILD_MODE`
- Jenkins / GitLab CI each check their own config non-empty to register
- Actual routing dispatch uses `MappingManager::activeMaps()` filtered by DB `build_mode`

**Impact scope:** Mode switching only affects mapping list filtering and Jenkins check in health check.

---

### 5.9 Authentication & Authorization

#### 5.9.1 Login

**Route:** `POST /api/admin/login`  
**Method:** `AdminController::login()`

**Verification Priority:**
1. Query `admin_users` table, `password_verify()` to check bcrypt hash
2. Fallback: compare `.env` `ADMIN_USER`/`ADMIN_PASSWORD` (requires password non-empty)
3. Auth success → generate 64-char hex token → write to `cache` table (`admin_token_{token}`, 24h TTL)

#### 5.9.2 Token Verification

**Method:** `AdminController::authCheck()`

```
1. Extract token from Authorization: Bearer xxx
2. Query cache table to verify token (key=admin_token_{token}, expires_at > now)
3. If DB unavailable AND .env ADMIN_PASSWORD empty → allow (first-start no-password scenario)
4. If DB unavailable AND admin_users table empty → allow
5. Otherwise → 401
```

**On password change:** `DELETE FROM cache WHERE cache_key LIKE 'admin_token_%'` — clears all old tokens, forces re-login.

#### 5.9.3 Docs Page Authentication

**Swagger UI (`/api/docs`) and OpenAPI JSON (`/api/openapi.json`):**
- Uses routes.php closure `$checkAuth`, independent of `AdminController::authCheck`
- If `.env` `ADMIN_PASSWORD` is empty → allow directly
- Otherwise verify token (supports Bearer or Query String `?token=`)

#### 5.9.4 RBAC Permission System (v2.4.1)

The system uses a role-based access control (RBAC) model with three database tables:

| Table | Purpose |
|---|---|
| `roles` | Role definitions: `id`, `name`, `description`, `is_system`, `created_at` |
| `permissions` | Permission catalog: `id`, `key` (dot-delimited, e.g. `cd.deploy.k8s`), `parent_key`, `description`, `created_at` |
| `role_permissions` | Many-to-many join: `role_id`, `permission_key` |

**System role:**
- `super_admin` is the only built-in system role (`is_system=1`) — cannot be edited or deleted from the UI
- It uses `'*'` as a wildcard permission set (defined in `AppConfig::DEFAULT_ROLES`)
- The root user from `seedAdmin()` defaults to `super_admin`

**Permission catalog (23 total):**

| Category | Count | Keys |
|---|---|---|
| CI | 8 | `ci.manage`, `ci.users.manage`, `ci.users.manage_admin`, `ci.mapping.edit`, `ci.platform.edit`, `ci.mode.edit`, `ci.discover`, `ci.trigger` |
| CD Level 1 | 8 | `cd.build-manage`, `cd.deploy-manage`, `cd.server-manage`, `cd.webshell`, `cd.deploy-record`, `cd.image-registry`, `cd.resource-monitor`, `cd.notification-manage` |
| CD Level 2 | 7 | `cd.deploy.single`/`docker`/`k8s`, `cd.monitor.app`/`system`/`custom`/`alert` |

**Implied permissions** (`AppConfig::IMPLIED_PERMISSIONS` + frontend `IMPLIED_PERMISSIONS`):
- `cd.build-manage` → `ci.trigger` (Build Management implies Trigger Build)

**Database seeding:**
- Built-in roles and permissions are seeded in `ensureTables()` via `REPLACE INTO`
- `DEFAULT_PERMISSIONS` in `AppConfig.php` stores canonical English names (UI uses i18n keys for display)
- When adding a new permission: insert into `permissions` table + add i18n keys in both lang files

**API endpoint** (`v2.4.1`):
- `GET /api/admin/me/permissions` — returns current user's role and permissions (valid token only, no admin required)
- `super_admin` returns `permissions: "*"` (wildcard); other roles return an array of permission keys

**Permission check logic** (`hasPermission($permKey)`):
```php
if ($role === 'super_admin') return true;  // hardcoded bypass
// Otherwise: JOIN roles → role_permissions → permissions and check
```

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
            └─ → ci_pipeline_tags record tag→pipeline mapping
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
| Startup error: `DB_DRIVER must be sqlite or mysql` | `.env` not configured or incorrect | `echo $DB_DRIVER`, verify value |
| SQLite: `unable to open database` | Directory lacks write permission | `chmod 777 config/data/` |
| MySQL: `Access denied` | Wrong password or insufficient user privileges | `mysql -u root -p` to verify |
| Table not found error | `DB_AUTO_MIGRATE=false` and tables not manually created | Check `.env` `DB_AUTO_MIGRATE` value |
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
| Login always 401 | Password mismatch between `admin_users` and `.env` | Clear `admin_users` table to rebuild, or use temporary reset route |
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
BUILD_MODE=both                    # jenkins / gitlab_ci / both (initial seed value)
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

# ============ Application ============
APP_ENV=production                 # production / staging / development
APP_DEBUG=false
APP_LOCALE=zh_CN                   # Default locale (zh_CN / en)
API_BASE_URL=http://127.0.0.1:8080 # Swagger / OpenAPI external address
LOG_PATH=/applogs/                 # Log directory
```

### 8.2 Complete Route Table

| Method | Route | Auth | Description |
|---|---|---|---|
| GET | `/` | No | Homepage HTML |
| GET | `/admin` | No | Admin page HTML |
| GET | `/api/health` | No | Health check |
| GET | `/api/docs` | Yes | Swagger UI |
| GET | `/api/openapi.json` | Yes | OpenAPI 3.0 spec |
| GET | `/api/i18n/{locale}` | No | i18n language pack |
| **Main** | | | |
| GET/POST | `/api/main/jobs/list` | No | Job list |
| GET/POST | `/api/main/map/list` | No | Three-party mapping (30s cache) |
| GET/POST | `/api/main/git/platforms` | No | Git platform list |
| GET/POST | `/api/main/git/discovery` | No | Platform access detection |
| **Admin** | | | |
| POST | `/api/admin/login` | No | Login to get token |
| PUT | `/api/admin/password` | Token | Change password |
| GET | `/api/admin/user/list` | Token | User list |
| POST | `/api/admin/user/create` | Token | Create user (super_admin only) |
| DELETE | `/api/admin/user/delete` | Token | Delete user (super_admin only) |
| GET | `/api/admin/job_git_map` | Token | Mapping list (search/pagination) |
| POST | `/api/admin/job_git_map` | Token | Add mapping |
| PUT | `/api/admin/job_git_map` | Token | Update mapping |
| DELETE | `/api/admin/job_git_map` | Token | Delete mapping |
| GET | `/api/admin/platform_versions` | Token | Platform version list |
| PUT | `/api/admin/platform_versions` | Token | Update platform version |
| POST | `/api/admin/discover` | Token | Auto-discover projects |
| GET | `/api/admin/security_checks` | Token | Security scan list (filter/pagination) |
| GET | `/api/admin/build_mode` | Token | Get build mode |
| PUT | `/api/admin/build_mode` | Token | Update build mode |
| GET | `/api/admin/me/permissions` | Token | Get current user permissions |
| **Build** | | | |
| GET/POST | `/api/build/jobs/list` | No | Job list (with ci_provider) |
| GET | `/api/build/config-mode` | No | Build mode status |
| GET/POST | `/api/build/{path}/pipelines` | No | Pipeline list |
| GET/POST | `/api/build/{path}/pipelines/{id}` | No | Pipeline details + jobs |
| POST | `/api/build/{path}/pipelines/{id}/retry` | No | Retry pipeline (GitLab CI only) |
| POST | `/api/build/{path}/pipelines/{id}/cancel` | No | Cancel pipeline (GitLab CI only) |
| GET/POST | `/api/build/{path}/logs/{id}` | No | Build logs |
| GET/POST | `/api/build/{path}/trigger` | No | Trigger build |
| GET/POST | `/api/build/{path}/variables` | No | Build parameters |
| POST | `/api/build/{path}/scan-sync` | No | Harbor scan sync |
| POST | `/api/build/{path}/commit-status` | No | Commit status writeback |
| GET/POST | `/api/build/{path}/tag` | No | Pipeline → tag query |
| **Git** | | | |
| GET/POST | `/api/git/{path}/branches` | No | Branch list |
| **Harbor** | | | |
| GET/POST | `/api/harbor/projects` | No | Project list |
| GET/POST | `/api/harbor/{project}/repositories` | No | Repository list |
| GET/POST | `/api/harbor/{project}/repositories/{repo}/tags` | No | Tag list |
| POST | `/api/harbor/{project}/repositories/{repo}/tags/{tag}/scan` | No | Trigger scan |
| GET | `/api/harbor/{project}/repositories/{repo}/tags/{tag}/scan` | No | Scan report |

### 8.3 Database Migration Checklist

When adding/modifying table structures, simultaneously update the following files:

- [ ] `config/AppConfig.php` → Add `TABLE_*` constant
- [ ] `src/Service/Database.php` → `ensureTables()` method
- [ ] `database/mysql_init.sql`
- [ ] `database/sqlite_init.sql`

---

*Document version: v2.4.1 | Last updated: 2026-07-30*
