# Changelog

## v2.7.0 (2026-08-31)
- **Viewer read-only role** — New `viewer` built-in system role (is_system, non-deletable) seeded with 8 read-only keys: 6 CD (build results / deploy records / resource monitor / app resources / system resources / approval center) + 2 CI (user list / permission list). Deliberately excludes write keys (`cd.image-registry`, `ci.manage`, `cd.deploy.approve`, etc.) and never expands implied rules, preventing privilege escalation via `cd.build-manage → ci.trigger`.
- **CD user read/write API** — New `/api/rbac/*` (7 endpoints: create / read / update / delete / verify-password / role catalog) for the CD service account to integrate via API token, reusing the `rbac.user.write` scope; read endpoints never return `password_hash`, verify-password returns only a boolean, so bcrypt hashes never leave Glue.
- **Role existence validation** — User create/update now verifies the role exists in the `roles` table, returning 400 `user.role_not_found` otherwise, preventing dangling role names that would load zero permissions.
- **System role description seed** — `roles.description` for `super_admin` / `viewer` is seeded from `DEFAULT_ROLE_DESCRIPTIONS` (超级管理员 / 只读), consumed by the CD role catalog as the display name.
- **Role display name i18n-first** — The admin role list now prefers i18n (`user.role_{name}`) and falls back to description → role name, so custom roles without a translation no longer show the raw i18n key.
- **Approval-center permissions** — New `cd.approval-center` (top-level menu) and `cd.deploy.approve` (approve/reject action) keys, with the implied rule `cd.deploy.approve → cd.approval-center`.
- **Build project/tag endpoints** — New `GET /api/build/projects` (active mappings + latest tag per project, for CD listing/deploy resolution) and `GET /api/build/{path}/tags` (paginated tag list).
- **Git remote normalization fix** — dash vs slash are distinct Jenkins job identities (`java-registry` top-level job vs `java/registry` folder job); mapping matching no longer conflates them.
- **Custom Push log URL** — Build detail now returns the `log_url` field.
- **API token scope language** — Scope labels now follow the current language, so the English UI no longer shows backend-default Chinese.
- **Versioning** — `APP_VERSION` bumped to 2.7.0; OpenAPI `version` synced (CN/EN).

## v2.6.4 (2026-08-25)
- **Prepared-statement hardening** — MySQL connections now disable emulated prepares (`PDO::ATTR_EMULATE_PREPARES = false`) and use server-side native prepares, removing the injection surface introduced by the emulation layer's own quoting; applied to both connection sites (`config/container.php` and `Service/Database.php`).
- **Error-handler status-code guard** — The default error handler now range-checks Slim `HttpException` status codes against 400–599, falling back to 500 when out of range (e.g. a bare `HttpException` with code 0), preventing `withStatus(0)` from throwing a second exception *inside* the error handler and blanking the page.
- **Git remote normalization fix** — Fixed parsing of `ssh://user@host:port/path` remotes: the old logic folded the port number into the repository path (`2222/org/repo`), so the same repository reached over https and over ssh normalized to two different canonical keys and broke CI→CD chain matching. Both now yield `org/repo`.
- **User-update existence check** — `updateUser()` now verifies the user exists before the UPDATE and raises explicitly when it does not, instead of silently updating nothing. Existence is determined by a dedicated query rather than `rowCount()` (on MySQL `rowCount()` reports *changed* rows, not *matched* rows, so re-submitting identical values falsely reports 0), matching `setStatus()` behavior.
- **Logging observability** — `Helper\Log` is now a singleton, so recording an exception no longer reloads the config and rebuilds the Logger on every call; the previously silent `catch` blocks in `Database::ensureIndexes()` and `TokenService::validate()` now log, so index-creation failures and database errors during token validation are no longer swallowed.
- **API token capabilities** — The API-token list now exposes a `capabilities` field that expands abstract scope keys into the concrete endpoints/operations they unlock (e.g. `build.report` → `scan-sync` / `commit-status` / `report`), so what a token can actually call is visible at a glance.
- **Git remote path extraction refactor** — New `App\Helper\GitRemote::extractPath()` unifies GitLab subgroup path extraction (`group/sub/repo`), replacing the four duplicated regexes that kept only the last two segments; `ssh://` URLs with a port no longer leak the port into the path, so branch/tag dropdowns and commit-status write-back stop 404-ing on subgroup projects.
- **SSO email** — The seeded super-admin account now always gets an email: a real one via the optional `ADMIN_EMAIL` env var, or a placeholder `admin@example.com` by default (editable in the admin UI). This keeps OIDC/OAuth `email_verified` at `true` and avoids downstream SSO clients that require a verified email rejecting the account. Existing DBs are backfilled only when the email is empty or still the placeholder, never overwriting an admin-edited email.
- **Scan state decoupled from write-back** — The `scan-sync` endpoint now separates `scan_state` (Harbor vulnerability scan result) from `commit_status.success` (Git platform write-back result), so a write-back failure no longer corrupts the scan result; the Gitee write-back failure message is sanitized and the raw exception goes to server logs only.
- **Versioning** — `APP_VERSION` bumped to 2.6.4; the OpenAPI `version` field, which had lagged at 2.6.2, is realigned to 2.6.4 (both CN and EN specs).

## v2.6.3 (2026-08-24)
- **LDAP login support** — Added PHP ext-ldap based LDAP authentication (zero new Composer deps): supports `user_dn_pattern` direct mode and `bind_dn + base_dn + user_filter` admin-search mode, LDAPS / StartTLS, and filter & DN escaping against injection.
- **Auth chain integration** — `AdminAuthService` login order: local DB → LDAP → env fallback; a successful LDAP bind must map to a local account bound in `user_identities`, otherwise rejected (`auth.ldap_not_bound`); `ldap_bind_failed` / `ldap_user_not_found` both map to "wrong credentials".
- **Identity binding table** — Added `user_identities` (globally unique on `provider_type + provider_uid`), supporting local / ldap multi-source binding; LDAP identities keep credential NULL, and login auto-refreshes email & raw_profile.
- **admin_users structure expansion** — Added `id` (BIGINT/INTEGER auto-increment PK), `avatar_url`, `status` (1=active, 0=disabled), and `created_at`; fresh DBs get the full schema via `mysql_init.sql` / `sqlite_init.sql`, existing DBs get `avatar_url` / `status` / `created_at` via idempotent columnMigrations — the `id` PK requires manual migration by the deployer (a MySQL AUTO_INCREMENT PK column cannot be added idempotently via ALTER, and it touches the old username PK).
- **User enable/disable (kick offline)** — Login checks `admin_users.status`, rejecting disabled (status=0) accounts; token validation re-checks account status so a disabled account's unexpired token is invalidated immediately. Admin user list gains a "status" column and enable/disable button (`PUT /api/admin/users/{username}/status`); root and self cannot be disabled.
- **Timestamp semantics fix** — Creating a user writes only `created_at`; `updated_at` is written only on update.
- **CD parity** — Devops_CD login and token validation now check `status` in sync (with column-existence fallback for old DBs), matching CI behavior.
- **Config** — `config/settings.php` gains an `ldap` block (enabled / host / port / use_tls / use_ldaps / base_dn / bind_dn / bind_password / user_filter / user_dn_pattern / attrs / network_timeout), with `.env.example` examples; `APP_VERSION` bumped to 2.6.3.
- **i18n** — CN/EN language packs add `auth.ldap_not_bound` and `auth.user_disabled`.

## v2.6.2 (2026-08-23)
- **OIDC Provider upgrade** — Added an OIDC Provider (Discovery + JWKS + RS256 id_token), letting Jenkins / Harbor / GitLab log in with Glue accounts on top of OAuth2 (Grafana stays backward-compatible).
- **OAuth secret fail-closed** — Rejects empty/whitespace OAuth client secrets outright, eliminating the empty-secret bypass.
- **SSO documentation** — Unified OAuth2/OIDC single-sign-on docs (CN/EN); README/OpenAPI now list the SSO endpoints.

## v2.6.1 (2026-08-23)
- **OAuth2 Provider** — Added an authorization-code OAuth2 Provider (`/oauth/authorize` + `/oauth/token` + `/oauth/userinfo`), enabling Grafana single sign-on with Glue accounts.
- **Relationship dashboard read-only API** — Added read-only dashboard endpoints serving CI→CD chain data to Grafana panels; OAuth userinfo enhanced with additional user attributes.
- **Account management enhancements** — super_admin can now edit their own profile; `admin_users` gains an `email` field.
- **Expired tag cleanup** — Added expired-tag cleanup (admin toggle + read-time cleanup + scheduled CLI), unified writable directories to 755.
- **Mapping key normalization** — custom_push record keys normalized to `job_name`, avoiding `project` fragmentation.
- **Dependency tightening** — Pinned minimum PHP to 8.1, downgraded PHPUnit to ^10.5.
- **DB fix** — Fixed SQLite default path overshooting by one directory level, preventing silent empty-DB creation outside the project.

## v2.6.0 (2026-08-19)
- **Custom Push CI (custom_push)** — Added a push-based CI build mode where users push build status, log URL, and image tags via their own CI scripts. Devops-Glue only stores metadata and does not participate in build execution.
- **New API endpoint** — Added `POST /api/build/{path}/report` (terminal build result + image tag write-back in one call), reusing the `build.report` scope.
- **Config-driven registration** — Custom CI instances are registered via the `build.custom_providers` array in `settings.php`, ready out of the box; enabling requires only a checkbox toggle in the admin panel.
- **Orthogonal mode design** — `build_mode` (jenkins/gitlab_ci/both) and `custom_push_enabled` are independent switches, allowing any combination.
- **Admin panel enhancements** — Added Custom_Push status card, auto-discovery scanning support, dropdown and mapping list adaptations.
- **Database additions** — Added `ci_custom_builds` table for build metadata storage; `pipeline_iid` is an integer type.
- **OpenAPI docs** — Completed OpenAPI spec for the new endpoint (CN/EN).
- **Documentation updates** — API docs, admin manual, technical guide, architecture diagram, and FAQ all updated in sync.
- **Version correspondence** — Devops-Glue API v2.6 ↔ Devops-Glue CD v1.4.
- **Release update** — Bumped app version to v2.6.0.
- **Refinements** — Push records pagination (Custom_Push_Log); disabled Custom_Push now demotes new custom_push mappings to Pending and filters them in every build mode; Harbor repository-vs-tag existence checks; topology flow direction git → build → harbor.
- **Harbor version detection** — Detect the concrete Harbor version (`/api/v2.0/systeminfo`, anonymous-first with authenticated fallback); robot-account REST API support is decided by version (boundary v2.2.0) and shown on the admin "Platform API Version" page.
- **Service version compatibility doc** — Added `Integrated-Service-Version-Compatibility.md` (CN/EN) covering Jenkins / GitLab / Gitea / Harbor version support and boundaries.
- **CI-support accuracy** — FAQ, admin manual, architecture, and API docs now state the push-based Custom_Push (theoretically all CI) alongside pull-based Jenkins / GitLab CI.

## v2.5.1 (2026-08-14)
- **Permission list: built-in vs. registered + delete + registered-at** — The permissions list now distinguishes built-in vs. registered permissions (`is_builtin`), shows the registration time (`created_at`), and lets admins delete registered permissions (built-in keys are protected).
- **DB bootstrap fix** — Restored "run schema/seed only on first boot" semantics; no more per-request redundant writes (regression from the refactor).
- **Idempotent migrations** — `ALTER ... ADD COLUMN` now checks column existence, eliminating the flood of `Duplicate column name` error logs.
- **Security audit write-back** — Commit status write-back results (success / failed / skipped) are now recorded in `ci_security_checks` and surfaced in the admin security audit page.
- **Audit page i18n fix** — The state / write-back columns no longer render raw English i18n keys; translation now happens lazily at render time.
- **Schema init scripts** — `mysql_init.sql` / `sqlite_init.sql` now create the complete schema in one shot (including the write-back columns).
- **Admin forgot-password** — Fixed the previous security issue; admin forgot-password recovery is now handled via a patch.
- **Docs and other updates** — Fixed several minor bugs, plus documentation updates.

## v2.5.0 (2026-08-13)
- **API Token management** — Added API token feature: creation (`dg_` prefix + 64 hex chars), listing, and scope query.
- **Token revoke/delete** — Supports both "revoke" (soft-delete: disable and keep the record) and "delete" (hard-delete) operations.
- **OpenAPI docs** — Completed OpenAPI spec for the 5 API token endpoints.
- **Version correspondence** — Admin manuals now document the Devops-Glue API ↔ Devops-Glue CD version correspondence (v2.5 → v1.3, v2.4 → v1.2).
- **Release update** — Bumped app version to v2.5.0.

## v2.4.4 (2026-08-11)
- **Release update** — Bumped app version to v2.4.4.
- **Admin auth clarification** — Clarified `.env` fallback and admin account login behavior in documentation.
- **Smoke test fix** — Narrowed admin role expectation to match current super_admin auth semantics.

## v2.4.3 (2026-08-08)
- **Security Hardening** — All `/api/build/*`, `/api/git/*`, `/api/harbor/*` routes are now covered by AuthMiddleware, eliminating unauthenticated access risks.
- **Slim 4 Best Practices Refactor** — Authentication logic moved from Controller to AuthMiddleware; PDO, Logger and other dependencies unified as constructor injection; eliminated service locator anti-pattern and setter injection.
- **TokenService Extraction** — Unified token validation, permission query, and token revocation logic, eliminating duplicate SQL across AuthMiddleware, AdminController, and MainController.
- **Admin API Performance Optimization** — AuthMiddleware now queries and injects userPermissions in one pass, eliminating redundant DB queries for each permission check in AdminController.
- **Frontend Adaptation** — Build interface `<a>` links in admin.js changed to fetch + authHeaders + clipboard copy.

## v2.4.2 (2026-07-31)
- **Permission Management Refactor (Data-Driven RBAC)** — Permission keys, parent hierarchy, and implied relations all stored in DB; admin UI supports direct registration/deletion.
- **RBAC Role/Permission Edit UI** — Role editing supports visual permission grouping.
- **Security Hardening** — Permission registration API and implied rules API now have unified permission checks.
- **Homepage UI Adjustments** — Added system monitoring to admin dashboard.
- **Error Message Improvements** — OpenAPI docs now include 5 RBAC endpoints.
- **Historical Documentation Completion** — README (EN/CN), Technical Guide (EN/CN), FAQ (EN/CN), API.md updated.

## v2.4.0 (2026-07-28)
- **i18n** — Added `symfony/translation` dependency, supports Chinese/English bilingual UI.
- **Super Admin** — Introduced `super_admin` role, stored in DB, a built-in system role with wildcard `*` permissions above all user-defined roles.
- **User Permission Levels** — Data-driven multi-level RBAC; roles are user-defined with customizable permissions, and admin accounts at the same level cannot modify or delete each other.
- **Root Admin Protection** — Built-in root account cannot be deleted or modified.
- **Admin Dashboard Enhancements** — User list now shows roles; top bar displays current logged-in user.

## v2.3.2 (2026-07-25)
- Multi-environment config support (`APP_ENV` with `.env.{ENV}` overrides).
- Build mode runtime switching with backend validation.
- Security scan commit status write-back + `ci_security_checks` audit table.
- Bug fixes: security scan icons, `ci_pipeline_tags` COUNT query, Git pagination.

## v2.3.1 (2026-07-15)
- Tag validation and storage rules.
- MySQL / SQLite dual driver; `DB_DRIVER` required.
- Docker optimized with MySQL 8.4 integration.

## v2.3.0 (2026-07-10)
- GitLab CI dual-channel + Build unified module.
- SQLite persistence + Admin web UI.

## v2.2.0 (2026-05-06)
- ProviderRegistry pattern for Git platforms.
- Custom platform extension support.
- Gitea adapter added.

## v2.1.2 (2026-05-04)
- Homepage health check dashboard.
- GitHub platform integration.
- `/api/health` endpoint.
- Swagger UI + structured file logging.
- Docker deployment support.

## v2.1.1 (2026-03-05)
- Slim4 refactor.
- Main module (platform integration, multi-party mapping).
- Output format switching (raw/json/xml).
- Harbor scan integration and report retrieval.

## v1.1.0 (2021-11-01)
- Harbor query features added.

## v1.0.0 (2018-09-28)
- Initial release: Jenkins, Git, and Rundeck integration.