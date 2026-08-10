# Devops-Glue API Reference v2.4

Base URL: `http://your-domain.com/api`

All endpoints support both `GET` and `POST` unless otherwise noted (`trigger` and scan endpoints are `POST` only).

---

## Response Formats

Use the `?format=` query parameter to control output:

| Parameter | Example | Content-Type |
|---|---|---|
| `?format=raw` (default) | `["java/registry","static"]` | `application/json` (raw array) |
| `?format=json` | `{"data":["java/registry","static"]}` | `application/json` |
| `?format=xml` | `<?xml...><root><item>java/registry</item></root>` | `application/xml` |

## Common Errors

```json
{
  "code": 400,
  "message": "Error description"
}
```

| Status | Meaning |
|---|---|
| 200 | Success |
| 204 | CORS preflight success |
| 400 | Bad request |
| 401 | Unauthorized (missing or invalid token) |
| 403 | Forbidden (insufficient permissions) |
| 404 | Resource not found |
| 500 | Server error (Jenkins/Harbor unreachable, etc.) |
| 503 | Service unavailable (Harbor scanner disabled, etc.) |

## CORS

All endpoints allow cross-origin access by default (`Access-Control-Allow-Origin: *`).

Allowed methods: GET, POST, PUT, DELETE, PATCH, OPTIONS.
Allowed headers: Content-Type, Authorization, X-Requested-With, Accept.

To restrict origins, edit `config/settings.php`:

```php
'cors' => [
    'allowed_origins' => ['https://your-frontend.com'],
],
```

---

## Public Endpoints (No Auth)

| Endpoint | Method | Description |
|---|---|---|
| `/api/health` | GET | Health check |
| `/api/i18n/{locale}` | GET | Get language pack (`zh_CN` or `en`) |
| `/api/docs` | GET | Swagger UI page (requires doc auth) |
| `/api/openapi.json` | GET | OpenAPI spec (requires doc auth) |
| `/api/admin/login` | POST | Login, returns Bearer token |
| `/api/admin/logout` | POST | Logout, revoke token |

---

## Health Check

```
GET /api/health
```

Returns connectivity status of Jenkins, Git platforms, and Harbor, plus system stats.

```json
{
  "status": "ok",
  "checks": {
    "jenkins": true,
    "jenkins_version": "2.504",
    "git": [{"name": "gitlab", "api_version": "v4", "reachable": true}],
    "harbor": true,
    "harbor_version": "v2",
    "harbor_components": {"core": true, "jobservice": true, "registry": true}
  },
  "stats": {"total_maps": 15, "active_maps": 12, "git_platforms": 2, "harbor_repos": 8},
  "build_mode": "both",
  "build_mode_source": "database",
  "db_driver": "mysql",
  "app_version": "v2.4.3",
  "app_env": "production",
  "time": "2026-08-10 12:00:00"
}
```

- `status`: `ok` | `degraded`
- `jenkins`: `true` / `false` / `null` (null = gitlab_ci mode, Jenkins not checked)
- `harbor`: `true` / `false` / `null` (null = Harbor not configured)
- HTTP 200 (ok) / 503 (degraded)

---

## Main Module (`/api/main`)

> **Auth:** All Main endpoints require Bearer Token (obtained via `POST /api/admin/login`). Add `Authorization: Bearer <token>` header.

### List Jobs
```
GET /api/main/jobs/list
```

Returns string array: `["java/registry", "php/myapp", "static"]`

In `gitlab_ci` mode, returns active job names from mapping. In other modes, returns all Jenkins jobs (falls back to mapping on error).

### Job/Git/Harbor Mapping (grouped by project, 30s cache)
```
GET /api/main/map/list
```

Returns JSON with `projects` keyed by job name (or current_path), plus platform URLs:

```json
{
  "projects": {
    "tools/registry": {
      "git_platform": "gitlab",
      "build_provider": "jenkins",
      "git_remote": "http://URL/tools/registry.git",
      "project_id": 2,
      "web_url": "http://your-gitlab/group/project",
      "harbor_repository": "mycode/code-runtime",
      "platform_source": "auto",
      "detection_method": "",
      "jobs": ["java/registry"]
    }
  },
  "jenkins_url": "http://your-jenkins:8080",
  "harbor_url": "http://your-harbor"
}
```

### Git Platforms (static config)
```
GET /api/main/git/platforms
```

Returns configured Git platforms and Harbor API info.

### Platform Discovery (dynamic scan)
```
GET /api/main/git/discovery
```

Returns configured and unconfigured Git platform list.

---

## Build Module (`/api/build`) — v2.3.0

> Unified entry for Jenkins and GitLab CI. Legacy `/api/jenkins/*` routes are deprecated.
>
> **Auth:** All Build endpoints require Bearer Token (obtained via `POST /api/admin/login`). Add `Authorization: Bearer <token>` header.

| Endpoint | Method | Description |
|---|---|---|
| `/api/build/jobs/list` | GET/POST | Build job list (raw: job name array; json: with provider info) |
| `/api/build/config-mode` | GET | Build config mode (`{mode, source, has_jenkins, has_gitlab_ci}`) |
| `/api/build/{path}/trigger` | GET/POST | Trigger build (JSON body: `{"ref":"","variables":{"param":"value"}}`) |
| `/api/build/{path}/variables` | GET/POST | Build parameters / CI variables (raw: param name array; json: full metadata) |
| `/api/build/{path}/branches` | GET/POST | Git branch list (plain string array) |
| `/api/build/{path}/pipelines` | GET/POST | Pipeline list (`?list=id\|build\|time\|success`) |
| `/api/build/{path}/pipelines/{id}` | GET/POST | Pipeline detail + Jobs |
| `/api/build/{path}/logs/{id}` | GET/POST | Build logs (text/plain) |
| `/api/build/{path}/pipelines/{id}/retry` | POST | Retry pipeline (GitLab CI only) |
| `/api/build/{path}/pipelines/{id}/cancel` | POST | Cancel pipeline (GitLab CI only) |
| `/api/build/{path}/scan-sync` | POST | Harbor scan sync (`{"tag":"v3.0.0"}`, tag optional = latest) |
| `/api/build/{path}/tag` | GET/POST | Pipeline -> Tag mapping (`?pipeline=10`) |
| `/api/build/{path}/commit-status` | POST | Commit status write-back (security scans) |

`{path}` is the job name or `current_path` from the job-git mapping.

### Trigger Build

POST JSON body (or GET query string as fallback for legacy Jenkins):
```json
{
  "ref": "master",
  "variables": {"param": "value"}
}
```
Body root-level keys (except `ref`, `variables`, `format`, `token`) are merged into variables automatically.

### Commit Status Write-back

Required: `sha`, `state` (`pending`/`success`/`failed`/`error`), `context`, `description`.
Optional: `target_url`, `check_type` (defaults to `context`), `tag`.

Works with all Git providers (GitLab/GitHub/Gitee/Gitea), independent of CI system. Also records to `ci_security_checks` table for audit.

### Scan Sync

Triggers Harbor vulnerability scan and writes result back to Git platform commit status (`harbor-scan` context). If `tag` is omitted, uses the latest tag from Harbor.

---

## Git Module (`/api/git`)

> **Auth:** All Git endpoints require Bearer Token (obtained via `POST /api/admin/login`). Add `Authorization: Bearer <token>` header.

```
GET /api/git/{path}/branches
```

`{path}` is the job name from the mapping. Returns: `["master","devops","main"]`

Supports GitLab, Gitee, GitHub, and Gitea. Automatically resolves the Git repository from the job mapping.

---

## Harbor Module (`/api/harbor`)

> **Auth:** All Harbor endpoints require Bearer Token (obtained via `POST /api/admin/login`). Add `Authorization: Bearer <token>` header.

| Endpoint | Method | Description |
|---|---|---|
| `/api/harbor/projects` | GET/POST | Project list |
| `/api/harbor/{project}/repositories` | GET/POST | Repository list |
| `/api/harbor/{project}/repositories/{repository}/tags` | GET/POST | Tag list (double-encode `/` as `%2F`) |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | POST | Trigger image scan |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | GET | Get scan report |

---

## Admin Module (`/api/admin`)

All admin endpoints (except `login` and `logout`) require Bearer Token (obtained via `POST /api/admin/login`).

### Login

```
POST /api/admin/login
```

Request body:
```json
{
  "user": "admin",
  "password": "your_password"
}
```

Response:
```json
{
  "token": "a1b2c3...(64-char hex)",
  "role": "admin",
  "user": "admin",
  "is_root": true,
  "permissions": ["ci.manage", "ci.users.manage", "..."]
}
```

Token expires in 24 hours. `super_admin` role returns `"*"` for permissions.

### Admin Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/admin/login` | POST | Login (public, no auth) |
| `/api/admin/logout` | POST | Logout, revoke token (public, no auth) |
| `/api/admin/password` | PUT | Change own password (requires old password) |
| `/api/admin/job_git_map` | GET | Mapping list (supports `?search=&platform=&provider=&page=&per_page=`) |
| `/api/admin/job_git_map` | POST | Add mapping |
| `/api/admin/job_git_map` | PUT | Update mapping (requires `_original_job_name`) |
| `/api/admin/job_git_map` | DELETE | Delete mapping (`?job_name=...`) |
| `/api/admin/discover` | POST | Auto-discover Jenkins jobs |
| `/api/admin/security_checks` | GET | Security scan audit records (supports `?project=&check_type=&state=&exclude=&page=&per_page=`) |
| `/api/admin/platform_versions` | GET/PUT | Platform API version config |
| `/api/admin/build_mode` | GET/PUT | Build mode (jenkins/gitlab_ci/both) |
| `/api/admin/users` | GET | User list (admin sees all; non-admin cannot see admin users) |
| `/api/admin/users` | POST | Create user (body: `username`, `password`, `role`, `systems`) |
| `/api/admin/users/{username}` | PUT | Update user (body: `password` and/or `role`) |
| `/api/admin/users/{username}` | DELETE | Delete user (cannot delete root account or self) |
| `/api/admin/roles` | GET | Role list |
| `/api/admin/roles` | POST | Create custom role (requires `ci.users.manage_admin`) |
| `/api/admin/roles/{id}` | PUT | Update role permissions (full replacement, requires `ci.users.manage_admin`) |
| `/api/admin/roles/{id}` | DELETE | Delete custom role (requires `ci.users.manage_admin`) |
| `/api/admin/permissions` | GET | Permission catalog + implied rules (requires `ci.permissions.list`) |
| `/api/admin/permissions` | POST | Register new permission (body: `perm_key`, `description`, `parent_key?`; requires `ci.permissions.register`) |
| `/api/admin/permissions/{perm_key}` | DELETE | Delete permission (builtin keys protected; requires `ci.permissions.register`) |
| `/api/admin/implied_rules` | POST | Create implied rule (body: `source_key`, `target_key`; requires `ci.permissions.rules`) |
| `/api/admin/implied_rules` | DELETE | Delete implied rule (`?source_key=&target_key=`; requires `ci.permissions.rules`) |
| `/api/admin/me/permissions` | GET | Get current user's permissions (super_admin returns wildcard `"*"`) |

### Permission Checks

- Creating/updating/deleting **admin** users requires `ci.users.manage_admin` permission
- Creating/updating custom roles requires `ci.users.manage_admin` permission
- Permission management requires corresponding `ci.permissions.*` permissions

---

## Quick Test Commands

```bash
# Health check (no auth required)
curl "http://URL/api/health"

# Login and get token
TOKEN=$(curl -s -X POST "http://URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"user":"admin","password":"your_password"}' | jq -r '.data.token')

# Trigger build (POST JSON, requires auth)
curl -X POST "http://URL/api/build/static/trigger" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"ref":"master","variables":{"branches":"master"}}'

# Query job variables (requires auth)
curl "http://URL/api/build/static/variables" \
  -H "Authorization: Bearer $TOKEN"

# Query Git branches via build endpoint (requires auth)
curl "http://URL/api/build/static/branches" \
  -H "Authorization: Bearer $TOKEN"

# Query Git branches via git endpoint (requires auth)
curl "http://URL/api/git/static/branches" \
  -H "Authorization: Bearer $TOKEN"

# Query mapping (requires auth)
curl "http://URL/api/main/map/list" \
  -H "Authorization: Bearer $TOKEN"

# Query configured Git platforms (requires auth)
curl "http://URL/api/main/git/platforms" \
  -H "Authorization: Bearer $TOKEN"

# Query platform discovery (requires auth)
curl "http://URL/api/main/git/discovery" \
  -H "Authorization: Bearer $TOKEN"

# Build job list (requires auth)
curl "http://URL/api/build/jobs/list" \
  -H "Authorization: Bearer $TOKEN"

# Build config mode (requires auth)
curl "http://URL/api/build/config-mode" \
  -H "Authorization: Bearer $TOKEN"

# Harbor projects (requires auth)
curl "http://URL/api/harbor/projects" \
  -H "Authorization: Bearer $TOKEN"

# Harbor tags (requires auth)
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags" \
  -H "Authorization: Bearer $TOKEN"

# Trigger Harbor scan (requires auth)
curl -X POST "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan" \
  -H "Authorization: Bearer $TOKEN"

# Get Harbor scan report (requires auth)
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan" \
  -H "Authorization: Bearer $TOKEN"

# Commit status write-back (requires auth)
curl -X POST "http://URL/api/build/static/commit-status" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"sha":"abc123","state":"success","context":"security-scan","description":"No vulnerabilities"}'

# CORS preflight test (no auth required)
curl -X OPTIONS "http://URL/api/main/jobs/list" -H "Origin: http://example.com" -v
```
