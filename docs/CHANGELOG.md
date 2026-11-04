# Changelog

## v2.11.4 (2026-11-03)

- **Fixed**
  - Artifacts table restores the image digest field, lost during the v2.8.0 rename; it is added back automatically on upgrade/deploy.
  - Image sync and scan sync can now report the image digest; when not reported and the tag is verified, it is resolved from the registry and persisted.
  - Artifact API responses and queries return the image digest.
  - Database time storage and writes are unified to UTC; all repositories now persist build/pipeline/log timestamps in UTC instead of the container local time, fixing cross-timezone display drift and the SQLite incremental-schema default-value gap.
  - Joint-deployment docs add from-scratch prerequisites (prepare two environment examples, enable devops-cd), and fix the container-rebuild steps that omitted the CD service and failed to note that editing the root .env requires rebuilding the container rather than restart; also correct the CD table-creation wording to state that CD builds its own tables per its migration mode (not Glue).
  - Fix forced first-login password-change deadlock: a non-super-admin account (lacking ci.users.password) was locked out and had no way to reach the change-password page when must_change_password=1; the self-service change-password endpoint no longer requires that permission (safety is guaranteed by verifying the old password), the frontend now unconditionally shows the entry and pins the user to the change-password tab while forced, and the username dropdown gains a persistent "Change Password" entry.
  - Fix misleading registry display: the map-list and platform endpoints now return the address field named after the actual configured type (Harbor → harbor_url, standard Registry → registry_url) — a single address field with no redundant type field, removing the "two configured / type mismatch" ambiguity; the topology view infers the type from the field name and no longer forces the /harbor link for Registry.
  - OpenAPI docs (zh/en) rename Harbor to Container Registry in the "three-party mapping" title, aligning with the registry-type naming.
- **Added**
  - A migration script backfills missing image digests from the registry by repository and tag.
  - The tag backfill task now also resolves and backfills the image digest while backfilling tags, and fixes its hard-coded harbor connection key so registry-type deployments can fetch the connection correctly.
  - When extracting the image tag from build logs, the sha256 is parsed from the successful-push line as the image digest and cached, returned together with backfills and API responses; the fallback backfill prefers the log digest and falls back to registry resolution when absent.
  - Image sync, scan sync and custom-push report now uniformly take the image digest from the registry's current state: a reported digest that differs from the registry's is overwritten by the registry, and an absent one is resolved from the registry; the build and push records views gain an image digest column.
  - The image-tag log cache table gains a status field, custom_push reporting/write-back/log-backfill function, and dual verification of tag name and digest.
  - A configurable display timezone (admin console → Data Management): the frontend renders all UTC timestamps in this timezone; defaults to UTC, with a dropdown of common timezones plus a free-text field for any IANA timezone, validated server-side.
  - A one-off migration script `cli/migrate-timezone.php` converts legacy local-time timestamps in the database to UTC (dry-run by default; `--from-tz` selects the source timezone, `--apply` writes). Run it before upgrading to the UTC-storing release.
- **Optimized**
  - Empty image digests no longer overwrite existing values, supporting historical records that did not report a digest.
  - Image-tag projection now resolves and backfills by pipeline instead of aggregating by commit sha, fixing the collapse where repeated builds of the same commit produced multiple tags but were all wrongly shown with the same tag.
  - Image-tag log backfill now writes only CI-success candidates and follows the registry's current digest: a different digest updates to the registry's, instead of being blindly skipped when an in-house same-name tag exists; identical entries are skipped naturally and the run stays safe to re-run.
- **Changed**
  - Removed the `TZ` setting from `docker-compose.yml` so the container runs in UTC and no longer pollutes the database or PHP timezone.
- **Refactored**
  - Database-related code is split from a single file into several single-responsibility files: schema migration, seeding, data repair, distributed lock, and bootstrap orchestration, for easier maintenance.

## v2.11.3 (2026-11-01)

- **Added**
  - Concurrency regression tests: multiple processes fire real requests in parallel to reproduce and lock in several concurrency fixes (authorization code redeemed twice, terminal build state downgraded, lost login-failure counts, and single sign-on signing key regenerated).
  - Console accounts now enforce a forced password change on first sign-in: a seeded admin flagged as pending change can only reach the change-password and logout endpoints until the password is changed; all other admin APIs are rejected.
  - Deleting a console account now also clears its external identities (LDAP / SSO) in a transaction, avoiding orphaned rows and letting the same external identity rebind.
  - Login-failure counters now increment atomically (expired rows purged first, then atomic increment), fixing lost counts under concurrency on first failure.
  - Authorization-code redemption now takes a row lock and redeems atomically (a one-row delete counts as success), eliminating concurrent double redemption.
  - SSO signing-key generation now takes a cross-process file lock, preventing multiple workers from each generating a key and causing the key id to jitter so downstream JWKS verification fails.
  - Build logs, triggers, write-backs and registry-verification user messages now switch between Chinese and English.
  - Custom-push build-log fetching now pins DNS resolution (closing the rebinding SSRF window) and caps the log body size (truncated when over the limit).
  - Registry tag authenticity is now verified: when a tag is absent from the repository, the authoritative artifact is no longer written and a clear error is returned.
  - Mapping build-source now enforces an allowlist, rejecting unsupported build sources.
  - Permission parent keys now enforce a safe character set, removing the stored XSS injection vector.
- **Optimized**
  - Bootstrap now pins idempotent steps (table creation, column reconciliation, data self-heal, seeding, drift detection) to the schema version, running them only on a version change (deploy); per-request bootstrap SQL drops from a dozen-odd statements to a few.
  - Detailed health checks (external probes) are cached for about 30 seconds; the platform connections page loads the zero-probe static endpoint and reads the registry-type-mismatch flag from the last cached result, removing the synchronous probe that blocked page load.
  - The role-permission list now uses a single batched query grouped by role, removing the per-role N+1 queries.
  - Security-scan search no longer full-scans: the per-request distinct type enumeration is now cached for about 300 seconds, and the audit table gains indexes covering the common equality filters and the default time-descending list.
- **Fixed**
  - Authorization code redemption takes the write lock immediately under SQLite to avoid the concurrent read-then-write deadlock, and a row-count guard keeps a code redeemable at most once.
  - Custom-push terminal success is now irreversible: a concurrent write that would overwrite an already-success record is treated as an idempotent no-op, avoiding duplicate artifacts and notifications.
  - Build report finish-time now validates format, rejecting invalid values that would distort the state machine; pure numeric Unix timestamps (seconds / milliseconds) are also accepted and normalized, no longer wrongly rejected.
  - Registry sync now skips authoritative artifact writes when the tag fails verification, instead of writing a non-existent tag.
  - Mapping and permission lists now escape on render, fixing stored XSS; permission parent keys are validated server-side against a safe character set, blocking the injection vector.
  - Role permission update is now a single transaction (rename / description, permission delete and rebuild), and permission writes no longer swallow exceptions, so the transaction wrapper can actually roll back.
  - Build trigger / retry / cancel audit now records the provider's actual result, so failures are no longer logged as success.
  - Registry tag cache key is now bound to the registry connection endpoint, so repositories with the same name under different registries no longer share a cache.
  - The generic and API password-change entry points now revoke all of the user's sessions after a change, consistent with the dedicated change-password entry point.
  - Mapping resolution's single entry point now rejects non-active mappings, so pending / inactive mappings are no longer resolved and executed.
  - Lost login-failure counts under first-insert race are fixed: a real atomic upsert is now used.
  - Build terminal-timestamp non-atomicity is fixed: the finished_at ordering is folded into the atomic UPDATE condition, avoiding a newer failed / aborted being overwritten by an older one.
  - OIDC private-key lock was not fail-closed: it now throws when the lock cannot be acquired, instead of generating without a lock.
  - Registry sync unverified tag not surfaced: the sync result now returns tag_verified and artifact_written, surfaced in the API response for auditing / frontend awareness.
  - The first build report now inserts first (unique key prevents concurrent duplicates) and falls back to a protected update on conflict, so a later failure no longer overwrites an earlier successful record.
  - When a registry is configured but the tag fails verification, no green commit status is written, no success is written back, and the mapping is not marked successful.
  - Cold-start on an empty database now creates the mutex table first so migration mutual exclusion takes effect; the schema version marker is moved inside the migration lock, avoiding the concurrent re-run window.
  - Backup writes now verify the actual byte count and the archive write result; the backup directory permission is tightened to owner-only read / write and the backup archive file to owner-only read.
  - A path with a non-active mapping now rejects the build-source direct fallback, preventing bypass of the mapping status gate (after upgrade, any path that has a pending / disabled mapping will immediately return 400 on the trigger that previously relied on the Jenkins direct fallback — enable the mapping instead).
  - OAuth access-token account-state lookup exceptions are now fail-closed (only migration-compatibility cases such as missing table / column are allowed through; the same detection used by the token service now also covers SQLite / PostgreSQL table / column absence expressed in the message text).
  - Build report finish-time is now normalized and written back into the report body, so the provider write and the SQL time-order guard see the same format and local timezone (matching the rest of the system's NOW()/date() convention); this corrects the reversed conclusion between string comparison and timestamp parsing that previously caused legitimately newer reports to be silently dropped (no longer mixing UTC with the system-wide local convention).
  - Role creation now runs inside a controller-level transaction (consistent with role update, via TransactionManager); a failed permission write rolls back entirely. A duplicate name that hits the unique key now maps to 409 instead of 500, avoiding a half-created role.
  - Backup file names now carry a random suffix, avoiding two concurrent requests contending for the same file.
  - Deleting an implied rule now prunes only the permissions derived solely from that rule and not explicitly granted, with cycle detection added; prune errors are no longer swallowed.
  - OIDC auto-seed now records its permanent marker only after the cd client is successfully created, so a fresh install whose first boot runs before env is ready retries on the next boot instead of staying un-seeded; seeding also removes a cd-service row mistaken for an OIDC client.
- **Security**
  - Session revocation after password change / reset was silently failing: the escape syntax error is fixed so old tokens are correctly invalidated after a password change.
  - Custom-push log URL SSRF protection tightened: address safety checks are stricter (unparseable addresses rejected, loopback / link-local / unique-local / multicast / embedded-IPv4 handled), resolved addresses are pinned to close the rebinding window; unique-local addresses (both ranges) are rejected as untrusted, closing the earlier gap that only blocked one range; IPv6 literals are bracketed for libcurl; the response body is streamed and truncated at the size limit instead of being fully buffered.
  - SSO userinfo, identity token and email endpoints now filter sensitive claims by authorization scope, and the access token records the granted scope: the role claim is returned under the profile scope and groups under the groups scope (clients that only request openid profile email still receive role).
  - The email_verified flag is now returned true only when a verification mechanism actually exists (none today, so it is always false).
  - Registry Bearer auth realm host must now share the origin with the registry host, refusing to forward base credentials to an arbitrary host.
  - Token account-state lookup exceptions are now fail-closed (only migration-compatibility cases such as missing table / column are allowed through), avoiding disabled / downgraded accounts keeping working sessions.
  - RBAC hardening: an implied-rule target must be a permission the caller already holds (preventing privilege escalation to system / platform-config via rules); deleting an implied rule now prunes only the permissions derived solely from that rule and not explicitly granted, with cycle detection added to avoid wiping a role or dropping explicit grants; role create / update enforces the privilege-escalation ceiling that a non-super-admin cannot grant permissions it does not itself hold.

## v2.11.2 (2026-10-30)

- **Fixed**
  - A fresh database now seeds its single sign-on configuration from environment variables on first start (issuer, token lifetime, client whitelist); no manual migration is needed and existing rows are never overwritten.
  - An unusable SSO signing key now fails loudly with fix instructions (non-PEM value, unreadable key file, key that cannot be persisted) instead of silently falling back to a temporary key.
  - Uncaught exceptions are always logged: 5xx entries record the exception class, location, request method and path, and the stack trace.
  - Bootstrap notices are deduplicated by state: the leftover connection / SSO environment keys, signing-key problems, data-table list drift and the unchanged initial password are each reported once per state, and again only when that state changes.
  - The leftover-key notice no longer advises deleting everything after migrating: it states that CD still reads the client secret and the redirect URI in a joint deployment.
  - Login failure messages now carry actionable fix instructions (run the migration script or add the client in the console).
  - Duplicate build reports (custom_push / commitStatus) no longer stack repeated rows in the security audit table: a unique index on (project, sha, check_type, context) turns the upsert into a true upsert, and existing duplicate rows are deduplicated during migration.
- **Improved**
  - The SSO migration script and the first-start auto-seeding share a single implementation.
  - Build, Git platform and registry user-facing messages now switch between Chinese and English.
- **Docs**
  - The User Manual gains sections 9 and 10: signing into Devops-CD with a Glue account, and migrating from environment variables to the console (steps and common misreads).
  - The FAQ gains five entries: why the CD owning-system key is optional, the login checklist for a fresh database, the three login-failure messages, CD not enabled / not deployed, and how to stop the repeated startup notice.
  - Config files no longer contain the CD client's owning system; the root config and CD's example config are aligned.

## v2.11.1 (2026-10-27)

- **Refactor**
  - The admin and build controllers are split apart, database access moves into dedicated repositories and services, and write operations go through one transaction manager; URLs, HTTP methods, permission checks and response structures are unchanged.
- **Fixed**
  - When the image registry does not support scanning, the audit state is normalized from the build result to success / failed / pending instead of "unknown".
  - The platform version page shows the registry row's type suffix based on the actual registry type.
  - The health check returns the probed distribution version in standard-registry mode instead of the fixed string "registry".
  - The custom_push build report endpoint writes a security audit row for all three terminal states (success, failed, aborted).
- **Improved**
  - nginx resolves the php-fpm upstream dynamically via Docker's built-in DNS: addresses refresh after scaling up or down or a container recreation, with passive failover when failed requests pass the threshold.
  - Container log rotation is centralized; nginx is upgraded with reduced container capabilities, plus a health endpoint and gzip.
  - Connection settings already migrated to the database are removed, along with the configuration template file.
  - A registry type that does not match the service behind the address shows a "registry type mismatch" warning in the health check and the platform-connection card and logs an error, without blocking the save.
- **Changed**
  - The registry connection row follows the actual registry type; switching the type migrates the row and keeps its credentials.
  - A one-off script cli/migrate-registry-platform.php moves legacy Harbor-labelled rows that are really standard registries to the standard-registry type (idempotent).

## v2.11.0 (2026-10-24)

- **Added**
  - The image registry type is selectable: Harbor (default) or a standard OCI registry (`registry:2` and the like), one of the two per instance, never both. A standard registry offers no vulnerability scanning, so the related pages hide the matching columns and actions.
  - A new write-back endpoint `POST /api/build/{path}/registry-sync`: CI reports a pushed image back to Glue, no scanning capability required, so a standard registry records the push result and writes the Git commit status as well.
  - The integration-service contract endpoint delivers the image registry type and address: consumers such as CD no longer maintain a local copy, only the username and password, and fall back to the local configuration when the endpoint is unavailable.
  - Tag queries return tag details instead of names only, together with the scan conclusion.
  - The tag endpoints return paginated responses; with neither pagination parameter passed the previous array response stays unchanged, so existing consumers such as CD's registry sync need no change.
- **Changed**
  - The image-registry field on mappings, build and deploy payloads, and the admin pages is renamed from `harbor_repository` to `registry_repository`; the automatic migration moves the old values to the new field and then drops the old one.
  - `scan-sync` and `registry-sync` share one write-back implementation and differ only in whether scan results are read; `scan-sync` remains the entry point for existing Harbor setups.
  - A write-back audit record is no longer limited to pipelines that succeeded: when CI reported the image but the pipeline failed or never finished, the record is kept, while the image artifact is recorded only for successful pipelines and only those count as a push.
  - The registry type and address from the contract are delivered only to credentials holding the "Harbor read" permission.
  - The health check for a standard registry probes `/v2/`; both registry types share one connection entry, and switching the type only changes the access protocol.
  - Audit records of the two write-back endpoints are tagged `harbor-scan` / `registry-sync`; the audit page recognises both, so earlier records stay visible.
  - The image-registry query scope is renamed from `harbor.read` to `registry.read`; existing tokens are migrated automatically at startup and need no reissue.
- **Fixed**
  - A registry path with more than two segments is accepted; the earlier limit of two segments is gone.
  - The registry query endpoints reject an invalid project or repository name with `400` and a message.
  - Commit-status write-back sends the state each platform accepts: failures are normalized to `failure`, and without a scan conclusion the state is derived from the pipeline result.
  - A standard registry with an empty password is treated as anonymous and sends no credentials, so the default username no longer breaks anonymous pulls.
  - The project list always returns project names as text: a purely numeric first segment is no longer converted to a number, matching the documented structure.
  - Dropping the renamed-away field is best-effort on older SQLite versions that cannot drop a column: a warning is logged instead of aborting the startup migration, and the leftover field is harmless.
  - Two missing UI texts no longer show the raw translation key (the login-token failure message and the implied-permission dropdown).
  - Messages with parameters render correctly: the placeholder braces used to stay in the text, and same-named words elsewhere in the sentence were replaced by mistake.
  - Harbor version detection no longer falls back to the v1 endpoint on a network error: a single timeout used to be cached as "v1" for a full hour.
  - A platform address may be entered as `host[:port]` without a scheme: Harbor is normalized to `https` and a standard registry to `http`, so the request no longer fails on a schemeless address.
  - The Jenkins health check now judges connectivity by the response status code: only a status code below `500` counts as reachable.

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
