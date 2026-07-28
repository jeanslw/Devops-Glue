# Devops-Glue FAQ v2.4.0

## Table of Contents

- [Getting Started](#getting-started)
- [Configuration & Startup](#configuration--startup)
- [Build & CI](#build--ci)
- [Mapping Management](#mapping-management)
- [Authentication & Permissions](#authentication--permissions)
- [Harbor & Security Scanning](#harbor--security-scanning)
- [Database & Performance](#database--performance)
- [Git Platform Integration](#git-platform-integration)
- [Internationalization (i18n)](#internationalization-i18n)

---

## Getting Started

### Q: What are the runtime requirements?

- **PHP 8.0+** with extensions: `pdo_sqlite` or `pdo_mysql`, `curl`, `json`
- Database: SQLite (out of the box) or MySQL 8.0+ / MariaDB 10.4+
- With Docker, no manual dependency installation is needed

### Q: How do I get started quickly?

```bash
cp config/.env.example config/.env
# Edit .env — at minimum, configure one Git platform URL and Token
composer install --no-dev
php -S 0.0.0.0:8080 -t public/
```

Open `http://localhost:8080` in your browser. Default credentials come from `ADMIN_USER` / `ADMIN_PASSWORD` in `.env`.

### Q: How to deploy with Docker?

```bash
docker build -t devops-glue .
docker run -d -p 8080:8080 \
  -v $(pwd)/config/.env:/var/www/html/config/.env \
  devops-glue
```

`docker-compose/docker-compose.yml` provides a full deployment setup with MySQL included.

### Q: Why is the homepage empty?

You need to configure mapping relationships in the admin panel (`/admin`). After first deployment, visit `/admin`, log in, and click "Auto Discover" or manually add mappings.

---

## Configuration & Startup

### Q: What does "three-layer .env loading" mean?

Loading order (later overrides earlier):
1. `config/.env` — Base config (gitignored, contains real passwords)
2. `config/.env.{APP_ENV}` — Environment override (e.g., `.env.production`)
3. `config/.env.local` — Local override (gitignored, personal tweaks)

`APP_ENV=production` only loads `.env`; `.env.production` is not needed.

### Q: Why don't .env changes take effect?

- Some config (e.g., `build_mode`, mapping data) is persisted to the database on first boot, and the DB takes precedence thereafter
- Runtime configuration can be modified in the admin panel instead of editing `.env`
- If you must reload from `.env`, delete the corresponding row in `ci_app_settings` and restart

### Q: Error: "DB_DRIVER must be sqlite or mysql"?

`DB_DRIVER` in `.env` is missing or misspelled. Make sure the value is exactly `sqlite` or `mysql`.

### Q: SQLite error: "unable to open database"?

The `config/data/` directory lacks write permissions:

```bash
# Linux / macOS
chmod 777 config/data/

# Docker
docker exec -it <container> chmod 777 /var/www/html/config/data/
```

### Q: How to switch from SQLite to MySQL?

1. Update `.env` with `DB_DRIVER=mysql` and MySQL connection details
2. If you have existing SQLite data, manual migration is needed (no automatic migration between drivers)
3. Tables are auto-created in MySQL on first boot (`DB_AUTO_MIGRATE=true`)

---

## Build & CI

### Q: Which CI systems are supported?

**Jenkins** and **GitLab CI**. Switch `build_mode` in the admin panel:

- `jenkins` — Jenkins only
- `gitlab_ci` — GitLab CI only
- `both` — Both available simultaneously

### Q: build_mode switch doesn't take effect?

Check if the `build_provider` field of each mapping item matches the actual build system. The `build_provider` field in the mapping table determines which CI system handles that project.

### Q: Error: "Build system 'xxx' not configured"?

The selected `build_mode` requires environment variables that aren't configured:
- `jenkins` / `both` → requires `JENKINS_BASE_URL`
- `gitlab_ci` / `both` → requires `GITLAB_BASE_URL` + `GITLAB_TOKEN`

### Q: Build trigger timeout (504)?

Jenkins build triggers have a timeout limit, defaulting to 300 seconds (`BUILD_TIMEOUT`). Increase this value if Jenkins responds slowly.

### Q: Pipeline list is empty?

- Jenkins: verify the Job exists and has run at least once
- GitLab CI: verify the project path and permissions are correct
- Confirm the `job_name` or `current_path` in the mapping table matches reality

### Q: How does concurrent Jenkins + GitLab CI support work?

The system abstracts both through the `BuildProvider` interface. Each record in `ci_job_git_map` specifies its `build_provider`, and API calls are automatically routed to the corresponding build engine.

### Q: How to configure a Jenkins job to work with parameterized Git branches?

passes branch info to Jenkins via build parameters. Your Jenkins job must be set up to accept them:

1. **Install Git Parameter plugin** in Jenkins (if not already installed)
2. In the job configuration, add a **Git Parameter** build parameter:
   - Name: e.g., `branch` or `git_branch`
   - Parameter Type: `Branch`
   - Default Value: `main`
3. Under **Source Code Management → Git → Branches to build**, set:
   - Branch Specifier: `${branch}` (your parameter name)
4. In the Git Parameter **Advanced** settings:
   - Branch Filter: `origin/(.*)`

This tells Jenkins to accept any branch passed via the build parameter, rather than building a fixed branch. When devops-glue triggers a build, it passes the selected branch as the parameter value.

### Q: Trigger build fails with "branch not found"?

- Ensure the Git Parameter plugin is installed and configured in the Jenkins job
- Verify the parameter name in the Jenkins job matches what devops-glue passes
- Check that the Branch Filter regex is correct: `origin/(.*)` matches all remote branches

---

## Mapping Management

### Q: What is a "mapping"?

A mapping is the relationship between a Jenkins Job / GitLab CI project, a Git repository, and a Harbor registry. The core table is `ci_job_git_map`, editable through the admin UI.

### Q: What do I need to fill in when manually adding a mapping?

| Required | Description |
|---|---|
| Job Name | Jenkins job name or GitLab CI full project path |
| Git Platform | gitlab / github / gitee / gitea |
| Build Provider | jenkins / gitlab_ci |
| Git Remote URL | Repository HTTP/SSH address |
| Git Repo Path | e.g., `tools/my-project` |
| Harbor Repository | Format `project_name/repo_name` |

### Q: What does "Auto Discover" do?

Scans all projects in the current build mode, auto-extracts Git repository information, and imports them into `ci_job_git_map` with one click.
- **Jenkins mode**: scans all Jobs, parses SCM configuration to extract Git info
- **GitLab CI mode**: scans all project repositories via GitLab API
- **both mode**: scans both

### Q: Can't find a matching mapping for a project?

- Verify `job_name` or `current_path` exactly matches the actual value
- Verify the mapping status is `active`
- Verify `build_mode` is correct (e.g., `jenkins` mode won't return mappings with `build_provider=gitlab_ci`)

### Q: Why don't mapping changes take effect immediately?

The mapping list has a 30-second cache (`cache` table, key=`map_list_{mode}`). Wait 30 seconds for auto-refresh, or switch to `gitlab_ci` mode (which skips caching).

---

## Authentication & Permissions

### Q: What are the default credentials?

`.env` values for `ADMIN_USER` / `ADMIN_PASSWORD`. The user is created automatically on first boot and written to the `admin_users` table; thereafter the DB takes precedence.

### Q: Login always returns 401?

The password in `.env` and the password in `admin_users` table are out of sync.

**Solution:**
- Delete all rows from the `admin_users` table and restart — the system will recreate the user from `.env`
- Or change the password through the admin panel (if you can still log in)

### Q: How to obtain and renew tokens?

The login endpoint `POST /api/admin/login` returns a 64-character hex token valid for 24 hours. Re-login after expiration. Changing the password invalidates all existing tokens.

### Q: What's the difference between admin and super_admin?

| Role | Permissions |
|---|---|
| `super_admin` | Full access, including user management |
| `admin` | All management features, but **cannot manage other users** |

The root user (created by `seedAdmin`) defaults to `super_admin`.

### Q: How to create a new admin account?

1. Log into the admin panel with a super_admin account
2. Go to "User Management" → "Create User"
3. Enter username, password, select role `admin`
4. Only `super_admin` can create users

### Q: Can't access Swagger UI (/api/docs)?

The docs page requires authentication. Visiting `/api/docs` auto-redirects to the login page. If `ADMIN_PASSWORD` in `.env` is empty, access is granted directly.

---

## Harbor & Security Scanning

### Q: What's the scan-sync process?

1. Retrieve the vulnerability scan report for a specified tag (or the latest tag) from Harbor
2. Write the scan result back to the Git platform's Commit Status (context: `harbor-scan`)
3. Record in the `ci_pipeline_tags` table (tag → pipeline mapping)

### Q: scan-sync reports "tag not found in Harbor"?

- Verify the tag was actually pushed to Harbor
- Tag names in Harbor are case-sensitive
- If Harbor is unreachable, the system downgrades and passes through (doesn't block CI)

### Q: What's the Harbor repository format?

`harbor_repository` must be `project_name/repo_name` (two segments separated by `/`). Example: `myteam/myapp`.

### Q: Harbor scan shows "pending"?

Possible causes:
- Harbor has no vulnerability scanner configured (Trivy, Clair, etc.)
- Scan is in progress
- Harbor service is unreachable

### Q: What security scans are supported besides Harbor scanning?

Through the Commit Status endpoint (`POST /api/build/{path}/commit-status`), any scan step in CI pipeline can write results back:

- **Secret scanning**: Gitleaks, TruffleHog (context: `secret-scan`)
- **SAST**: SonarQube, Semgrep (context: `sast`)
- **Dependency scanning**: Trivy, Snyk (context: `sca`)
- **IaC auditing**: Checkov, tfsec (context: `iac-audit`)

All scan records are stored centrally in the `ci_security_checks` audit table.

---

## Database & Performance

### Q: What to note when changing table structures?

⚠️ **Must update in four places simultaneously:**
1. `config/AppConfig.php` → Add a `TABLE_*` constant for the new table
2. `src/Service/Database.php` → `ensureTables()` method (auto-migration logic)
3. `database/mysql_init.sql` → MySQL manual init script
4. `database/sqlite_init.sql` → SQLite manual init script

Adding a new table (e.g., `ci_new_table`) involves:
1. Define `TABLE_NEW_TABLE = 'ci_new_table'` in `AppConfig.php`
2. Add `CREATE TABLE IF NOT EXISTS` in `ensureTables()`, referencing the constant
3. Add the same `CREATE TABLE` statement to both `mysql_init.sql` and `sqlite_init.sql`

Existing columns or indexes added via `ALTER TABLE` / `CREATE INDEX` in `ensureTables()` don't need the manual scripts updated, but new tables always do.

### Q: What data is cached and for how long?

| Cache Item | TTL |
|---|---|
| Mapping list (`map_list_{mode}`) | 30 seconds |
| Admin Token (`admin_token_{token}`) | 24 hours |
| Harbor API version detection (`harbor_api_version`) | 1 hour |

### Q: Health check `/api/health` is slow?

The default timeout for external services is 3-5 seconds. Slow external services can make the overall health check exceed 3 seconds.

**Solution:** Check network connectivity and verify all configured `BASE_URL` values are reachable.

### Q: MySQL connection fails / Access denied?

- Verify `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` are correct
- Ensure the MySQL user has table creation privileges (when `DB_AUTO_MIGRATE=true`)
- In Docker, note that `127.0.0.1` inside the container points to the container itself, not the host

### Q: "Table not found" error?

`DB_AUTO_MIGRATE=false` and you haven't run the init scripts manually. Run `database/mysql_init.sql` or `database/sqlite_init.sql`, or set `DB_AUTO_MIGRATE=true`.

---

## Git Platform Integration

### Q: How is git_platform auto-detected?

The domain from the `git_remote` URL is matched against keywords (gitlab, github.com, gitee.com, etc.). If auto-detection fails, manually specify `git_platform` in the mapping.

### Q: Which Git platforms are supported?

GitLab, GitHub, Gitee, Gitea, with support for custom extensions.

### Q: How to integrate a self-hosted GitLab/Gitea?

Configure the platform's `BASE_URL` and `TOKEN` in `.env`:

```ini
GITLAB_BASE_URL=https://gitlab.yourcompany.com
GITLAB_TOKEN=glpat-xxxx
GITEA_BASE_URL=https://git.yourcompany.com
GITEA_TOKEN=xxxx
```

Set `git_platform` to `gitlab` or `gitea` in the mapping.

### Q: Why doesn't Gitee support Commit Status?

Gitee's public API does not support the Commit Status endpoint (returns 405). Consider Gitee Enterprise or another platform for this feature.

### Q: When does `DEFAULT_GIT_PLATFORM` take effect?

When the `git_remote` URL cannot be recognized by any registered Provider, it falls back to `DEFAULT_GIT_PLATFORM`.

### Q: What does "Git 运行时连通性" (Git Runtime Health) actually monitor?

The health check panel displays **Git runtime connectivity**, not integration health. It answers one narrow question: "can the system reach the API endpoints of the Git platforms currently referenced by existing mappings?"

The check flow:

```
ci_job_git_map.git_platform (e.g., "gitee")
        ↓
Config match → api_base_url (e.g., "https://gitee.com/api/v5")
        ↓
HEAD request → HTTP 200 = reachable
```

**What it checks:** Network connectivity to the Git platform API endpoint.

**What it does NOT check:**
- Token validity / authentication status
- Whether the actual repository exists
- Whether webhooks are properly configured
- Whether Jenkins can clone the repository

The three possible statuses:

| Status | Meaning | Display |
|---|---|---|
| `null` (no mapping references any platform) | No runtime reference — skip check | ⚪ 无运行时引用 |
| `true` (HEAD succeeds) | API endpoint is reachable | ✅ API 可达 |
| `false` (HEAD fails) | API endpoint is unreachable | ❌ API 不可达 |

If no `ci_job_git_map` records reference a Git platform, the check is skipped with "无运行时引用 / No Runtime Reference" — this does **not** mean the platform is unconfigured, only that no mapping is currently using it.

---

## Internationalization (i18n)

### Q: How to switch languages?

- **UI**: Language selector in the top-right corner (`中文 / English`)
- **URL parameter**: `?lang=zh_CN` or `?lang=en`
- **Browser**: Auto-detects the `Accept-Language` request header
- **Storage**: Frontend remembers the choice via `localStorage('dg_lang')`

### Q: Which languages are supported?

Chinese (`zh_CN`) and English (`en`).

### Q: How to add a new language?

1. Add the language code to `SUPPORTED_LOCALES` in `config/AppConfig.php`
2. Create a new language directory under `lang/` (e.g., `lang/ja/`)
3. Translate all keys in `messages.php`
4. Add the corresponding language pack to `public/assets/i18n.js`

### Q: Does i18n affect the API?

No. API responses always return raw data. i18n only affects the frontend UI and controller error messages.

---

*Document version: v2.4.0 | Last updated: 2026-07-28*
