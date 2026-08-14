# Devops-Glue API FAQ v2.5.1

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

`docker-compose/docker-compose.yml` provides a full deployment setup with MySQL included.

```bash
docker build -t devops-glue .
docker run -d -p 8080:8080 \
  -v $(pwd)/config/.env:/var/www/html/config/.env \
  devops-glue
```

### Q: How to deploy CI + CD services together?

There are three deployment modes for the CI (devops-glue) and CD (devops-cd) services:

**1. Same host — single `docker-compose.yml` (recommended)**

`docker-compose/docker-compose.yml` already contains a commented `cd-service` block. Uncomment it:

```bash
cd docker-compose/
# Edit docker-compose.yml, uncomment the cd-service section (# cd-service: → cd-service:)
docker compose up -d
```

This launches CI, CD, and MySQL in one go. Both services connect to `devops-mysql` via Docker's internal DNS. The CD service's `.env` should set `DB_HOST=devops-mysql`.

**2. Same host — separate `docker-compose.yml` files**

Deploy CI and CD independently. They share MySQL, so you must create a shared network first:

```bash
# In the CI project
docker network create devops-net
cd docker-compose/
docker compose up -d

# In the CD project
# Ensure the CD's docker-compose.yml uses the same network:
#   networks:
#     default:
#       name: devops-net
#       external: true
docker compose up -d
```

The CD service's `.env` should set `DB_HOST=devops-mysql` (Docker DNS resolves across the shared network).

**3. Separate hosts**

When CI and CD run on different machines, MySQL must be accessible from outside Docker:

- CI host: in `docker-compose.yml`, expose MySQL port:
  ```yaml
  mysql:
    ports:
      - "3306:3306"
  ```
- CD host: in its `.env`, point to the CI host:
  ```env
  DB_HOST=<CI_HOST_IP>
  DB_PORT=3306
  DB_NAME=devops_glue
  DB_USER=root
  DB_PASS=<CI_MYSQL_ROOT_PASSWORD>
  ```
- Firewall: allow CD host access to CI host's port 3306

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

Devops-Glue passes branch info to Jenkins via build parameters. Your Jenkins job must be set up to accept them:

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

### Q: Duplicate entries appear in "Auto Discover" under `both` mode?

`both` mode scans both Jenkins and GitLab CI, so the same `repository URL` may produce two entries (one from each). After enabling one pipeline, the other is auto-hidden; reverting to "pending" status shows the duplicates again. This is expected behavior.

### Q: What is the "pending" mapping status?

Mappings imported by Auto Discover start in "pending" and must be manually activated (status becomes `active`) before they are used by the API and homepage. Manually added mappings can be activated directly.

### Q: Do I need to fill in the `api_version` mapping field?

No. `api_version` is metadata only; it does not affect actual API routing (routes are hardcoded in each Service). You can omit it.

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

### Q: What roles are available? (v2.4)

The system has a role-based access control (RBAC) system. **Everything is data-driven: permission keys and implied rules are stored in DB and fully manageable from the admin UI.** There is only one built-in system role:

| Role | Type | Editable | Description |
|---|---|---|---|
| `super_admin` | System | No | Full access to all CI and CD features, including user and role management |

All other roles are custom roles created by administrators. You can create any number of roles and assign permissions to them.

### Q: How are permissions organized?

Permissions are divided into **CI** and **CD** categories with a two-level hierarchy (parent/child menus):

**CI (8 permissions — no hierarchy):**
- Full Admin Access, User Management, Manage Admin Accounts
- Edit Mappings, Edit Platform Versions, Modify Build Mode
- Auto Discovery, Trigger Build

**CD Level 1 (8 permissions — top-level menus):**
- Build Management, Deploy Management, Server Management
- Web Shell, Deploy Records, Image Registry
- Resource Monitor, Notification Management

**CD Level 2 (9 permissions — sub-items):**
- Deploy: Single Machine, Docker, K8S
- Monitor: App Resources, System Resources, Custom Resources, Alert Rules
- Notification: Bot Config (`cd.bot`), WebHook Config (`cd.webhook`)

Total: **25 built-in permissions**. The permission system is fully data-driven. Adding a new CD menu permission can be done two ways:

- **Recommended (no code):** Admin UI → Permission Registration → fill in `perm_key` + `description` + `parent_key` → on Implied Rules page add a child→parent rule → done, not a single line of code changed
- **Persistent seed (with code):** Update `DEFAULT_PERMISSIONS` + `IMPLIED_PERMISSIONS` in `AppConfig.php` + i18n `role.perm_{key}` (dots become underscores) in both `lang/*/messages.php`, restart backend for auto-UPSERT

### Q: What are "implied permissions"?

Implied rules live in the dedicated `implied_rules` table (`source_key` → `target_key`), meaning "having A automatically grants B". Direction **MUST strictly follow**:

- ✅ **Child → Parent**: e.g. having `cd.bot` automatically shows the parent `cd.notification-manage` menu
- ❌ **Parent → Child (prohibited)**: adding this direction causes a reverse-dependency bug: "child permissions cannot be revoked while the parent menu is retained"

**Built-in implied rules:**
- All `cd.deploy.*` → `cd.deploy-manage`
- All `cd.monitor.*` → `cd.resource-monitor`
- `cd.bot` / `cd.webhook` → `cd.notification-manage`
- `cd.build-manage` → `ci.trigger` (Build Management implies Trigger Build — a special cross-module rule)

Implied rules can be added/removed dynamically via the Admin UI → Implied Rules page. No code changes required.

> **Note:** The **built-in implied rules above are seeded with the system and cannot be deleted** (shown with 🔒). Only rules added by a user via the Implied Rules page can be deleted.

### Q: What does the "Type" column (Built-in / Registered) in the permission list mean?

Permissions fall into two categories:

- **Built-in**: seeded by the system (`AppConfig::DEFAULT_PERMISSIONS`), required for the system to run, and **cannot be deleted** (shown with 🔒 and no delete button).
- **Registered**: added dynamically via "Permission Management → Permission Registration", and **can be deleted** (requires the `ci.permissions.register` permission on the current account).

### Q: Why is the "Registered At" column empty (`—`)?

Built-in permissions are written by the seed data and have no registration time (`created_at` is empty), so they show `—`. Only permissions added via "Permission Registration" have a real registration time. Re-registering the same permission key only updates the description/parent key and preserves the first registration time.

### Q: Why can't I see the delete button on a permission?

Both conditions must be met: ① the permission is "Registered" (built-in permissions cannot be deleted); ② the current account has the `ci.permissions.register` permission. Without it, even calling the delete endpoint directly returns 403.

> Deleting a registered permission cascades and cleans up related records in `role_permissions` and `implied_rules`.

### Q: How to create a custom role?

1. Log in with an account that has `ci.users.manage_admin` (typically `super_admin`)
2. Go to "Role Management"
3. Click "Create Role", enter a role name and description, check the permissions you want to grant
4. Save — the backend **auto-expands implied permissions** before writing; the role appears immediately in the role list and user creation dropdown
5. When updating later, any `permissions` array (even empty) passed in fully replaces the role's permission set

### Q: Why can't I edit or delete the super_admin role?

`super_admin` is a system role (`is_system=1`). System roles:
- Cannot be edited or deleted from the UI (locked with a padlock icon)
- Have `'*'` as their permission set (always includes all permissions — no need to list every key)
- Their description is rendered via i18n (`user.role_super_admin`), not stored in the database

### Q: Why does a role show `user.role_xxx` instead of a readable name?

The display name for system roles comes from i18n translation keys (`user.role_{name}`). If you see a raw key like `user.role_builder`, it means the translation is missing. Add the corresponding key in `lang/zh_CN/messages.php` and `lang/en/messages.php`. Custom roles use their `description` field from the database.

> **Permission display names** follow the same pattern: the permission picker reads Chinese labels from the `role.perm_{key}` (dots → underscores) i18n key. For permissions dynamically registered via Admin UI's "Permission Registration", the `description` field is used first, then falls back to the raw key if the i18n entry is missing.

### Q: What is `/api/admin/me/permissions`? (v2.4)

`GET /api/admin/me/permissions` returns the current user's role and permission list. It only requires a valid Bearer token — **no admin privileges needed** (this is exactly how the CD frontend retrieves permissions).

Response format:
```json
{
  "role": "deployer",
  "permissions": ["ci.trigger", "cd.build-manage"]
}
```

For `super_admin`, `permissions` is `"*"` (wildcard meaning all permissions). **For all other roles, the returned array is already the final implied-expanded set** — callers can just use `includes()`, no client-side expansion needed.

### Q: How does CD check user permissions?

The CD frontend calls `GET /api/admin/me/permissions` to get the current user's permissions, then checks if a given permission key is present. For example:
```js
var perms = await fetch('/api/admin/me/permissions').then(r=>r.json());
if (perms.permissions === '*' || perms.permissions.includes('cd.deploy.k8s')) {
    // show K8S deploy button
}
```

For CI's own admin panel (`admin.html`), the built-in helper `hasPermission('xxx')` does the same check.

### Q: CD added a new menu. What must I do to bring it under permission management?

**No code changes required.** As `super_admin`, just do two steps in the Admin UI:

1. **Permission Registration** → fill `perm_key` (e.g. `cd.your-new-menu`) + `description` + `parent_key` (for level-2 menus, point to the level-1 parent key)
2. **Implied Rules** → if it has a parent menu, add one rule `source_key=cd.your-new-menu, target_key=<parent_key>` (child→parent direction)

After that, the new permission appears in Role Edit screens and you can assign it to any role. Only when you also need a persistent DB seed or Chinese display label do you need to touch the code.

### Q: How to create a new admin account?

1. Log into the admin panel with a super_admin account
2. Go to "User Management" → "Create User"
3. Enter username, password, select a role
4. Only `super_admin` can manage users and roles

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

### Q: What does the "Write-back" column on the Security Audit page show?

It records the result of each Commit Status write-back:

| Status | Meaning |
|---|---|
| `success` | Write-back succeeded |
| `failed` | Write-back failed |
| `skipped` | Write-back skipped |
| (empty) | Historical record / unknown |

The result is stored in the `writeback_status` / `writeback_message` fields of `ci_security_checks` and shown on the "Security Audit" page. A failed write-back does not block the CI flow (it degrades and passes through).

### Q: What fields does a security audit record contain?

The `ci_security_checks` table records: `project`, `sha` (commit), `check_type` (scan type, defaults to `context`), `state` (result status), `context` (check name, e.g. `harbor-scan` / `secret-scan` / `sast` / `sca` / `iac-audit`), `description`, `tag`, `writeback_status` (write-back result), and `created_at`.

### Q: The "Status / Write-back" column shows English keys instead of Chinese?

This was an i18n gap in earlier versions, fixed in v2.5.1 (now translated lazily at render time). If you still see English keys, confirm your version is ≥ v2.5.1.

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

*Document version: v2.5.1 | Last updated: 2026-08-14*
