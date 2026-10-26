# Changelog

## v2.11.0 (2026-10-24)

- **Added**
  - The image registry is selectable per deployment: Harbor (default) or a standard OCI registry (`registry:2` and alike), configured on the admin platform-connection card; one deployment picks one, the two types never coexist. A standard registry has no vulnerability scanning, and the related pages hide the matching columns and actions.
  - `POST /api/build/{path}/registry-sync` lets CI report a pushed image back to Glue. It does not require the registry to support scanning, so a standard registry also gets its push result recorded and the Git commit status written.
  - The integration-service contract endpoint delivers the registry type and address, so consumers such as CD no longer keep a manually synchronized local copy; local environment variables remain as a fallback when the endpoint is unavailable.
  - Querying a repository's tags returns tag details instead of bare names: each entry carries `digest`, `size_bytes`, `push_time` and `pull_time`, and Harbor adds the scan conclusion (`scan_status`, `scan_severity` and the per-severity counts). Only what the registry can supply is filled — Harbor v1 and `registry:2` leave the rest empty or null, and for `registry:2` Glue resolves the digest and size from the image manifest (config plus layers; a manifest list or index is resolved to a linux/amd64 entry first). `/api/harbor/*/tags` and `/api/registry/*/tags` share the implementation through the provider interface's new `getTagDetails()`.
  - The tag endpoints accept pagination: `page` and `page_size` (1–200, default 50) return `{items,total,page,page_size,total_pages}`, while omitting both keeps the previous array response unchanged, so existing consumers such as CD's registry sync need no change.
- **Changed**
  - The registry field on mappings, build and deploy payloads, and the admin pages is renamed from `harbor_repository` to `registry_repository`; the automatic migration copies the old values into the new column and then drops the old one.
  - `scan-sync` and `registry-sync` share one write-back implementation and differ only in whether scan results are read; `scan-sync` remains the Harbor-specific entry point.
  - The audit row of a write-back is no longer limited to pipelines that ended successfully: when CI reported the image but the pipeline failed or did not finish, the audit stays (marked as "not written back"), while the artifact is still recorded only for successful pipelines and only those count as a push fact.
  - The registry type and address from the contract are delivered only to credentials holding the "Harbor read" permission (which governs both Harbor and standard-registry queries): consumers connect to the registry directly once they have the address and never pass through Glue again, so the gate sits at delivery. The CD service credential does not carry that permission by default — grant "Harbor read" to the credential in "API Management" when CD needs the image registry.
  - Health checks probe `/v2/` for a standard registry instead of the Harbor components; the saved username and password survive a registry-type switch, because both types share one connection entry and the switch only changes the access protocol (Harbor v2 API versus the OCI distribution API) — clearing the credentials used to make every switch back return 401.
  - Security-check records from the two write-back endpoints are marked `harbor-scan` / `registry-sync`; the audit page accepts both, so earlier records stay visible.
  - `config/settings.example.php` no longer carries a `harbor.registry_type` entry: the registry type is configured only on the admin platform-connection card, and the file value never took effect.
  - The image-registry query scope is renamed from `harbor.read` to `registry.read` (it governs both Harbor and standard OCI registry queries); existing tokens are migrated automatically at startup and need no reissue. `harbor.scan` is unchanged.
- **Fixed**
  - A registry path with more than two segments is accepted; the earlier two-segment limit is gone.
  - The registry query endpoints reject an invalid project or repository name (`..`, an empty segment, characters other than `A-Za-z0-9._-`, a `/` in the project) with `400` and a message instead of passing it into the registry URL; multi-segment names such as `a/b` are still accepted, and `/api/harbor/*` keeps its existing contract without validation.
  - Commit-status write-back sends the state each platform accepts: failures are normalized to `failure`, and without a scan conclusion the state is derived from the pipeline result instead of sending `unknown`, which the platforms reject.
  - A standard registry with an empty password is treated as anonymous and sends no authentication header, so the default `admin` username no longer breaks anonymous pulls.
  - `GET /api/registry/projects` always returns project names as strings: a purely numeric first segment (for example `123/app`) is no longer turned into a JSON number.
  - Dropping the renamed-away column is best-effort on old SQLite versions that lack `DROP COLUMN`: a warning is logged instead of aborting the startup migration, and the leftover column is harmless.
  - Two missing UI texts no longer surface as raw translation keys (the failed login-token message and the implied-permission dropdown).
  - Messages built from translations render their placeholders correctly: the braces used to stay in the text and the substitution also replaced matching words anywhere in it.
  - Harbor version detection no longer falls back to probing the v1 endpoint when the v2 probe fails with a network error, where a single timeout used to be cached as "v1" for a full hour. An unconfirmed probe is treated as v2 and cached for 120 seconds only, so the next request re-probes immediately; a v2 path that answers `404` is still taken as v1 and cached the same short way.
  - A platform address may be entered as `host[:port]` without a scheme: Harbor is normalised to `https` and a standard registry to `http`, instead of the request failing on a schemeless base URL.

## v2.10.0 (2026-10-17)

- **Added**
  - Platform connections and the OIDC/SSO client whitelist are stored in the database and managed from two new admin pages; credentials are stored encrypted and never returned in plaintext.
  - Build mode covers standalone CI only; each Git platform's built-in CI has its own enable switch, migrated automatically from the old combined format.
  - GitHub Actions is available as a pull-mode CI source, including discovery and build mode.
  - Bitbucket and GitCode are supported as custom Git platforms.
  - Pull-mode CI failures are returned by HTTP semantics: resource not found (404), service unreachable (502), service unavailable (500); API-token calls follow the same semantics.
  - Auto-discovery reports success / partial failure / total failure and names the platforms that failed.
  - Platform connection cards are collapsed by default and expand on click.
  - A mapping must declare its build source: creating one without `build_provider` returns 400, an explicitly empty value is rejected, and building a mapping with no provider is refused instead of falling back to Jenkins (a mapping left empty by an earlier release must be edited to pick its CI).
  - Editing a mapping merges into the existing row, so sending only the fields you want to change no longer clears the rest.
  - A mapping with no build provider is shown as "build source unspecified" everywhere — mapping list, topology and the project dropdown — instead of being labelled Jenkins or dropped from the list.
- **Fixed**
  - Hosting type is derived from the base-URL domain, so the hosted GitLab service is no longer labelled self-hosted.
  - An unreachable Harbor, Git platform or Jenkins is reported as unreachable instead of "no data", "no branches" or "job not found".
  - For API-token calls, "resource not found" and "service unavailable" are no longer flattened into 502.
  - Switching a platform's CI off demotes its active mappings to pending, so re-enabling does not silently resurrect them.
  - Build-record load failures show the backend reason instead of a bare status code, and a failed auto-refresh keeps the existing table.
  - The scanning notice no longer disappears after a few seconds, and consecutive notices no longer overwrite each other.
  - Creating or editing a mapping writes only that row instead of "read all, change one, write all back", so concurrent edits no longer overwrite each other; batch writes run in a transaction, and empty input is rejected instead of clearing the table.
  - Editing a mapping with an empty `job_name` no longer deletes the row: it returns 400.
  - OpenAPI documents the endpoints that accept both GET and POST but only declared GET (the `main`, `build`, `git` and `harbor` list endpoints plus `/oauth/userinfo`), and adds the missing `custom_builds`, `operation_logs` and `pipelines/{id}/resolve-tag`.
  - Commit-status write-back no longer echoes the raw upstream error, which could expose internal URLs; a safe summary with the HTTP status is returned instead.
  - CI credential and permission failures (401 / 403) are reported as such instead of being labelled "service unavailable".
  - Auto-discovery no longer treats a 5xx from GitHub or Gitea as "no projects found"; the failure is reported with the platform name.
  - Jenkins unreachable errors no longer include the internal request URL; details stay in the log.
  - GitCode repositories are recognised as SaaS — the SaaS host list used a domain GitCode does not use.
  - Mappings with no provider are no longer filtered out of job lists, no longer match a Jenkins filter, and no longer demoted to pending when a CI is switched off.
  - Auto-discovery no longer skips a project because a provider-less mapping with the same name exists, so its build source can still be filled in.
  - A path that matches no mapping is only sent to Jenkins when Jenkins is enabled; otherwise it is rejected instead of being assumed to be a Jenkins job.
  - Running a tag job no longer distorts the platform-connection cards: the long one-line result wraps instead of stretching its grid column and squeezing the neighbouring cards.
  - `BuildController::report()` now resolves the mapping for the reported path through `MappingManager::resolveMapRow()` instead of scanning all maps itself, so path lookup has a single definition across build, scan-sync and report.
  - Tags parsed out of build logs must satisfy the Docker tag charset (`[A-Za-z0-9_][A-Za-z0-9._-]{0,127}`): values such as an unexpanded `${TAG}` are dropped, and trailing punctuation including a sentence-final period is trimmed (`v1.0.` becomes `v1.0`), so neither kind of dirty value reaches the Harbor lookup and failures no longer point at Harbor.
  - `BUILD_TIMEOUT` is removed — nothing ever read it, and the docs wrongly implied that raising it would relax the Jenkins trigger timeout (which is a fixed 30 s in `JenkinsService`). The FAQ and failure tables now give the real per-CI timeouts.
- **Improved**
  - nginx, php-fpm and the scheduled worker run as separate containers, so the web tier scales independently.
  - A distributed lease lock keeps each scheduled job to a single instance across replicas, and schema creation on cold start is mutually exclusive.
  - Two offline migration scripts move connection and OIDC settings from env into the database without overwriting existing values.
  - The seed-account warning fires only when the first-login password change is still pending.
  - A Kubernetes reference manifest is included.
  - OpenAPI descriptions are trimmed to the action and its behaviour; field listings and rationale stay in the documentation.
  - `resolve-tag` resolves the mapping once instead of scanning the whole table twice.
  - CI-related wording lists every supported engine, and Custom_Push is described as a push-based channel (an external CI reports results; Glue does not schedule it) rather than as one of the CI engines.
  - Which CI a mapping uses, and which project id to call it with, have a single definition shared by build, scan-sync and commit-status, so the three endpoints can no longer resolve to different rows.
  - A CI data-flow diagram is added to the README and the architecture overview, with a legend explaining the line styles; all docs images are moved under `docs/Image/`.
  - The Docker build context excludes `docs/`: the existing `*.md` rule does not cross the path separator, so documents and images under `docs/` were still sent to the daemon.
  - `config/app.env.staging` is a complete override-layer template: it documents which keys belong in it, which are secrets (pointed at `app.env.local`), and which are now database-managed (Jenkins / Git / Harbor / OIDC issuer). Only `APP_DEBUG=true` takes effect; the rest stays commented out as examples.

## v2.9.0 (2026-10-15)

> **⚠️ Breaking change — v2.9.0 is a major release and is not backward-compatible with earlier versions.** Before upgrading, see the CI↔CD version correspondence in the [Admin Manual](ADMIN_MANUAL.md).
- **Added**
  - Deploy logs are read from CD over HTTP instead of the shared view; when CD cannot serve them the page degrades to a placeholder.
  - A narrow contract endpoint lets an integrated service ask whether it is enabled and which provisioning mode applies; an unknown service name (404) is distinct from "not enabled".
  - Git tags are available as build refs, alongside the existing branch list.
  - Integrated-service enablement is a contract: enabling issues a scope-limited token, disabling freezes the existing credential and only revocation voids it.
  - API-token scopes can be edited without reissuing the token.
  - OIDC support for CD: account ownership is decided per client, the cd client is registered, access tokens are stored as digests, and role permissions can be queried.
  - Column drift against the schema is detected, and safe widening is applied automatically.
- **Fixed**
  - CD no longer falls back to reading the shared contract table: an unreachable or unauthorized contract is 503, never silently "not enabled".
  - CI-owned tables are unified under one prefix with in-place renames; backups taken before the rename stay restorable, and a backup mixing both names is rejected.
  - Indexes are identical across auto-migrate and the manual init scripts; driver-specific index syntax and column widths were aligned.
  - The log-center loading indicator no longer stays on screen next to the degraded placeholder.
  - Token lifetime reporting matches the real access-token TTL; the OIDC issuer must be set explicitly behind a reverse proxy.
  - The enable dialog no longer shows HTML tags as text.
  - OpenAPI documents the two log endpoints that were missing and unifies the path parameter.
- **Improved**
  - The Grafana SSO client was removed.
  - Documentation covers joint CI+CD deployment variables and bare-metal deployment; table references converge across manuals.

## v2.8.9 (2026-10-11)

- **Added**
  - Schema creation is an explicit choice: automatic by the application account, or manual with a migration CLI that a pipeline can run with a privileged account.
  - A migration dry-run previews pending tables and columns without writing, sharing one fact source with the real run and needing no DDL privileges.
  - A public health probe reports database reachability and version state, answering 503 when the database is unreachable.
  - Passwords can use argon2id in addition to bcrypt; existing hashes keep verifying without migration.
  - The admin panel shows whether schema creation is automatic or manual.
- **Fixed**
  - Static assets are no longer served as immutable for 30 days, so an upgrade no longer leaves the browser running stale JS/CSS.
  - The admin table list probed one table fewer than the database has, and the omission could not be detected; the list is now asserted against the real table set and checked at runtime.
  - Rolling back no longer narrows system-role permissions; partial seed failures fail fast and are audited instead of half-seeding.
  - Version transitions and downgrades are audited, with dedupe keyed per channel so bootstrap and explicit migrations stay separately visible.
  - Schema-version lookups use bound parameters.
  - The health probe distinguishes "database unreachable" from "schema not yet current", so a rolling upgrade no longer pulls every replica.
- **Improved**
  - The container drops all capabilities and re-adds only those it needs; the local env override file is no longer baked into the image.
  - The legacy base64 docs token is disabled by default.
  - Front-end DOM output is escaped, and CI workflows declare minimum permissions.
  - User manuals gained full walkthroughs for image tags, log settings and the admin pages.

## v2.8.8 (2026-10-05)

- **Added**
  - Three read-only audits in the admin log center: operation logs, deploy logs and API access logs, each with its own permission.
- **Fixed**
  - Connection-level failures to Jenkins / GitLab / Gitea / Harbor return 502 instead of 500, so "downstream unreachable" is distinguishable from an internal failure.
  - Mapping management: Git platforms without a native CI are discoverable, mappings of disabled providers stay listed instead of vanishing, Jenkins platform identification no longer mislabels same-host/different-port repositories, and dedup covers all built-in providers.
  - API access-log file and database writes are controlled by one switch.
- **Improved**
  - PHPStan raised to level 8; admin sidebar grouped, mobile layout and table headers polished.

## v2.8.7 (2026-10-03)

- **Fixed**
  - The database login path compares the root account case-insensitively, so a mixed-case root account is no longer treated as a normal user.

## v2.8.6 (2026-09-23)

- **Added**
  - Tag cleanup and tag backfill can be triggered from the admin panel, with the same safety rules as the scheduled jobs.
- **Fixed**
  - Environment override files take effect again; keys already present in the base file were silently dropped.
- **Improved**
  - Documentation and screenshots synced back onto the mainline; `API_Documents.md` renamed to `API_Reference.md`.

## v2.8.5 (2026-09-22)

- **Added**
  - Distributed deployment: the scheduled jobs are separated from the stateless web layer, with a lease lock so each job runs at most once across replicas.

## v2.8.4 (2026-09-20)

- **Added**
  - Pull records show an image tag column; when the canonical tag is missing it is derived from the build log and can be backfilled on a schedule.
  - Tag settings moved to the platform-config endpoint, and both tag schedules are configurable.
  - A failed pull record whose tag came from the log can be re-parsed.
- **Improved**
  - Platform-version probing no longer blocks the page, and tag settings are split into three cards.
  - Config files use the `.env` suffix; Harbor version probing logs one line per candidate.

## v2.8.3 (2026-09-20)

- **Added**
  - System settings panel with platform config and system info pages; data management with a database backup action (super_admin only).
  - Operation log records every write operation.
  - Dark theme with a topbar toggle.
- **Fixed**
  - `APP_DEBUG` gates log output as intended.
- **Improved**
  - The build-mode page drops the "select all" checkbox and spans the full row.

## v2.8.2 (2026-09-18)

- **Added**
  - Gitea Actions as a third CI source, with trigger / retry / cancel / job logs and discovery.
  - Build mode accepts multiple providers; auto-discovery dedup spans the whole enabled set.
  - A shared build-status vocabulary normalizes the native statuses of all providers.
  - Operation-log auditing with a paginated admin endpoint.
  - LDAP attribute configuration.
- **Fixed**
  - The job list merges all enabled providers instead of returning Jenkins jobs only.
  - Build-record reads are permission-gated, and CD service accounts can read pull records.
- **Improved**
  - Log verbosity is gated by `APP_DEBUG`; health probes retry once on transient network errors.

## v2.8.1 (2026-09-13)

- **Fixed**
  - Forged `X-Forwarded-For` headers can no longer spoof the client IP or bypass the login lockout; the trusted-proxy hop count is configurable.
  - Login lockout semantics match Devops-CD; RBAC service-account operations are restricted to cd accounts and rate-limited.
  - Rendered build links are restricted to http(s) and same-site URLs.
  - MySQL healthcheck works on mysql:8.4; the tag-cleanup job drops privileges; php-fpm runtime directory ownership fixed.
  - The hardcoded smoke-test password was removed; an English locale typo restored.
- **Improved**
  - Compose separates root and application database credentials with a preflight check; env-file comments are bilingual; the landing-page footer shows the version.

## v2.8.0 (2026-09-09)

- **Added**
  - Canonical pipeline identity with a 1:1 artifact table; the legacy tag table is dropped after its rows are migrated.
- **Fixed**
  - Admin usernames are normalized consistently, so mixed-case names no longer create separate identities.
  - The seed admin email is derived from the deployment hostname instead of one shared value.
  - Older reports and older timestamps can no longer overwrite newer facts; a successful custom-push report commits atomically.
- Version correspondence: API v2.8.0 ↔ Devops-CD v1.5.1.

## v2.7.2 (2026-09-09)

- **Fixed**
  - Custom-push log URLs are validated before fetching: scheme and host restrictions, blocked IP ranges, DNS-rebinding and redirect protection.
  - A successful custom-push report is final; scan-sync requires a complete pipeline identity; a failed tag write returns an error so the caller retries.
  - Tag queries merge the job-name and path aliases, so querying by either key returns the same tags.
- **Improved**
  - Compose reads the config env file; the committed root env file with real credentials was removed and ignored.

## v2.7.1 (2026-09-06)

- **Fixed**
  - Session tokens no longer appear in URLs; remaining admin output is escaped.
  - OAuth login is rate-limited like the admin login, so it can no longer be used to brute-force credentials.
- **Improved**
  - Documentation covers LDAP, the previously undocumented endpoints, and adds a contributor guide.

## v2.7.0 (2026-08-31)

- **Added**
  - Built-in read-only `viewer` role with read-only permissions only.
  - CD user read/write API for service accounts; bcrypt hashes never leave Glue.
  - Approval-center permissions.
  - Build project and tag endpoints for CD listing and deploy resolution.
- **Fixed**
  - Role names are validated on user create/update, so a dangling role no longer silently loads zero permissions.
  - Role display names fall back to description or role name instead of showing a raw translation key.
  - Dash and slash are treated as distinct Jenkins job identities; API-token scope labels follow the UI language.
- Version correspondence: API v2.7 ↔ Devops-CD v1.5.

## v2.6.4 (2026-08-25)

- **Added**
  - The API-token list shows which endpoints and operations each scope unlocks, so what a token can do is visible at a glance.
  - The seeded super-admin account always gets an email: a real one from configuration, otherwise a placeholder that can be edited in the admin UI. Existing accounts are backfilled only when the email is empty or still the placeholder.
- **Fixed**
  - SSH repository addresses with a port were normalized wrongly: the port leaked into the repository path, so the same repository reached over HTTPS and over SSH became two different projects and broke CI→CD chain matching.
  - GitLab subgroup projects (`group/sub/repo`) resolved to the wrong path, so branch/tag dropdowns and status write-back failed with 404.
  - Updating a non-existent user silently did nothing; it now fails explicitly.
  - Index-creation failures and database errors during token validation were swallowed silently; they are now logged.
  - A failed status write-back polluted the Harbor scan result, and the Gitee write-back failure exposed the raw exception to the caller.
  - An invalid status code in the error handler threw a second exception and blanked the page; it now falls back to 500.
- **Improved**
  - MySQL connections use server-side native prepared statements, removing the quoting weaknesses of emulated prepares.
  - Logging no longer rebuilds the logger on every recorded exception.

## v2.6.3 (2026-08-24)

- **Added**
  - LDAP login: direct-bind mode and admin-search mode, LDAPS and StartTLS, with filter and DN escaping against injection. No new Composer dependency.
  - Identity binding: one account can bind local and LDAP identities; LDAP identities store no credential and refresh email and profile on login.
  - User enable / disable: a disabled account is rejected at login and its unexpired tokens stop working immediately. Managed from the admin user list; the root account and the current user cannot be disabled.
  - User accounts gain avatar, status and creation-time fields. Fresh databases are created complete; existing databases gain the new columns automatically. Adding the auto-increment primary key requires a manual migration by the deployer.
  - LDAP configuration block with documented examples.
  - CN/EN language packs cover the LDAP and account-disabled messages.
- **Fixed**
  - Login order is now local → LDAP → environment fallback; an LDAP user without a bound local account is rejected, and LDAP bind failures and unknown users both report "wrong credentials".
  - Creating a user also wrote the update timestamp; it is now written only on update.
- **Improved**
  - The paired CD system checks account status as well, staying compatible with older databases.

## v2.6.2 (2026-08-23)

- **Added**
  - OIDC provider: discovery, JWKS and RS256 id_token, so Jenkins / Harbor / GitLab can sign in with Glue accounts.
  - Single-sign-on documentation in CN/EN; README and the OpenAPI spec list the SSO endpoints.
- **Fixed**
  - An empty or whitespace OAuth client secret is rejected instead of being accepted as valid.

## v2.6.1 (2026-08-23)

- **Added**
  - OAuth2 provider: authorization-code authorize, token and userinfo endpoints, so external systems can sign in with Glue accounts.
  - Expired-tag cleanup: admin toggle, cleanup on read, and a scheduled job.
  - Super admins can edit their own profile; accounts gain an email field.
- **Fixed**
  - The default SQLite path pointed one directory level too high and silently created an empty database outside the project.
  - Custom-push record keys were not normalized, splitting one project into several entries.
- **Improved**
  - Minimum PHP version is pinned to 8.1 and the test dependency was downgraded.

## v2.6.0 (2026-08-19)

- **Added**
  - Custom push CI: your own CI script pushes build status, log URL and image tags; Glue only stores the metadata and does not run builds.
  - A report endpoint that writes the terminal build result and the image tag in one call.
  - Custom CI instances are registered through configuration and enabled with a checkbox in the admin panel.
  - Build mode and the custom-push switch are independent and can be combined freely.
  - Harbor version detection: the concrete version is probed and robot-account support is decided by version, shown in the admin panel.
  - Integrated-service version compatibility guide (CN/EN) covering the supported versions and boundaries of Jenkins / GitLab / Gitea / Harbor.
  - Admin panel gains a custom-push status card; auto-discovery, dropdowns and the mapping list support the new mode.
- **Fixed**
  - With custom push disabled, newly added custom-push mappings became active and appeared in every build mode.
  - Harbor repository and tag existence were not checked separately.
- **Improved**
  - Push records are paginated; the topology graph flows repository → build → registry.
  - Documentation states push-based and pull-based CI support consistently.
- Version correspondence: API v2.6 ↔ Devops-CD v1.4.

## v2.5.1 (2026-08-14)

- **Added**
  - The permission list distinguishes built-in from registered permissions, shows when a permission was registered, and lets admins delete registered ones (built-in keys are protected).
  - Status write-back results (success / failed / skipped) are recorded and shown on the security audit page.
- **Fixed**
  - Schema and seed data ran on every request instead of on first boot only.
  - Column migrations ran without an existence check, flooding the log with "duplicate column" errors on every request.
  - The state and write-back columns of the audit page showed raw English keys instead of translated text.
  - The admin forgot-password flow had a security issue and is now handled by a patch.
- **Improved**
  - Fresh-database init scripts create the complete schema in one shot, including the write-back columns.

## v2.5.0 (2026-08-13)

- **Added**
  - API token management: creation, listing and scope query.
  - Tokens support both revoke (disabled but kept on record) and delete (removed entirely).
  - OpenAPI spec for the five API-token endpoints (CN/EN).
- **Improved**
  - The admin manual documents the API ↔ Devops-CD version correspondence (v2.5 → v1.3, v2.4 → v1.2).

## v2.4.4 (2026-08-11)

- **Improved**
  - Documentation clarifies the environment fallback login and admin account behaviour.
  - The smoke test narrows its role expectation to the current super-admin semantics.

## v2.4.3 (2026-08-08)

- **Fixed**
  - The build, git and harbor API route groups were not covered by authentication and could be called unauthenticated.
- **Improved**
  - Authentication moved into middleware; dependencies such as the database handle and logger are constructor-injected.
  - Admin endpoints resolve user permissions in one pass instead of querying per permission check.
  - Build links in the admin panel now call the API with credentials and copy the result to the clipboard.

## v2.4.2 (2026-07-31)

- **Added**
  - Data-driven RBAC: permission keys, hierarchy and implied relations live in the database, and permissions can be registered and deleted from the admin UI.
  - Role editing groups permissions visually.
  - System monitoring on the admin dashboard.
  - OpenAPI spec for the five RBAC endpoints; README, technical guide and FAQ completed in CN/EN.
- **Fixed**
  - The permission-registration and implied-rule endpoints lacked a unified permission check.

## v2.4.0 (2026-07-28)

- **Added**
  - Bilingual UI (Chinese / English).
  - Built-in `super_admin` role with wildcard permissions above every custom role.
  - Data-driven multi-level RBAC: roles and permissions are user-defined, and admins at the same level cannot modify or delete each other.
  - The built-in root account is protected against deletion and modification.
  - The user list shows roles and the top bar shows the signed-in user.

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