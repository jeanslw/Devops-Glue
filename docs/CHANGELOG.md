# Changelog

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
- **Super Admin** — Introduced `super_admin` role, stored in DB, with permissions above regular `admin`.
- **User Permission Levels** — 4-tier RBAC; admin accounts at the same level cannot modify or delete each other.
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