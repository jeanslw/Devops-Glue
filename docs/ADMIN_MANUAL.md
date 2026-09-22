# Devops-Glue API Admin Manual v2.8.0

> This manual is organized in a "from zero to usable" order, covering the installation, initialization, and full configuration of Devops-Glue API. Once you complete it in order, you will be able to: log in to the admin panel, connect CI / Git / Harbor platforms, configure build mode and mapping, manage permissions and roles, issue API tokens, and have the companion Devops-Glue CD call it correctly.

## Table of Contents

1. [Overview](#1-overview)
2. [Prerequisites](#2-prerequisites)
3. [Get the Code and Install Dependencies](#3-get-the-code-and-install-dependencies)
4. [Configure Environment Variables](#4-configure-environment-variables)
5. [Prepare the Database](#5-prepare-the-database)
6. [Start the Service](#6-start-the-service)
7. [First Boot and Auto-Initialization](#7-first-boot-and-auto-initialization)
8. [Log In to the Admin Panel](#8-log-in-to-the-admin-panel)
9. [Connect CI Platforms](#9-connect-ci-platforms)
10. [Connect Git Platforms](#10-connect-git-platforms)
11. [Connect Harbor](#11-connect-harbor)
12. [Configure Build Mode](#12-configure-build-mode)
13. [Configure Mapping](#13-configure-mapping)
14. [Permissions and Roles (RBAC)](#14-permissions-and-roles-rbac)
15. [Create API Tokens](#15-create-api-tokens)
16. [Connect Devops-Glue CD](#16-connect-devops-glue-cd)
17. [Verify Everything Works](#17-verify-everything-works)

Appendices:

- [Appendix A: Environment Variables Reference](#appendix-a-environment-variables-reference)
- [Appendix B: CORS Configuration](#appendix-b-cors-configuration)
- [Appendix C: Multi-Environment Deployment](#appendix-c-multi-environment-deployment)
- [Appendix D: Custom Git Platform Integration](#appendix-d-custom-git-platform-integration)
- [Appendix E: Single Sign-On (OAuth2 / OIDC)](Single-Sign-On.md)

---

## 1. Overview

Devops-Glue API is a Slim4-based unified API layer that provides a single management entry point for Jenkins / GitLab CI pull-based builds plus Custom_Push push-based builds (theoretically supporting all CI), GitLab / Gitee / GitHub / Gitea multi-platform code, and the Harbor image registry — covering the full CI-to-CD flow. It is a CI enhancement component; the complete system requires the companion deployment service [Devops-Glue CD](https://gitee.com/jeanslw/devops_cd).

| Devops-Glue API | Devops-Glue CD |
|:---:|:---:|
| v2.8.5 | v1.5.x |
| v2.8.4 | v1.5.x |
| v2.8.3 | v1.5.x |
| v2.8.2 | v1.5.x |
| v2.8.1 | v1.5.x |
| v2.8.0 | v1.5.x |
| v2.7 | v1.5 |
| v2.6 | v1.4 |
| v2.5 | v1.3 |
| v2.4 | v1.2 |

> Version correspondence: Devops-Glue API v2.8.x maps to CD v1.5.1 (v2.8.1 is a security-fix release), v2.7 maps to CD v1.5, v2.6 maps to CD v1.4, v2.5 maps to CD v1.3, and v2.4 maps to CD v1.2. You can view each platform's API version on the admin panel's "Platform Versions" page.

---

## 2. Prerequisites

| Component | Version requirement |
|---|---|
| PHP | 8.1+ (the Docker image uses 8.3) |
| Database | SQLite (default) / MySQL 8.0+ / MariaDB 10.4+ |
| Jenkins | v2.60+ |
| GitLab | v9.0+ (API v4) |
| Harbor | v1.10.1 / v2.x |

> **Note:** Jenkins / GitLab / Harbor URLs must be reachable from the Devops-Glue container.

---

## 3. Get the Code and Install Dependencies

```bash
# 1. Clone
git clone https://github.com/jeanslw/Devops-Glue.git
cd Devops-Glue

# 2. Install dependencies
composer install
```

---

## 4. Configure Environment Variables

```bash
cp config/app.env.example config/app.env
```

Edit `config/app.env` and fill in at least the following key items:

- **CI system**: `JENKINS_BASE_URL` / `JENKINS_USER` / `JENKINS_TOKEN`
- **Git platform**: `GITLAB_BASE_URL` / `GITLAB_TOKEN` (plus `GITHUB_*` / `GITEE_*` / `GITEA_*` as needed)
- **Harbor**: `HARBOR_BASE_URL` / `HARBOR_USER` / `HARBOR_PASSWORD`
- **Admin panel**: `ADMIN_USER` / `ADMIN_PASSWORD` (creates the root admin account on first boot)
- **Database**: `DB_DRIVER` / `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` (for MySQL); Docker Compose additionally requires `DB_ROOT_PASS` (MySQL root password, used only on first-time volume init)
- **Reverse proxy**: `TRUSTED_PROXY_HOPS` (set 1 behind a reverse proxy such as nginx, 0 for direct access — see Appendix A)

See [Appendix A](#appendix-a-environment-variables-reference) for the full list and descriptions.

---

## 5. Prepare the Database

Two databases are supported, selected by `DB_DRIVER`:

- `DB_DRIVER=mysql`: Docker Compose auto-starts MySQL 8.4 and creates the database. MariaDB 10.4+ is also fully supported. Fill in `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_ROOT_PASS` in the root `app.env`. `DB_PASS` and `DB_ROOT_PASS` **have no defaults — when either is empty the `env-check` preflight container prints bilingual (Chinese/English) guidance and aborts startup (the mysql container never runs)**: the root account is separated from the application account (`MYSQL_USER`, `devops` by default), and the application connects as the application account. These passwords take effect only on first initialization of the mysql volume; upgrading with an existing volume requires creating the account manually (see the SQL in `docker-compose.yml` comments) or rebuilding the volume after backup.
- `DB_DRIVER=sqlite`: Data file at `config/data/data.db`, no extra database service required. MySQL/MariaDB is recommended for production.

`DB_AUTO_MIGRATE` controls table creation:

- `true` (default): auto-create tables and seed data on first boot.
- `false`: manually run the init scripts under `database/` (`mysql_init.sql` or `sqlite_init.sql`); seed data is still applied on boot.

---

## 6. Start the Service

**Option 1: Docker Compose (recommended)**

```bash
docker compose up -d --build
```

**Option 2: PHP built-in server**

```bash
php -S 0.0.0.0:8080 -t public/
```

The service listens on `http://localhost:8080` after startup.

---

## 7. First Boot and Auto-Initialization

On first boot the system automatically performs the following initialization:

1. **Create tables**: creates all tables (permissions, roles, mapping, users, API tokens, etc.) when `DB_AUTO_MIGRATE=true`.
2. **Seed data**: writes built-in permissions, implied rules, and the system role `super_admin`.
3. **Root admin account**: reads `ADMIN_USER` (default `admin`) and `ADMIN_PASSWORD` from `app.env` and creates a `super_admin` account with full permissions.
4. **Build mode seed value**: reads `BUILD_MODE` (default `jenkins,gitlab_ci,gitea_ci`; the legacy value `both` is mapped to `jenkins,gitlab_ci`).

> When `ADMIN_PASSWORD` is not set, it is treated as first-time initialization and no admin account is created; set the password in `app.env` before logging in.

---

## 8. Log In to the Admin Panel

Open `http://localhost:8080/admin` and log in with `ADMIN_USER` / `ADMIN_PASSWORD` from `app.env`.

- Switch the UI language (Chinese / English) at the top right.
- Change your password under "User Management → Change Password".
- Admin authentication is validated against the database first; the `app.env` password only serves as a fallback when the database is totally inaccessible (disaster recovery) or the `admin_users` table is empty (first deployment). For a forgotten password, use an offline patch — contact the author to obtain it.

The admin panel sidebar contains the following modules:

| Module | Description |
|---|---|
| Monitor | Data overview and service status |
| Mapping | Job ↔ Git ↔ Harbor mapping configuration |
| Security Audit | SAST / secret / dependency vulnerability scan results and write-back records |
| Platform Versions | API versions of each connected platform |
| Build Mode | Build mode (jenkins / gitlab_ci / gitea_ci, multi-select) |
| User Management | User list, role management, change password |
| Permission Management | Permission list, permission registration, implied rules |
| API Management | Create / revoke / delete API tokens (only shown with the required permission) |
| Operation Log | Admin operation audit log (paginated + filterable) |
| System Settings | Platform access status (read-only) + database schema info & manual migration |

### 8.1 External LDAP / AD Logins (Optional)

Besides built-in accounts, the system can validate logins against a corporate LDAP / Active Directory server (since v2.6.3). When enabled, the login order is: **local `admin_users` → LDAP → `app.env` fallback**.

- **Prerequisite**: PHP must have the `ldap` extension enabled (`extension=ldap` in `php.ini`). If it is missing, the LDAP provider is skipped automatically and local account logins keep working unchanged.
- **Two connection modes (pick one)**:
  - *Search mode* (typical for AD): set `LDAP_BIND_DN` + `LDAP_BIND_PASSWORD` + `LDAP_BASE_DN` + `LDAP_USER_FILTER`. The service binds with the service account first, searches for the user's DN using the filter, then validates the user's own password against that DN.
  - *Direct bind mode*: set `LDAP_USER_DN_PATTERN`, e.g. `uid=%s,ou=users,dc=example,dc=com`. No directory search is performed — the DN is built from the pattern and validated directly. Best for well-structured directory layouts.
- **Transport security (pick one)**: `LDAP_USE_TLS=true` upgrades the plaintext connection to TLS (STARTTLS) after connecting on the default port 389; `LDAP_USES_LDAPS=true` connects over `ldaps://` instead (typically port 636).
  - ⚠️ **LDAPS with a self-signed certificate**: the PHP OpenLDAP client defaults to `TLS_REQCERT=demand`, requiring the server certificate to verify against a trusted CA. Containers / bare-metal setups ship **without any CA trust configuration**, so LDAPS against a self-signed certificate fails the TLS handshake (reported as `ldap_connect_failed`, easily misdiagnosed as a network problem). Pick one fix:
    1. Inject the CA cert path into the runtime environment: `LDAPTLS_CACERT=/path/to/ca.pem` (for Docker, mount the ca.pem into the container and set `LDAPTLS_CACERT` in `config/app.env` — php-fpm runs with `clear_env=no` so it is passed through);
    2. Or add `TLS_CACERT /path/to/ca.pem` to `/etc/ldap/ldap.conf` inside the container (persist it by bind-mounting the file).
    Setting `LDAPTLS_REQCERT=never` skips verification for ad-hoc internal testing but removes man-in-the-middle protection — forbidden in production.
- **Accounts must be pre-bound**: LDAP only proves *who you are*. To sign in, the username you type must already be mapped to your LDAP DN in the `user_identities` table (`provider_type='ldap'`, `provider_uid` = the user DN). Otherwise login is rejected with `auth.ldap_not_bound` — accounts are **never auto-created**. Binding example:

```sql
-- MySQL
INSERT INTO user_identities (username, provider_type, provider_uid, email, bound_at, updated_at)
VALUES ('zhangsan', 'ldap', 'uid=zhangsan,ou=users,dc=example,dc=com', 'zhangsan@example.com', NOW(), NOW());
-- SQLite
INSERT INTO user_identities (username, provider_type, provider_uid, email, bound_at, updated_at)
VALUES ('zhangsan', 'ldap', 'uid=zhangsan,ou=users,dc=example,dc=com', 'zhangsan@example.com', datetime('now'), datetime('now'));
```

  Authorization (role, allowed systems, email, etc.) always follows the matching `admin_users` row — LDAP is only the identity source. After a successful LDAP login the stored email and latest LDAP attributes are refreshed automatically; an account disabled in the admin panel (`status=0`) cannot sign in through LDAP either.
- **Failure semantics**: a failed bind (wrong password / unknown user) is reported as a generic credentials error. If the LDAP server itself is unreachable (connection failure, missing extension, …), login falls through to the `app.env` fallback, exactly like the disaster-recovery path — the system stays usable.
- **Security**: usernames are escaped before being interpolated into `LDAP_USER_FILTER` / `LDAP_USER_DN_PATTERN`, so it is safe to embed user input in these templates.

Environment variables: see [Appendix A](#appendix-a-environment-variables-reference).

---

## 9. Connect CI Platforms

Devops-Glue integrates CI in two complementary ways, so **theoretically all CI systems are supported**: built-in pull-based (Jenkins and GitLab CI) and push-based Custom_Push (any CI script can report build results).

**Jenkins** — fill in `app.env`:

```ini
JENKINS_BASE_URL=http://your-jenkins:8080
JENKINS_USER=admin
JENKINS_TOKEN=your_token
```

**GitLab CI** — reuses the GitLab platform configuration (see next step).

After configuration, verify the connection on the "Monitor" page.

---

## 10. Connect Git Platforms

Fill in each platform's `BASE_URL` and `TOKEN` in `app.env` as needed:

```ini
GITLAB_BASE_URL=http://your-gitlab
GITLAB_TOKEN=your_token

GITHUB_BASE_URL=https://api.github.com
GITHUB_TOKEN=your_token

GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_token

GITEA_BASE_URL=http://your-gitea
GITEA_TOKEN=your_token

DEFAULT_GIT_PLATFORM=gitlab   # Fallback when URL cannot be auto-detected
```

- The system auto-detects the platform from repository URL keywords; self-hosted GitLab/Gitea domains often lack platform keywords and fall back to `DEFAULT_GIT_PLATFORM`, so explicit configuration is recommended.
- To integrate a non-built-in platform, see [Appendix D](#appendix-d-custom-git-platform-integration).

---

## 11. Connect Harbor

Fill in `app.env`:

```ini
HARBOR_BASE_URL=http://your-harbor
HARBOR_USER=admin
HARBOR_PASSWORD=your_password
```

Harbor is used to associate build artifacts (image repositories) and to trigger image scans in the security audit.

> **About robot accounts:** Devops-Glue calls the Harbor REST API via HTTP Basic Auth, so the account must be usable for the REST API:
>
> | Harbor version | Can a robot account call the REST API? |
> |---|---|
> | v1.x / v2.0.x / v2.1.x | ❌ No. The robot token is a JWT (legacy) and only works with Docker/Helm CLI |
> | v2.2.0+ | ✅ Yes. The robot account uses a secret, so Basic Auth works against the REST API |
>
> - **Harbor ≥ 2.2.0**: `HARBOR_USER` may be `robot$xxx`, with `HARBOR_PASSWORD` set to the secret shown when creating the robot account.
> - **Harbor < 2.2.0**: use a **normal account** (username/password) for `HARBOR_USER` / `HARBOR_PASSWORD`.
>
> The admin "Platform API Version" page detects the concrete Harbor version in real time and clearly shows whether robot accounts are supported.

---

## 12. Configure Build Mode

On the "Build Mode" page, select the enabled CI sources with checkboxes (multi-select; only configured CIs are shown):

- `jenkins`: Jenkins
- `gitlab_ci`: GitLab CI
- `gitea_ci`: Gitea Actions

The stored value is a comma-separated set in the database (e.g. `jenkins,gitlab_ci,gitea_ci`). `BUILD_MODE` in `app.env` is only a first-boot seed; afterwards the admin panel is authoritative. The legacy single value `both` is mapped to `jenkins,gitlab_ci` on read.

### Gitea Actions prerequisites

`gitea_ci` uses **Gitea Actions** as the CI backend. To run a Gitea-hosted project end-to-end (trigger → pipeline/logs → artifact tag), the following are required:

- **Workflow file**: the repository must contain a workflow with a `workflow_dispatch` trigger (e.g. `.gitea/workflows/build.yml`) for manual triggering. Without it, `trigger` returns a "workflow required" message; `push`-triggered workflows still run and are read back normally.
- **Runner available**: the Gitea server must have Actions enabled (`[actions] ENABLED=true`) and at least one runner online.
- **Push image to Harbor**: Gitea Actions exposes no "artifact tag" API. The build-artifact tag association into `ci_pipeline_artifacts` uses the **Harbor scan-sync** flow — identical to Jenkins / GitLab CI. The workflow must push its image to Harbor, then call `POST /api/build/{owner/repo}/scan-sync` with `{ "tag", "pipeline_iid", "sha" }` (`pipeline_iid`/`sha` optional; when omitted, Glue falls back to the latest run and latest Harbor tag).
- **`harbor_repository` configured**: the mapping must set `harbor_repository` to `project/repo` form, otherwise `scan-sync` returns 400.

### Pull-based CI vs Push-based CI

Devops-Glue supports two orthogonal CI paradigms:

- **Pull-based CI**: controlled by the enabled CI source set (`jenkins` / `gitlab_ci` / `gitea_ci`). Devops-Glue actively queries Jenkins / GitLab CI / Gitea Actions to pull build status, logs, and image tags.
- **Push-based CI**: controlled by the independent boolean switch `custom_push_enabled`. Users trigger builds in their own CI scripts and push build status, log URLs, and image tags back to Devops-Glue. Devops-Glue only stores build metadata and log URL pointers — it **does not participate in build execution** and **does not store log content**.

The two are independent and can be enabled simultaneously (e.g. `jenkins + custom_push`).

> **Semantics note:** Push-based CI reuses the `/api/build/{path}/trigger` endpoint, but the endpoint's exact meaning is determined by the mapping's `build_provider` — a given `{path}` is bound to exactly one provider at a time, so the scenarios never conflict:
>
> | `build_provider` | `/trigger` semantics | Data direction |
> |---|---|---|
> | `jenkins` | Devops-Glue actively calls Jenkins to trigger a build | Outbound (Devops-Glue → Jenkins) |
> | `gitlab_ci` | Devops-Glue actively calls GitLab to trigger a pipeline | Outbound (Devops-Glue → GitLab) |
> | `custom_push` | User CI reports the **build result** (not a build trigger) | Inbound (CI → Devops-Glue) |
>
> The push-based flow uses a single endpoint: `report` (write the terminal build result + image tag, needs `build.report` scope).

### Image-tag association, log fallback, and backfill

The "Image Tag" column of pull records is determined in this order:

1. **Primary source (Harbor scan-sync writeback)**: after the artifact is pushed to Harbor, the scan writeback success (`ci_security_checks` rows where `check_type='harbor-scan'` and `writeback_status='success'`) yields `sha→tag` directly.
2. **Log fallback (log-derived tag)**: when the primary source misses, Devops-Glue fetches the build log and extracts the tag by the configured **log keyword** + this project's `harbor_repository` path (`repo:tag` or `-t/--tag`), **preferring the tag whose trailing number equals the current build number** (to avoid picking the previous build's tag). The result is cached in `ci_pipeline_build_log` (`source='log'`) so subsequent lists hit the cache without re-fetching logs.

The related settings live on the **System Settings → Platform Config** page:

- **Log keyword** (`tag_log_keyword`): the keyword used to identify a successful push and locate the tag, default `digest`; supports `|`-separated multiple keywords (e.g. `digest|pushed`), any hit counts as a push log; empty means no keyword filtering.
- **Enable stale-tag cleanup** (`stale_tag_cleanup_enabled`): periodically removes tags from `ci_pipeline_artifacts` that no longer exist in Harbor (Harbor is the source of truth; an unreachable/unconfigured Harbor is skipped safely — never a wrong delete).
- **Image-tag log backfill** (`backfill_tag_enabled`): periodically promotes log-derived tags into the canonical `ci_pipeline_artifacts` — **only after Harbor explicitly confirms the tag exists**, and only filling gaps where no tag exists yet; it never overwrites the authoritative scan-sync result. Off by default.

Both cron jobs are driven by the container's supervisord sleep-loop (no system cron needed); their intervals are overridable via the root `app.env` vars `TAG_CLEANUP_INTERVAL` (default 3600s) / `TAG_BACKFILL_INTERVAL` (default 1800s). **On bare metal** (no supervisord), schedule the two CLIs with system cron instead — see the "Bare-metal Deployment" section in the architecture doc.

> If a record's log-derived tag was parsed incorrectly (e.g. it picked up the previous build's tag), the **↻ Re-parse** button next to that tag force-re-parses it (skips the cache and re-extracts by the current build number).

### Enabling Custom_Push

No code changes needed — works out of the box:

1. On the "Build Mode" page, check the **Custom_Push** checkbox. It is independent of the build-mode checkboxes (`jenkins` / `gitlab_ci` / `gitea_ci`).
2. Register a provider in the `build.custom_providers` array of `config/settings.php`.

Configuration example:

```php
'build' => [
    'custom_providers' => [
        [
            'name'   => 'custom_push',
            'class'  => 'App\\Service\\Build\\CustomPushBuildProvider',
            'config' => [
                'variables' => [
                    'env'       => ['type' => 'choice', 'choices' => ['dev', 'staging', 'prod'], 'description' => 'Target environment for the built image (optional)', 'required' => false],
                ],
            ],
        ],
    ],
],
```

- `CustomPushBuildProvider` implements `BuildProviderInterface` and is auto-discovered and registered on startup.
- `variables` defines the custom variables allowed in reports; extra JSON keys outside the control fields are stored in `variables_json`.
- Build records are stored in the new `ci_custom_builds` table with `(job_name, pipeline_iid)` as the unique key; `pipeline_iid` must be an integer.
- Duplicate reports with the same `(job_name, pipeline_iid)` overwrite (UPDATE) the existing record.

### Reporting flow (single report)

Push-based CI reports the build result in **one call** — the terminal status and (on success) the image tag are written together:

| Endpoint | When | Required fields |
|---|---|---|
| `POST /api/build/{path}/report` | After the build (and image artifact) completes | `pipeline_iid` + `status` + `finished_at` |

> **Required pillars (missing any of them → 400):**
> - `pipeline_iid`: the correlation key, hard-validated (400 if missing).
> - `status`: the terminal build result (`success` / `failed` / `aborted`), hard-validated (400 if missing or not terminal).
> - `finished_at`: the build completion time, hard-validated (400 if missing).
> - `tag`: the image tag — required when `status=success`, hard-validated (400 if missing on success).

`{path}` may be either the mapping's `job_name` or `current_path`; both normalize to `job_name` (the mapping primary key). All JSON keys use lowercase snake_case. See [Push-name normalization](#push-name-normalization) for the keying rule.

#### `POST /api/build/{path}/report` — Report terminal result

Called after the build completes (or is aborted); writes the terminal state directly (no `pending`/`running` intermediate states). Requires an API token with the `build.report` scope.

| Field | Required | Type / values | Description |
|---|---|---|---|
| `pipeline_iid` | ✅ | integer | Pipeline ID; combined with `job_name` it forms the unique key |
| `status` | ✅ | `success` / `failed` / `aborted` | Terminal build result |
| `finished_at` | ✅ | timestamp string | Build completion time |
| `started_at` | | timestamp string | Start time (optional) |
| `ref` | | string | branch or ref |
| `sha` | | string | commit SHA |
| `exit_code` | | int | Exit code |
| `log_url` | | URL | Log URL pointer — Devops-Glue only stores the link; it does not fetch or store log content |
| `web_url` | | URL | Pipeline web entry |
| `tag` | ✅ (success) | string | Image tag — required when `status=success`; written to `ci_pipeline_artifacts` |
| `harbor_repository` | — | string | Harbor repo — resolved from `job_git_map` (body value ignored); must be `project/repo`; both repo and `tag` verified to actually exist in Harbor on report (400 if either missing) |
| (custom variables) | | — | Any other JSON keys are stored in `variables_json` (e.g. `env`) |

> **Tag write semantics:** when `status=success`, `tag` and a resolvable `harbor_repository` are mandatory, and `ci_pipeline_artifacts` is written (`provider`, `project_id`, `pipeline_iid`, `project_key`, `repository`, `tag`, `status`), read by the deployment (CD) layer. Non-successful builds never write a tag, so the deployment system never picks up a failed build's tag.

### Push-name normalization

`custom_push` uses the mapping's **`job_name` (the mapping primary key — the stable identity across `build_provider` switches) as the canonical local record key**. Whichever name the user CI pushes, the backend normalizes it to `job_name`:

| Pushed `{path}` | Stored key (`ci_custom_builds.job_name`) |
|---|---|
| `job_name` (recommended) | `job_name` |
| `current_path` | `job_name` (normalized) |

- Pushing `job_name` and pushing `current_path` land on the **same record**, so Jenkins naming differences don't produce two split records.
- `ci_pipeline_artifacts.project_key` (the deployment layer's delivery source) is also written to the normalized `job_name`, keeping it consistent with `ci_custom_builds.job_name`.
- **Why not use `current_path` as the key**: when converting Jenkins to custom_push, `job_name != current_path`; keying on `current_path` would split the same project into `java/registry` and `tools/registry` when it is later switched back to Jenkins.
- If the mapping's `job_name` is empty (normally impossible — it's the primary key), it falls back to `current_path`.

#### Converting Jenkins to custom_push

Converting a Jenkins mapping (`build_provider=jenkins`) to custom_push (`build_provider=custom_push`) is supported. **Keep `job_name` unchanged** (it's the mapping primary key and the identity anchor when switching back to Jenkins); you only need to:

1. Change `build_provider` to `custom_push`;
2. Confirm no other `custom_push` mapping exists for that Git project, to avoid duplicates.

Do not rewrite `job_name` to match `current_path` — that breaks identity continuity when switching back to Jenkins; backend normalization already ensures pushes with `current_path` land under `job_name`.

### Reporting example

```bash
BASE="http://localhost:8080/api/build/tools/runner-ci"
TOKEN="<API token with build.report scope>"

# Report the terminal result (and image tag) in one call
curl -X POST "$BASE/report" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"pipeline_iid": 12345, "status": "success", "finished_at": "2026-08-18 18:05:30", "started_at": "2026-08-18 18:00:00", "ref": "master", "sha": "a3f8c1d2", "exit_code": 0, "tag": "master-a3f8c1d2", "env": "staging"}'
```

**Python (`requests`)**

```python
import requests

BASE = "http://localhost:8080/api/build/tools/runner-ci"
TOKEN = "<API token with build.report scope>"

resp = requests.post(
    f"{BASE}/report",
    headers={"Authorization": f"Bearer {TOKEN}", "Content-Type": "application/json"},
    json={
        "pipeline_iid": 12345,
        "status": "success",
        "finished_at": "2026-08-18 18:05:30",
        "started_at": "2026-08-18 18:00:00",
        "ref": "master",
        "sha": "a3f8c1d2",
        "exit_code": 0,
        "tag": "master-a3f8c1d2",
        "env": "staging",
    },
)
resp.raise_for_status()  # raises on non-2xx
print(resp.json())
```

**Java (JDK 11+ `java.net.http`, no third-party deps)**

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class ReportBuild {
    public static void main(String[] args) throws Exception {
        String base  = "http://localhost:8080/api/build/tools/runner-ci";
        String token = "<API token with build.report scope>";
        // text block requires JDK 15+; on JDK 11 use string concatenation
        String payload = """
            {
              "pipeline_iid": 12345,
              "status": "success",
              "finished_at": "2026-08-18 18:05:30",
              "started_at": "2026-08-18 18:00:00",
              "ref": "master",
              "sha": "a3f8c1d2",
              "exit_code": 0,
              "tag": "master-a3f8c1d2",
              "env": "staging"
            }
            """;

        HttpRequest request = HttpRequest.newBuilder()
            .uri(URI.create(base + "/report"))
            .header("Authorization", "Bearer " + token)
            .header("Content-Type", "application/json")
            .POST(HttpRequest.BodyPublishers.ofString(payload))
            .build();

        HttpResponse<String> response = HttpClient.newHttpClient()
            .send(request, HttpResponse.BodyHandlers.ofString());

        System.out.println(response.statusCode());
        System.out.println(response.body());
    }
}
```

**Go (`net/http`)**

```go
package main

import (
    "bytes"
    "encoding/json"
    "fmt"
    "net/http"
)

func main() {
    base  := "http://localhost:8080/api/build/tools/runner-ci"
    token := "<API token with build.report scope>"

    payload, _ := json.Marshal(map[string]any{ // any requires Go 1.18+
        "pipeline_iid": 12345,
        "status":       "success",
        "finished_at":  "2026-08-18 18:05:30",
        "started_at":   "2026-08-18 18:00:00",
        "ref":          "master",
        "sha":          "a3f8c1d2",
        "exit_code":    0,
        "tag":          "master-a3f8c1d2",
        "env":          "staging",
    })

    req, _ := http.NewRequest("POST", base+"/report", bytes.NewReader(payload))
    req.Header.Set("Authorization", "Bearer "+token)
    req.Header.Set("Content-Type", "application/json")

    resp, err := http.DefaultClient.Do(req)
    if err != nil {
        panic(err)
    }
    defer resp.Body.Close()

    fmt.Println(resp.StatusCode)
}
```

**Drone CI (`.drone.yml` embedded report)**

```yaml
kind: pipeline
type: docker
name: build

steps:
  - name: build
    image: golang
    commands:
      - go build ./...
      - go test ./...

  - name: report
    image: curlimages/curl
    when:
      status: [success, failure]   # report on failure too
    environment:
      TOKEN:
        from_secret: glue_token
      BASE: http://glue:8080/api/build/tools/runner-ci
    commands:
      - |
        STATUS="success"; [ "$DRONE_BUILD_STATUS" = "failure" ] && STATUS="failed"
        curl -X POST "$BASE/report" \
          -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
          -d "{\"pipeline_iid\": $DRONE_BUILD_NUMBER, \"status\": \"$STATUS\", \"finished_at\": \"$(date '+%Y-%m-%d %H:%M:%S')\", \"ref\": \"$DRONE_COMMIT_BRANCH\", \"sha\": \"$DRONE_COMMIT_SHA\", \"tag\": \"v1.0.$DRONE_BUILD_NUMBER\"}"
```

- `pipeline_iid` ← `DRONE_BUILD_NUMBER`, `ref` ← `DRONE_COMMIT_BRANCH`, `sha` ← `DRONE_COMMIT_SHA`.
- Map Drone's `failure` status to our `failed`.
- Replace `tag` with the image tag actually pushed to Harbor (example uses `v1.0.$DRONE_BUILD_NUMBER`).

**TeamCity (Command Line build step)**

Add a "Command Line" build step, set `Execute step` to "Even if some of the previous steps failed", with:

```bash
STATUS="success"; [ "%teamcity.build.status%" = "FAILURE" ] && STATUS="failed"
curl -X POST "http://glue:8080/api/build/tools/runner-ci/report" \
  -H "Authorization: Bearer %env.GLUE_TOKEN%" \
  -H "Content-Type: application/json" \
  -d "{\"pipeline_iid\": %build.number%, \"status\": \"$STATUS\", \"finished_at\": \"$(date '+%Y-%m-%d %H:%M:%S')\", \"ref\": \"%teamcity.build.branch%\", \"sha\": \"%build.vcs.number%\", \"tag\": \"v1.0.%build.number%\"}"
```

- `pipeline_iid` ← `%build.number%`, `ref` ← `%teamcity.build.branch%`, `sha` ← `%build.vcs.number%`, token via env var `%env.GLUE_TOKEN%`.
- Map TeamCity's `FAILURE` status to our `failed`.
- Replace `tag` with the image tag actually pushed to Harbor.

### Admin Panel and Auto-discovery

- The Custom_Push status card on the "Monitor" page shows ✅ with provider names when configured, or ⚪ "Not configured" when unconfigured.
- After enabling Custom_Push, auto-discovery scans Git platform project lists and generates a `build_provider=custom_push` mapping candidate (initially "pending", requires manual activation) for each project.
- After frontend page changes, perform a **hard refresh (Ctrl+F5)** in the browser; restart the service after backend configuration changes.

---

## 13. Configure Mapping

Mapping management establishes the three-way Job ↔ Git repository ↔ Harbor image association, which is the core of CI data aggregation.

### Auto-discovery

The system automatically scans enabled CI platforms and adds discovered jobs to the mapping list (initial status is "pending" and requires manual activation):

Only CI sources currently enabled are scanned (jenkins / gitlab_ci / gitea_ci, per the multi-select).

> **Note:** When multiple CI sources are enabled, the same repository may be discovered under several sources (e.g. Jenkins and Gitea Actions pointing at the same repo). Only one entry stays "active" at a time — the same repository is deduplicated by normalized remote (host + path, port ignored). Activating one entry auto-hides the others for that repository; reverting it to "pending" shows them again. This is expected behavior.

### Manual Mapping

Each job can be configured with the following fields. Only `job_name` is required; others can be omitted and auto-derived by the system.

| Field | Required | Description |
|---|---|---|
| `job_name` | ✅ | Jenkins Job or GitLab CI project full path, e.g. `"java/registry"` |
| `build_provider` | | CI system: `jenkins` (default) / `gitlab_ci` / `custom_push` |
| `git_platform` | | **Strongly recommended** for self-hosted instances. Auto-detected from URL keywords if omitted; self-hosted GitLab/Gitea domains often lack platform keywords and will fall back to `DEFAULT_GIT_PLATFORM`. Values: `gitlab` `gitee` `github` `gitea` or custom name |
| `git_remote` | | Auto-fetched from Jenkins Job SCM config if omitted |
| `project_id` | | GitLab: auto-queried via API if omitted; GitHub/Gitee: fill if known |
| `web_url` | | Project homepage link (display only) |
| `current_path` | | Project path; auto-derived from `git_remote` if omitted |
| `harbor_repository` | | Associated Harbor repo, format `"project/repository"` (display only) |
| `api_version` | | **Metadata only**; does not affect actual API routing (routes are hardcoded in each Service) |

### Mapping Data

Example (`config/settings.php`):

```php
'job_git_map' => [
    // Self-hosted GitLab (domain lacks platform keyword → must specify git_platform)
    [
        'job_name'          => 'java/registry',
        'git_platform'      => 'gitlab',
        'git_remote'        => 'http://git.mycompany.com/tools/registry.git',
        'project_id'        => 2,
        'harbor_repository' => 'mycode/code-runtime',
    ],
    // SaaS Gitee (URL contains gitee.com → auto-detected, git_platform optional)
    [
        'job_name'          => 'static',
        'harbor_repository' => 'mycode/static-app',
    ],
],
```

---

## 14. Permissions and Roles (RBAC)

Devops-Glue uses data-driven RBAC: permission keys and implied rules are stored in the database and can be managed directly in the admin panel.

### Built-in Roles and Built-in Permissions

- The only built-in role is `super_admin`, which has all permissions and **cannot be deleted**.
- Permissions are divided into "built-in" and "registered": built-in permissions are seeded by the system and **cannot be deleted**; registered permissions are added via "Permission Registration" and **can be deleted**.
- Roles other than `super_admin` (e.g. `admin` / `deployer` / `viewer`) are created by the admin in "Role Management" with manually selected permissions.

### Create Roles and Assign Permissions

1. Go to "User Management → Role Management", create a new role and name it.
2. In the permission tree, check the permissions this role may access.
3. Create users and assign roles under "User Management → User List".

### Permission Registration

When a new CD menu/module needs a new permission key, no code change is required — register the permission key directly under "Permission Management → Permission Registration" (optionally specify a parent key for hierarchy display). It then becomes selectable in role editing. The permission list shows the type (built-in / registered) and registration time.

### Implied Rules

Implied rules define automatic permission inheritance (child → parent direction). For example, the rule `cd.bot → cd.notification-manage` means checking `cd.bot` automatically also grants `cd.notification-manage`, so the corresponding menu displays correctly.

- Built-in implied rules are seeded by the system and **cannot be deleted**.
- Rules added by users via "Permission Management → Implied Rules" **can be deleted**.

---

## 15. Create API Tokens

API tokens are used by third-party services (especially Devops-Glue CD) or scripts to call the API. They are independent of the RBAC login system and directly carry interface permissions (scopes).

Go to "API Management" (requires the relevant permission) and choose the required scopes when creating a token:

| Scope | Description |
|---|---|
| `main` | Read-only: job list, mapping, Git platforms, Git discovery |
| `git` | Read-only: Git branches |
| `harbor.read` | Read-only: Harbor projects / repositories / tags |
| `harbor.scan` | Trigger Harbor image scans |
| `build.read` | Read-only: build pipelines / logs / branches |
| `build.write` | Write: trigger / retry / cancel builds |
| `build.report` | Report: scan-sync / commit-status / report |

> **Note:**
> - The token is shown in plaintext only once at creation; save it immediately.
> - `/api/admin/*` management endpoints always reject API tokens (only super_admin interactive login may access them).

---

## 16. Connect Devops-Glue CD

To let the companion [Devops-Glue CD](https://gitee.com/jeanslw/devops_cd) call this system correctly, configure the connection to this system in Devops-Glue CD's `app.env`. Choose **one** authentication method:

**Recommended — API token** (create one for the CD service in "API Management"; grant the read / write / report scopes it needs):

```ini
CI_API_URL=http://devops-glue      # Address of this system
CI_API_TOKEN=dg_xxx                # API token created above
```

**Deprecated — service account** (`CI_ADMIN_USER` / `CI_ADMIN_PASS`, being phased out; prefer the API token):

```ini
CI_API_URL=http://devops-glue
CI_ADMIN_USER=Service_Account      # Service account created in the admin panel
CI_ADMIN_PASS=Service_Password
```

> The two methods are mutually exclusive — do not configure both.

If sharing the same database with CD, point CD's `DB_HOST` to the same MySQL instance.

---

## 17. Verify Everything Works

After completing the above steps in order, verify the system is usable:

- [ ] The admin panel accepts logins (`http://localhost:8080/admin`).
- [ ] "Monitor" shows CI / Git / Harbor services connected.
- [ ] "Build Mode" is set to the expected mode.
- [ ] "Mapping" auto-discovers and enables the target jobs.
- [ ] Roles / users / permissions match your team's needs.
- [ ] An API token has been issued for the CD service.
- [ ] The interactive API docs are reachable: `http://localhost:8080/api/docs` (requires login).
- [ ] (Optional) Run the API smoke test after providing the password via an environment variable — PowerShell: `$env:TEST_LOGIN_PASS='admin-password'; php tests/smoke_test.php http://localhost:8080` (override the username with `TEST_LOGIN_USER`, default `root`; the script exits if the password is unset).

---

## Appendix A: Environment Variables Reference

Copy `config/app.env.example` to `config/app.env` and fill in your actual credentials.

```ini
# ============ CI Systems ============
JENKINS_BASE_URL=http://your-jenkins:8080
JENKINS_USER=admin
JENKINS_TOKEN=your_token

BUILD_MODE=jenkins,gitlab_ci,gitea_ci   # enabled CI sources, comma-separated (seed value on first boot)
BUILD_TIMEOUT=300         # Build timeout in seconds

# ============ Git Platforms ============
GITLAB_BASE_URL=http://your-gitlab
GITLAB_TOKEN=your_token

GITHUB_BASE_URL=https://api.github.com
GITHUB_TOKEN=your_token

GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_token

GITEA_BASE_URL=http://your-gitea
GITEA_TOKEN=your_token

DEFAULT_GIT_PLATFORM=gitlab   # Fallback when URL cannot be auto-detected

# ============ Harbor ============
HARBOR_BASE_URL=http://your-harbor
HARBOR_USER=admin
HARBOR_PASSWORD=your_password

# ============ Admin Panel ============
ADMIN_USER=admin
ADMIN_PASSWORD=               # Created on first boot; DB takes precedence afterwards

# Note:
#   - For super_admin login, the system first validates against the admin_users DB record.
#   - The system falls back to app.env ADMIN_USER/ADMIN_PASSWORD only when the DB is totally inaccessible (disaster recovery) or the admin_users table is empty (first deployment).
#   - This ADMIN_USER/ADMIN_PASSWORD pair is only used by the Devops-Glue API global admin fallback logic.
#   - To create a CD-specific account, create the account in the admin backend, assign Devops-Glue CD permissions, and then write it into the Devops-Glue CD's own app.env if that service supports it.

# ============ LDAP external identity source (optional) ============
# Requires the PHP ldap extension (php.ini: extension=ldap); local logins are unaffected when disabled.
# Login order: local admin_users → LDAP (must be pre-bound in user_identities) → app.env fallback
LDAP_ENABLED=false
LDAP_HOST=ldap.example.com
LDAP_PORT=389
# Pick one transport: LDAP_USE_TLS = STARTTLS after connect (port 389); LDAP_USES_LDAPS = ldaps:// directly (usually 636)
LDAP_USE_TLS=false
LDAP_USES_LDAPS=false
LDAP_BASE_DN=ou=users,dc=example,dc=com
LDAP_BIND_DN=cn=admin,dc=example,dc=com
LDAP_BIND_PASSWORD=
LDAP_USER_FILTER=(uid=%s)      # %s is replaced with the login username
LDAP_ATTRS=uid,cn,mail,dn      # attributes read back after login (comma-separated); mail refreshes the stored email
LDAP_USER_DN_PATTERN=          # when non-empty, direct-bind mode is used, e.g. uid=%s,ou=users,dc=example,dc=com
LDAP_NETWORK_TIMEOUT=5
# LDAPS with a self-signed cert (pass-through vars read directly by the OpenLDAP client, not the app; for Docker set them in config/app.env):
LDAPTLS_CACERT=/path/to/ca.pem
LDAPTLS_REQCERT=never          # internal testing only, skip cert verification; forbidden in production

# ============ Database ============
DB_DRIVER=mysql               # sqlite or mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=devops_glue
DB_USER=root
DB_PASS=your_password
DB_PATH=config/data/data.db   # SQLite only
DB_AUTO_MIGRATE=true          # Auto-create tables; false = run scripts in database/ manually

# ============ Application ============
APP_ENV=production            # production / staging / development
APP_DEBUG=false
API_BASE_URL=http://127.0.0.1:8080
LOG_PATH=/applogs/
TRUSTED_PROXY_HOPS=0          # Number of reverse proxies in front (nginx etc.):
                              # 0 = direct (default, X-Forwarded-For ignored);
                              # 1 = one proxy (real IP taken from the rightmost XFF entry,
                              #     login-failure lockout is bucketed by that IP); increase for more layers
```

---

## Appendix B: CORS Configuration

Edit `config/settings.php`:

```php
'cors' => [
    'allowed_origins' => ['*'],   // Allowed domains, * = all
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
]
```

---

## Appendix C: Multi-Environment Deployment

The system supports environment separation via `APP_ENV` (production / staging / development):

- `app.env`: Base config (contains real passwords, gitignored)
- `app.env.staging`: Staging overrides (e.g. `APP_DEBUG=true`)
- `app.env.local`: Local overrides (gitignored, personal tweaks)

Loading priority: `app.env.local` > `app.env.{APP_ENV}` > `app.env`

Default `APP_ENV=production` does not require additional files. See `docs/Technical-Guide.md` (English) or `docs/技术文档.md` (Chinese) for details.

---

## Appendix D: Custom Git Platform Integration

You can integrate any Git platform without modifying core source code.

### 1. Write a Provider class

Create an adapter in `src/Service/Git/` implementing `GitProviderInterface`:

```php
namespace App\Service\Git;

class BitbucketService implements GitProviderInterface
{
    public function getName(): string { return 'bitbucket'; }
    public function matchUrl(string $url): bool { return str_contains($url, 'bitbucket'); }
    public function getApiVersion(): string { return 'v2'; }
    public function getBranches(string $repository): array { /* fetch branches with pagination */ }
    public function getTags(string $repository): array { /* fetch tags with pagination */ }
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array { /* write back commit status */ }
}
```

> `GitProviderInterface` has 6 methods, all required: `getBranches`, `getTags`, `setCommitStatus`, `getName`, `matchUrl`, `getApiVersion`.

### 2. Register in `config/settings.php`

```php
'git' => [
    'custom_providers' => [
        [
            'class'  => 'App\\Service\\Git\\BitbucketService',
            'config' => [
                'name'         => 'bitbucket',
                'base_url'     => 'https://api.bitbucket.org/2.0',
                'token'        => env('BITBUCKET_TOKEN', ''),
                'api_version'  => 'v2',
                'matcher'      => function (string $url): bool {
                    return str_contains($url, 'bitbucket');
                },
            ],
        ],
    ],
],
```

### 3. Auto-discovery

No further source changes needed. The system automatically discovers and registers custom providers on startup.

> **Note:** Custom platforms do not support independent `app.env` variables (e.g. `BITBUCKET_TOKEN`). Tokens must be written into `settings.php` or extend `AppConfig` yourself.

### 4. Adding Built-in Platforms

To add a built-in platform (GitLab/Gitee/GitHub/Gitea style), modify:

- `src/Service/Git/XxxService.php` (adapter)
- `config/container.php` (ProviderRegistry registration)
- `config/AppConfig.php` (`getXxxConfig` + `getDefaultApiVersion` + `getGitPlatformsConfig`)
- `config/settings.php` + `settings.example.php` (config sections)
- `config/app.env.example` (environment variable declarations)

---

## Appendix E: Single Sign-On (OAuth2 / OIDC)

Glue ships a built-in OAuth2 / OIDC Provider, letting Grafana (OAuth2) and Jenkins / Harbor / GitLab (OIDC) sign in with a Glue account.

See [Single-Sign-On.md](Single-Sign-On.md) for setup steps, per-system integration config, and role mapping.
