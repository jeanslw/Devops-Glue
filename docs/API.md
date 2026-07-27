# Devops-Glue API Reference v2.3.2

Base URL: `http://your-domain.com/api`

All endpoints support both `GET` and `POST` unless otherwise noted (`trigger` and scan endpoints are `POST` only).

---

## Response Formats

| Parameter | Example | Content-Type |
|---|---|---|
| (default, no param) | `["java/registry","static"]` | `application/json` (raw array) |
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
| 404 | Resource not found |
| 500 | Server error (Jenkins/Harbor unreachable, etc.) |
| 503 | Service unavailable (Harbor scanner disabled, etc.) |

## CORS

All endpoints allow cross-origin access by default (`Access-Control-Allow-Origin: *`).

To restrict origins, edit `config/settings.php`:

```php
'cors' => [
    'allowed_origins' => ['https://your-frontend.com'],
],
```

---

## Health Check

```
GET /api/health
```

Returns connectivity status of Jenkins, Git platforms, and Harbor.

```json
{
  "status": "ok",
  "checks": {
    "jenkins": true,
    "git": [{"name": "gitlab", "reachable": true}],
    "harbor": true
  },
  "app_env": "production",
  "time": "2026-07-05 12:00:00"
}
```

- `status`: `ok` | `degraded`
- HTTP 200 (ok) / 503 (degraded)

---

## Main Module (`/api/main`)

### List Jobs
```
GET /api/main/jobs/list
```

Returns string array: `["java/registry", "php/myapp", "static"]`

### Job/Git/Harbor Mapping (grouped by project)
```
GET /api/main/map/list
```

Returns JSON object keyed by Git repository path:

```json
{
  "tools/registry": {
    "git_platform": "gitlab",
    "git_remote": "http://URL/tools/registry.git",
    "project_id": 2,
    "web_url": "http://your-gitlab/group/project",
    "current_path": "tools/registry",
    "harbor_repository": "mycode/code-runtime",
    "jobs": ["java/registry"]
  }
}
```

### Git Platforms (static config)
```
GET /api/main/git/platforms
```

### Platform Discovery (dynamic scan)
```
GET /api/main/git/discovery
```

---

## Build Module (`/api/build`) — v2.3.0

> Unified entry for Jenkins and GitLab CI. Legacy `/api/jenkins/*` routes are deprecated.

| Endpoint | Method | Description |
|---|---|---|
| `/api/build/{project}/trigger` | POST | Trigger build (JSON body: `{"variables":{"param":"value"}}`) |
| `/api/build/{project}/variables` | GET/POST | Build parameters / CI variables |
| `/api/build/{project}/branches` | GET/POST | Git branch list (plain string array) |
| `/api/build/{project}/pipelines` | GET/POST | Pipeline list (`?list=id\|build\|time\|success`) |
| `/api/build/{project}/pipelines/{id}` | GET/POST | Pipeline detail + Jobs |
| `/api/build/{project}/logs/{id}` | GET/POST | Build logs (text/plain) |
| `/api/build/{project}/pipelines/{id}/retry` | POST | Retry pipeline (GitLab CI only) |
| `/api/build/{project}/pipelines/{id}/cancel` | POST | Cancel pipeline (GitLab CI only) |
| `/api/build/{project}/scan-sync` | POST | Harbor scan sync (`{"tag":"v3.0.0"}`) |
| `/api/build/{project}/tag` | GET/POST | Pipeline → Tag mapping (`?pipeline=10`) |
| `/api/build/{project}/commit-status` | POST | Commit status write-back (security scans) |

### Commit Status Write-back

Parameters: `sha`, `state`, `context`, `description` (required); `target_url`, `check_type`, `tag` (optional).

Works with all Git providers (GitLab/GitHub/Gitee/Gitea), independent of CI system.

---

## Git Module (`/api/git`)

```
GET /api/git/{group}/{project}/branches
```

Returns: `["master","devops","main"]`

Supports GitLab, Gitee, and GitHub. Automatically resolves the Git repository from the job mapping.

---

## Harbor Module (`/api/harbor`)

| Endpoint | Method | Description |
|---|---|---|
| `/api/harbor/projects` | GET/POST | Project list |
| `/api/harbor/{project}/repositories` | GET/POST | Repository list |
| `/api/harbor/{project}/repositories/{repository}/tags` | GET/POST | Tag list (double-encode `/` as `%2F`) |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | POST | Trigger image scan |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | GET | Get scan report |

---

## Admin Module (`/api/admin`)

All admin endpoints require Bearer Token (obtained via `POST /api/admin/login`).

| Endpoint | Method | Description |
|---|---|---|
| `/api/admin/login` | POST | Login |
| `/api/admin/password` | PUT | Change password |
| `/api/admin/job_git_map` | GET/POST/PUT/DELETE | Mapping CRUD |
| `/api/admin/discover` | POST | Auto-discover Jenkins jobs |
| `/api/admin/security_checks` | GET | Security scan audit records |
| `/api/admin/platform_versions` | GET/PUT | Platform API version config |
| `/api/admin/build_mode` | GET/PUT | Build mode (jenkins/gitlab_ci/both) |
| `/api/admin/users` | GET/POST/PUT/DELETE | User management (v2.4.0) |

---

## Quick Test Commands

```bash
# Health check
curl "http://URL/api/health"

# Trigger build (POST JSON)
curl -X POST "http://URL/api/build/static/trigger" \
  -H "Content-Type: application/json" \
  -d '{"variables":{"branches":"master"}}'

# Query job variables
curl "http://URL/api/build/static/variables"

# Query Git branches
curl "http://URL/api/build/static/branches"

# Query三方映射
curl "http://URL/api/main/map/list"

# Query configured Git platforms
curl "http://URL/api/main/git/platforms"

# Query platform discovery
curl "http://URL/api/main/git/discovery"

# Harbor projects
curl "http://URL/api/harbor/projects"

# Harbor tags
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags"

# Trigger Harbor scan
curl -X POST "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"

# Get Harbor scan report
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"

# CORS preflight test
curl -X OPTIONS "http://URL/api/main/jobs/list" -H "Origin: http://example.com" -v
```
