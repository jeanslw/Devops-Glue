# User Manual

> Written for backend administrators and daily users, this manual covers the main functions of Devops-Glue from a **user's point of view**. Menu names and button labels match the admin UI so you can follow along step by step. It focuses on **how to use** things, not how they work inside. For detailed configuration, please see the Admin Configuration Manual.

## Contents

1. [Application Settings](#1-application-settings)
2. [Mapping Management](#2-mapping-management)
3. [Build Mode](#3-build-mode)
4. [User Management](#4-user-management)
5. [Permission Management](#5-permission-management)
6. [API Management](#6-api-management)
7. [Admin Pages Overview](#7-admin-pages-overview-version-compatibility--security-audit--monitor-overview--build-records--platform-management--log-center--data-management)
8. [LDAP Login](#8-ldap-login)
9. [Signing into Devops-CD with a Glue account](#9-signing-into-devops-cd-with-a-glue-account-fresh-db-to-working)
10. [Migrating from env vars to the console (UI first)](#10-migrating-from-env-vars-to-the-console-ui-first)

---

## 1. Application Settings

After installation and login (default address `http://localhost:8080/admin`), the first step is **connection configuration** — letting the system reach your Jenkins, Git platforms, and the image registry (Harbor or a standard `registry:2`). Since v2.10.0 these credentials are maintained on the admin "System Settings → Platform Management → Platform Connections" page (no longer in env; migrate old env values once with `cli/migrate-platform-connections.php`). This section explains what each item means and the key points.

### 1.1 Jenkins (CI build source)

On the Platform Connections page, fill in the **Jenkins** row:

- **URL**: your Jenkins address, e.g. `http://your-jenkins:8080`. Mind the protocol and port.
- **Username**: a Jenkins account with sufficient rights — a dedicated bot account is recommended over a personal one.
- **Token**: an **API Token** generated in Jenkins (Profile → Credentials / Token), **not** your login password. Using a token is safer.

> **Key points**: A token is not a password — generate a dedicated API Token in Jenkins. The account only needs routine build rights (read jobs, list branches, trigger builds, check status); no admin rights required.

### 1.2 Git Platforms

The system supports GitLab, GitHub, Gitee, and Gitea (custom platforms can also be added). On the Platform Connections page, fill in the **address and token** for each platform:

| Platform | Meaning | Key points |
|---|---|---|
| GitLab | GitLab address and access token | For self-hosted GitLab, use the intranet address; use a personal token with `api`/`read_api` scope |
| GitHub | GitHub address and access token | The address defaults to `https://api.github.com`; use a Personal Access Token |
| Gitee | Gitee address and access token | The address defaults to `https://gitee.com/api/v5` |
| Gitea | Gitea address and access token | For self-hosted Gitea, use the intranet address and a Gitea API token |

> **Key points**
> - Each platform token only needs the common rights: read repositories, list branches, write commit status.
> - Self-hosted GitLab/Gitea domains usually contain no platform keyword, so the platform may not be auto-recognized. Set `DEFAULT_GIT_PLATFORM` in `config/app.env` as a fallback (this key still lives in env and is not migrated).

### 1.3 Image Registry (Harbor / standard registry)

On the Platform Connections page, pick the **Registry Type** in the **"🐳 镜像仓库"** row first (Harbor by default; choose `registry:2` for a standard OCI registry), then fill in the **URL / username / password** (leave the username and password empty on a standard registry to pull anonymously).

- **Standard OCI registry (`registry:2`)**: there is no robot-account concept; use HTTP Basic username / password when the API call needs authentication. Vulnerability scanning is not available (the related columns and actions are hidden automatically).

When you use Harbor, there are two account types and the key point depends on your Harbor version:

- **Robot account (Harbor v2.2.0 and later) — recommended**
  - Create a robot account in the Harbor project and grant the needed permissions (at least **read / list tags**; add scan rights if you want to trigger scans).
  - Fill the robot account's **username and token** into the username / password fields.
  - Robot accounts are safer than normal accounts and are recommended.

- **Regular account (before v2.2.0)**
  - Older Harbor has no robot accounts, so use a normal user.
  - Make sure the user has at least **read (pull / view tags)** rights on the relevant projects; add push/scan rights only if you need to trigger image scans.

> **Key points**
> - Whatever the account type, follow the **least-privilege** rule: read-tag / list-project is enough for daily use. Grant scan rights only when the system should trigger vulnerability scans; avoid using an admin account.
> - The account must be able to access **all** image projects the system will use.

---
## 2. Mapping Management

"Mapping Management" links a **CI job (Jenkins Job / GitLab CI / Gitea Actions)** with a **Git repository** and a **Harbor image repository**. All data shown in the "Monitor" pages — builds, image tags, scan results — is generated from these mappings.

### 2.1 Auto-discovery

The system automatically scans the enabled CI platforms and adds discovered jobs to the mapping list (initially **Pending** — it does not enable them on its own).

Steps:
1. In the sidebar, open **Mapping Management**.
2. Click **🔍 Discover / Auto-discover** and wait for the scan to finish.
3. A batch of **Pending** records appears. Find the job you want and click **✅ Enable**.

> **Note**: When multiple CI sources are enabled, the same repository may be discovered by several sources (e.g. both Jenkins and Gitea pointing at one repo). Only one entry per repository can be **enabled** at a time; enabling one hides the other entries for the same repo. This is the normal de-duplication behavior.

### 2.2 Adding a mapping manually

Steps:
1. In the sidebar, open **Mapping Management** → click **➕ New**.
2. Fill in the form:
   - **Job name (required)**: the full CI path, e.g. `java/registry`.
   - **Build source**: `Jenkins` / `GitLab CI` / `Gitea Actions` / `Custom_Push` (default Jenkins).
   - **Git platform**: `gitlab` / `github` / `gitee` / `gitea`. The system also detects it from the repo URL, falling back to the default platform when it can't.
   - **Git Remote**: the repository URL; if omitted, it is auto-fetched from the job's SCM config.
   - **Harbor repo**: the image repository path in `project/repository` form, e.g. `mycode/code-runtime`.
3. Click **Save**.

> Most fields are optional and auto-derived by the system. The only required field is **Job name**.

### 2.3 Linking an image registry repository

Fill in the **Registry repo** field with a repository path of at least two segments (e.g. `project/repository`, `team/project/image`). Once linked, when the system displays image tags for this job, it checks the real tag list of that repository in the image registry.

> **Note**: The repository must actually exist in the image registry, and the value must be at least two segments with no scheme, host or port. Accurate linking is required for correct image-tag association and write-back later on.

### 2.4 Disabling / Deleting a mapping

- **Disable**: demotes an enabled entry back to **Pending** so it is no longer included in daily monitoring (kept, not deleted).
- **Delete**: removes the mapping entirely. A confirmation dialog appears first.

Each row also has **✏️ Edit** to modify these fields anytime.

---

## 3. Build Mode

This is where you configure which CI sources the system **polls**, plus options related to image tags.

### 3.1 Configuring the three CI sources

The three CI sources are controlled by the **Build Mode** multi-select: **Jenkins**, **GitLab CI**, and **Gitea Actions**. Select any combination:

- ⚡ **Jenkins**
- 🐺 **GitLab CI**
- 🦎 **Gitea Actions**

> **Key points**
> - The selection takes effect immediately — the system runs auto-discovery and polls build status from the selected sources.
> - Deselecting a source demotes its non-matching enabled mappings to **Pending** (they are not deleted and can be re-enabled later).

### 3.2 Image-tag write-back (scan-sync / registry-sync)

When a build produces an image and pushes it to an image registry (Harbor or a standard `registry:2`), the system can perform two automatic "write-back" actions:

1. **Image-tag linking**: the system links a build to the real tag of its registry repository, so the Monitor Overview / Build Records show which image tag this job's build produced.
2. **Commit status write-back**: the write-back result (success / pending / failed) is written as a commit status on the linked Git repository, so your CI / git hosting platform shows the outcome directly. For Harbor, the image vulnerability scan report is read as well, giving success / failed / not scanned.

Pick the endpoint by registry type:

| Endpoint | Registry type | Reads the scan report | Audit check_type |
|---|---|---|---|
| `POST /api/build/{path}/scan-sync` | Harbor | Yes | `harbor-scan` |
| `POST /api/build/{path}/registry-sync` | Harbor, registry:2 | No | `registry-sync` |

> **Key points**
> - This depends on correct mappings (Ch. 2): only when a job is linked to both a Git repo and an image registry does write-back have a target; `registry_repository` is a name with at least two segments (e.g. `project/repo`, `team/project/image`).
> - Both endpoints require the **API Token scope** `build.report`; `scan-sync` additionally needs scanning enabled on the registry side when reading the scan report.
> - The two endpoints share one write-back implementation and differ only in whether the scan report is read; the audit record's `check_type` is set by the endpoint you call (`scan-sync` → `harbor-scan`, `registry-sync` → `registry-sync`). Use `registry-sync` for `registry:2` — calling `scan-sync` still writes back, but the audit record is then labelled `harbor-scan`.

### 3.3 Enabling Custom_Push

**Custom_Push** is a **push / reverse mode**: instead of the system polling your CI, your existing CI (Jenkins Script / Drone / TeamCity, etc.) pushes the **final build result + image tag** to the system after building; the system only receives and records it. Use it when you already have your own CI orchestration and don't want the system involved in triggering.

How to enable:
1. In the sidebar, open **Build Mode**.
2. Turn on the **📤 Enable Custom_Push** switch.
3. An admin registers the custom push sources in `build.custom_providers` in `config/settings.php` (done at deployment/configuration time).

> **Key points**
> - Enabling auto-discovers a `custom_push` mapping candidate for each Git project (initially **Pending**, enable manually).
> - Turning the switch off demotes enabled Custom_Push mappings to **Pending**.
> - Typical use: you already use Jenkins/Drone/TeamCity as the build backbone, and only want Devops-Glue to keep a record of builds and image tags for the CD service to read.

### 3.4 Cleaning up stale tags

> **First, understand the origins of image-tag records and their trust levels** — 3.4 / 3.5 / 3.6 are three stations on one pipeline:
>
> | Origin | Description | Trust |
> |---|---|---|
> | **Scan write-back (scan-sync)** | Tags written explicitly by the Harbor image-scan callback (see 3.2) | **Authoritative** (canonical); consumed directly by the deployment layer |
> | **Registry write-back (registry-sync)** | Tags written by the callback after the image is pushed (see 3.2; used when the registry has no scanning) | **Authoritative** (canonical); consumed directly by the deployment layer |
> | **Log-derived** | Tags the system **guesses** from build logs using the "push-success keyword" while pulling build records (see 3.6) | **Candidate**; used for display and backfill only |
>
> The closed loop: **3.6 keyword** decides what counts as a successful push in a log and produces candidate tags → **3.5 backfill** verifies candidates against the registry and promotes them to authoritative records (written only when the registry explicitly confirms the tag exists; never overwrites write-back results) → **3.4 cleanup** removes authoritative records whose tags the registry has already deleted, so the deployment layer never reads a dead tag. All three treat the **image registry as the single source of truth**; their switches are independent of one another.

The **Build Mode** page has a **🧹 Enable stale tag cleanup** switch.

- **What problem it solves**: internal tag records go "stale" as Harbor prunes images (retention policies, manual deletion) — the record still exists but the image is gone. A stale tag read by the deployment layer is a release that is guaranteed to fail. This feature treats **Harbor as the source of truth** and removes internal tag records that no longer exist in Harbor (the authoritative tag table and the related build-log rows are deleted in one transaction — never half-way).
- **When to use**: when Harbor prunes tags routinely (e.g. a "keep last N tags" retention policy) or images are deleted manually. **A static repository with no retention does not need it** — its internal records never go stale.
- **How to use**:
  - **Automatic**: with the switch on, the system compares and cleans at suitable moments (e.g. while handling build records) — no per-tag manual deletion;
  - **Manual**: once the switch is on, the **▶ Clean up now** button runs a cleanup immediately (equivalent to running `php cli/cleanup-pipeline-tags.php`, for deployments without cron / CLI access); each run is recorded in the operation log under the action `cleanup_pipeline_tags`.
- **Safety boundary**: if Harbor is temporarily unreachable during a comparison, the whole batch is **skipped, not deleted** (better stale data than acting on incomplete information); records with missing fields are skipped as unverifiable. The only way to wrongly delete is "Harbor itself returns a wrong tag list", which normal operations never produce.

### 3.5 Backfilling image tags from build logs

The **Build Mode** page has a **🔄 Enable image tag backfill** switch and a **▶ Backfill now** button.

- **What problem it solves**: scan write-back (3.2) requires the Harbor scan callback to actually arrive — and real life has gaps: no callback configured, an unreachable callback URL, or the scanner being down at that moment. The result: **the image was pushed successfully but the authoritative record has no tag**, so the deployment layer cannot see that version. "Backfill" takes log-derived candidate tags (see 3.6), **verifies each one exists in Harbor**, and then writes them into the authoritative records — a safety net for scan write-back, so versions that were "pushed but whose callback was lost" do not vanish from the system.
- **Trust boundary (why it is safe to promote into the authoritative table)**: a tag is written **only when Harbor explicitly reports it as existing** — the same trust level as scan-sync, never a blind trust in log regexes. It is also written **only when the pipeline has no authoritative tag yet**, never overwriting scan-sync results; safe to re-run (already-backfilled items are skipped naturally).
- **When to use**: right after onboarding, when historical builds had no scan callbacks — enable the switch and click **Backfill now** once to bulk-recover historical versions. Afterwards you can leave it on for automatic coverage, or run it manually only when needed.
- **Reading the result**: `Backfill complete: checked {checked} candidates, backfilled {promoted}; skipped {skipped} (already tagged), {unreachable} (Harbor unreachable), {unverifiable} (incomplete fields)` — `promoted` is what was actually written; `unreachable` usually means Harbor was briefly down, just re-run later; the run is recorded in the operation log under the action `tag_backfill`.
- **Note**: backfill depends on candidate tags being derivable from build logs (a 3.6 keyword hit). If your CI log format is unusual and nothing is derived, adjust the 3.6 keyword and re-run the backfill.

### 3.6 Build-log image keyword

The **Build Mode** page has a **🔍 Build-log image keyword** input (default `digest`).

- **What problem it solves**: the biggest risk of log-derived tags is "treating a failed build as a successful one" — a log line mentioning the image does not mean the push succeeded (it may be an echo right before a `docker push` error). The keyword is the **criterion for push success**: a candidate tag (`repo:tag` / `-t TAG`) is extracted only when the build log **first hits the keyword** (interpreted as "the push really succeeded") **and contains this project's Harbor repository path**. The default `digest` matches the characteristic line of a successful `docker push` output (`digest: sha256:...`).
- **Configuration**: separate multiple keywords with `|` (any hit counts), case-insensitive; **empty = no filtering** (extract whenever the log contains the repository path — advisable only if your logs never echo failed pushes).
- **When to adjust**: when your CI output differs from standard docker (e.g. a homegrown build tool prints `PUSH OK` on success), set the keyword to `digest|PUSH OK`; when backfill (3.5) keeps reporting `unverifiable` / finds no tags, check first whether this keyword matches your logs' success output.
- **What it affects**: the keyword only governs the "Image Tag" column of **pull-mode** build records (the log-derived candidates); scan write-back (3.2) bypasses logs entirely and is unaffected.

### 3.7 Log settings (API access logs / operation logs)

The **Build Mode** page has a **📋 Log Settings** card with three items: **write level**, **retention days**, and **scheduled cleanup**.

- **What problem it solves**: API access logs are stored per request (one row per API call); under heavy traffic the table grows without bound and eventually slows queries and fills the disk. Log settings turn "what to record" and "how long to keep" into adjustable policies, so log volume matches your audit needs instead of unconditionally keeping everything forever.
- **Write level** (governs only what **API access logs** are persisted; file logs are always complete):
  | Level | Persisted | Suited for |
  |---|---|---|
  | All (success + denied + failure) | every request | full audit trails (who called which API, when) |
  | Denied + failure only | only 401/403/404-style rejections and 5xx | **recommended**: audits care about abnormal calls; successful traffic need not occupy the table |
  | Failure only | only 5xx | caring only about the system's own faults |
  | Off | nothing persisted | when no API-level audit is needed at all |
- **Retention days**: 90 / 60 / 30 days or custom (1–3650). Expired rows are removed daily by **scheduled cleanup** — which applies **to both API access logs and operation logs** (they share this retention policy).
- **Scheduled cleanup switch**: on by default. Turning it off keeps both kinds of logs **forever**; advisable only with external archiving (e.g. regular exports to a log platform).
- **Not covered**: **deployment logs** (owned by the CD system) and **build logs** (owned by the CI source) are unaffected by these settings.
- **Relation to the operation-log page**: retention / cleanup here decides how long the data **lives**; the admin "Log Center → Operation Log" page decides **how you query it** — query capability is unaffected by the write level (though under "Off" the API access log page naturally has no new data to show).

---
## 4. User Management

"User Management" controls **who can log in**, **which role they belong to**, and **what they can do**. It has three parts: User List, Role Management, and Change Password.

### 4.1 Creating a role

A role is a set of permissions. Assign permissions to the role, then add users to it — that completes the authorization.

Steps:
1. In the sidebar, open **User Management → Role Management**.
2. Click **New role**, and enter a role name.
3. In the permission list, check the permissions this role can access (see Ch. 5).
4. Save.

> **Key point**: the system ships a single built-in role `super_admin` (super administrator) that has **all** permissions and **cannot be deleted**. Other roles (such as admin / deployer / viewer) are created and configured by you.

### 4.2 Creating a user

Steps:
1. In the sidebar, open **User Management → User List**.
2. Click **New user**, enter the account and password, and pick a role.
3. Save.

> **Key points**
> - A user must have a role to get permissions; a user without a role sees no relevant menus in the backend.
> - The built-in `root` account is protected: it **cannot be deleted or modified**.
> - Disabled accounts cannot log in anymore (including via LDAP).

### 4.3 Changing a password

Logged-in users can change their own login password under **User Management → Change Password** (the old password must be entered to verify).

### 4.4 Disabling / Deleting a user

- **Disable**: temporarily blocks the account from logging in while keeping the record; it can be re-enabled later.
- **Delete**: removes the user entirely.

Both actions ask for confirmation, so be careful not to misclick.

---

## 5. Permission Management

"Permission Management" is a layer below roles — it manages **permissions themselves**. It has three parts: Permission List, Permission Registration, and Implied Rules.

### 5.1 What the built-in data does

On install, the system seeds a set of **built-in permissions** (view mappings, trigger builds, view logs, manage notifications, etc.) and built-in implied rules. They make the default functions map one-to-one to permissions — the "works out of the box" foundation.

- **Built-in permissions** are shown in the Permission List, shipped with the system, and **cannot be deleted**.
- **Built-in implied rules** are also undeletable.
- The list marks each entry as **Built-in** or **Registered** and shows its creation time, so you can tell what shipped with the system vs. what was added later.

### 5.2 Permission registration

When a new backend menu or feature needs its own permission to control who can access it, you can register a new permission key here **without writing code** (optionally under a parent key for tree display). Once registered, the permission becomes available for assignment under Role Management.

**When to use**: when your team extends functionality and wants a dedicated permission to control its access scope.

### 5.3 Implied rules

Implied rules define automatic inheritance — "checking A automatically grants B" (child → parent direction).

Example: a rule "checking `cd.bot` implies `cd.notification-manage`". When you check the former, the latter is granted along with it, so the notification management menu shows up correctly — no need to check each one manually.

**When to use**: whenever two permissions always go together and should be granted simultaneously, an implied rule saves effort and prevents omissions. User-created implied rules **can be deleted**; built-in ones cannot.

---

## 6. API Management

"API Management" issues **API Tokens** for third-party services / scripts (such as the Devops-Glue CD service or Jenkins scripts). An API token is independent of the backend account system and directly carries a set of interface scopes, so access is easy to grant, adjust, and revoke at any time. Only `super_admin` can create / edit / revoke / delete tokens.

This page also hosts the **Integrated services** card (above the token list): enabling an integrated service auto-issues a restricted service token here, and disabling it revokes that token — both live on one screen so the origin of that token is obvious. The switch is the **Enable** checkbox on the card (see 6.5).

### 6.1 Creating an API token

Steps:
1. In the sidebar, open **API Management**.
2. Click **New / Create**, and enter a name (e.g. `CD service account` / `Jenkins scan write-back`).
3. Check the scopes you need and optionally set an expiry time.
4. Save.

> ⚠️ The token plaintext is shown **only once** on creation — copy and save it immediately; the full token cannot be viewed again afterwards.

### 6.2 Scopes

| Scope | Purpose |
|---|---|
| `main` | Read-only: job list, mappings, git platforms, git discovery |
| `git` | Read-only: git branches / tags (includes the combined `/api/git/{path}/refs`, used by CD's build page to pick a ref) |
| `registry.read` | Read-only: Harbor projects / repositories / tags; also all `/api/registry/*` endpoints for **both** registry types (`harbor` / `registry:2`). Not included in the CD service credential |
| `harbor.scan` | Trigger Harbor image scans |
| `build.read` | Read-only: build pipelines / logs / branches |
| `build.write` | Write: trigger / retry / cancel builds |
| `build.report` | Write-back: scan-sync / commit-status / report |
| `rbac.user.write` | CD service-account management: create/read/update/delete users and list roles via `/api/rbac/*` |

> **Recommendations**
> - When authorizing a third-party service (especially CD), check the minimal set of scopes it actually needs — don't select everything.
> - To let the system do "scan write-back", check both `harbor.scan` and `build.report`.
> - Set an expiry time as needed; never-expiring is convenient long-term but keep an eye on security.

### 6.3 Editing a token's scopes

Authorization is not a one-off: **an already-issued token's scopes can be changed** without re-issuing it. Find the token in the "API Management" list, click "✏️ Edit Scopes" on its row, re-tick the scopes and save.

1. List → **✏️ Edit Scopes** on the target token (revoked / expired tokens can be edited too).
2. Tick / untick scopes (the form pre-ticks the **current** scopes).
3. Click **Save**; the list refreshes with the new scopes.

Key points:

- **Scopes only, same token**: the plaintext token and its digest stay unchanged, so tokens already configured in CD / Jenkins scripts **keep working** — the **next request** is checked against the new scopes.
- **Typical uses**: temporarily add `harbor.scan` / `build.report` to a write-back script and remove them afterwards; or trim an over-privileged token down to the minimum.
- **At least one scope must remain**: clearing everything is rejected ("Select at least one scope"), and values outside the catalog are rejected too — no silent "I thought I had removed/granted that". Use **Revoke** to make a token unusable entirely.
- Every edit is recorded in the **operation log** (action `update_api_token_scopes`, including the stored scope list).

> Example: the token used by a Jenkins scan write-back script only has `main`; when it should also report scan results back to Git, edit that token, tick `harbor.scan` and `build.report`, and save — the script's token needs no change at all.

### 6.4 Revoking / Deleting a token

- **Revoke**: immediately disables the token while keeping the record and its sha256 digest (good when you suspect a leak or want a temporary pause). There is no "re-enable" entry point on this page, so for an **ordinary token** it is equivalent to burning it — issue a new one and update the caller instead.
- **Delete**: removes the token record entirely (digest included); not recoverable.
- **The integrated-service `cd-service` token is a special case**: its lifecycle is owned by the **🔌 Integrated services** card (see 6.5) — **unticking the card = freeze** (ticking again restores the *very same* token, no CD change), while manually ⛔ revoking / 🗑 deleting it = **burn** (ticking again issues a **new credential** that CD must adopt).

For day-to-day: use **Revoke** for temporary suspension, **Delete** when it is clearly no longer used.

### 6.5 Integrated services (auto-issued service token)

Above the token list there is also an **🔌 Integrated services** card; currently only one service is supported: `CD deployment service`. It governs "is Devops-Glue CD authorized to run against this platform's shared database":

- **How to toggle**: the **Enable** checkbox on the card is the switch (same interaction as the switches on the Platform Management page) — **tick = enable** (issues the service token in the same transaction), **untick = disable (freeze)**. Both directions ask for confirmation first, and cancelling that dialog or a failed request reverts the checkbox to its previous state.
- **Status**: ⚪ not enabled (if the credential is merely frozen with the service, the card adds a line saying re-enabling restores the same token) / ✅ enabled (auto provision) / ✅ enabled (manual provision) / ⚠️ enabled but credential dead (red; the checkbox then falls back to unticked — see below).
- **Provision mode (`provision`)**: captured from this platform's **current migration mode** ("Data Management → Migration mode") **at the moment you tick Enable** — **switching the migration mode afterwards does not change it**. While not enabled the card shows "Provision mode when enabled", i.e. what would be written if you enabled now.
- **Enable (first time)**: tick **Enable** on the right of the card and confirm; in a single transaction it issues a restricted **service token** (`build.read` + `build.write` + `git` + `rbac.user.write`; no admin endpoint scopes), whose plaintext is **shown only once** — paste it into CD's `CI_API_TOKEN`. That token (named `cd-service`) appears in the list below right away.
- **Enable (restore)**: if the previous action was **Disable (freeze)**, enabling again does **not** issue a new token — the platform simply re-activates the *very same* one, whose original plaintext keeps working, so **CD's `CI_API_TOKEN` needs no change** (the confirm dialog says "♻ restore" and no plaintext popup appears).
- **Disable (freeze)**: untick **Enable** and confirm; CD immediately rejects every request and the paired service token is **disabled** (`enabled=0`) in the same transaction. **Disabled ≠ burned**: both the credential record and the binding are kept, CD's tables and data are **not** deleted, and **enabling again restores the very same token** (the original plaintext keeps working — no CD change).
- **Permission**: this page is `super_admin` only, and enabling / disabling is likewise `super_admin` only.
- **You can enable again after the credential was revoked / deleted (re-issue)**: if the `cd-service` token is ⛔ revoked (while the service stays enabled) or 🗑 deleted in the list, the service goes offline immediately — the card turns red ("⚠️ Enabled, credential dead") and the checkbox falls back to **unticked**. **Tick Enable again and confirm to re-issue a new credential in the same transaction** (the old one stays burned and never comes back) — no need to untick first. The confirm dialog flags this as "⓪ re-issue"; remember to update CD's `CI_API_TOKEN` with the new plaintext.
- **To burn / rotate the credential on purpose**: ⛔ revoke or 🗑 delete that `cd-service` row in the list, then tick **Enable** on the card to have a new token issued (and update CD). **To merely pause it**, untick the card instead (freeze — the credential is left untouched), so re-enabling needs no CD change at all. Mixing the two up costs you either "CD must be reconfigured" or "the old credential comes back to life".
- **CD's authorization self-check**: CD can skip reading the shared database and use the narrow endpoint `GET /api/integration/services/cd` to learn whether it is authorized and with which provisioning mode (requires the `build.read` scope, already included in CD's service token; not enabled returns 200 + `enabled=false`, while only a mistyped service name returns 404 — the two must never be conflated). Both channels read the same contract data, so switching does not affect the card's state display.

---
## 7. Admin Pages Overview (Version Compatibility / Security Audit / Monitor Overview / Build Records / Platform Management / Log Center / Data Management)

These pages are mostly **for viewing**. Here is a brief overview of each; details are in the Admin Configuration Manual.

### 7.1 Version Compatibility

Shows the versions of the services this system integrates with (Jenkins / GitLab / Harbor, etc.). Use it to confirm that the integrated services are within the supported range, and to check version compatibility when a feature seems not to work.

### 7.2 Security Audit

Records the system's image **scan / write-back** history (time, project, type, status, image tag, commit SHA, write-back result, etc.). Use it to trace each scan and commit write-back — e.g. when a scan result doesn't show up on Git, check here. It supports filtering by write-back status (success / failed / not written).

### 7.3 Monitor Overview

The dashboard's home view: total mappings, active projects, number of git platforms / image repositories, and the connectivity status of each service (CI / Git / Harbor). A quick look at the system's overall health and scale.

### 7.4 Build Records

"Build Records" (sidebar → Build Records) has two sub-sections:

- **Pull records**: build records the system actively polls from Jenkins / GitLab CI / Gitea Actions — pipeline, status, duration, image tag, log link, etc.
- **Push records**: build records that your CI reports via Custom_Push.

Use it to review the final results and artifacts (image tags) of historical builds — the place to answer "which image did this build produce?". The menu is visible only with the appropriate permission.

### 7.5 Platform Management Page (System Settings)

> Path: sidebar **System Settings → Platform Management** (page ID: `platform-config`). Cards on this page are shown or hidden by role: without the matching permission the card container is not rendered at all.

This page centralizes "which external systems the CI platform integrates, and in which mode it runs". Each card's function and permission gate (matching the actual backend checks — don't guess from the UI):

**① Build Mode (multi-select)** — `ci.mode.edit`
- Choose which build providers are enabled: `Jenkins` / `GitLab CI` / `Gitea Actions` / `Custom_Push` (push-style CI where your pipeline reports the final build state once). Changes take effect immediately and are stored in `ci_app_settings.build_mode`; the `build_provider` field in mapping management follows this list.
- **Problem it solves**: gradually migrating your CI system (e.g. Jenkins → GitLab CI) — old and new jobs coexist and can be switched one by one, with no downtime.

**② Platform Connections (v2.10.0)** — `ci.platform-config`
- A table listing the **address and credentials** of the six platforms — Jenkins / GitLab / GitHub / Gitee / Gitea / Harbor — one row each: URL, username, token / password. Token- and password-type fields show only "configured / not configured", never the plaintext; leave a field empty to keep its current value. A dot plus "✓ configured / ✗ not configured" in the top-right shows whether the row is complete; each row has a "Delete" button, with "Save" at the bottom.
- **Credentials are stored in the DB (`ci_platform_connections`) and encrypted with `DATA_ENC_KEY`**: changes here write to the DB and take effect immediately — no env edit or restart needed. Older env connection values are migrated once with `php cli/migrate-platform-connections.php`.
- **Problem it solves**: with several platforms in parallel, quickly add or change a connection; when a webhook / build trigger fails, look here first (is it configured, is the value right) instead of digging through env files.

**③ OIDC / SSO Config Card (v2.10.0)** — `ci.platform-config`
- Maintains the three SSO items: **issuer** (the externally reachable full URL — must be set explicitly behind a reverse proxy), **id_token TTL** (seconds), and the **client whitelist** (client_id + redirect_uri + secret + owning system + enable toggle; add/remove entries). The secret only shows "configured", never the plaintext; enabling a client requires both secret and redirect_uri, otherwise the save is rejected.
- **Stored in the DB (`ci_app_settings` / `ci_oauth_clients`) and encrypted with `DATA_ENC_KEY`**; older env values are migrated once with `php cli/migrate-oidc.php`. The signing private key stays in env / files and is not maintained here.
- **To let Devops-CD sign in with a Glue account**: this card is only the first of four gates — see [9. Signing into Devops-CD with a Glue account](#9-signing-into-devops-cd-with-a-glue-account-fresh-db-to-working) for the full click-through (issuer, the `cd` client, a user's "Access Systems", the integrated service, CD's env) and the error cross-reference table.

**④ Other settings cards** — each with its own permission
- **Build-record push allowlist** (`ci.build_records.push`): which jobs may report final states via `POST /api/build/{path}/report` — without an allowlist any job can report (the security boundary of push-style CI).
- Platform-level setting cards such as **stale tag cleanup / build-log backfill / log keyword / log settings** also live on this page (see sections 3.4–3.7).


---

### 7.6 The Three Log Pages (Log Center group)

> Path: sidebar **Log Center**: Operation Logs / Build Logs / API Access Logs. The three pages have different data sources and field semantics. When troubleshooting, read them in order: first the operation log to pin down "who did what and when", then the build log or API access log for the technical detail around that timestamp.

**① Operation Logs** — `ci.operation_logs`
- **Data source**: the `ci_operation_logs` table, written automatically by backend management actions (not forgeable — the writers live in code, never in user-controlled parameters).
- **Fields**: time / operator (`root` and other admins, or `system` for system-level events such as version-transition audits and cron jobs) / action (`migrate_schema`, `schema_upgrade`, `schema_downgrade`, `rbac_seed_failed`, `backup_database`, …; rendered as localized labels in the UI) / target / detail (JSON: version transitions carry `previous→current`, `seed_mode`, `channel`; backups carry filename and byte size) / IP / result (success ✅ / failure ❌).
- **Filters**: operator type (admin / system), action name, time range. **Typical usage**: suspect the schema was touched → filter by `schema_downgrade` or `migrate_schema`; suspect the permission seed is incomplete → filter by `rbac_seed_failed`.

**② Build Logs** — `ci.build_logs` (a different table from the operation log!)
- Raw CI build output, aggregated per job and paginated. The retention days configured under "Log Settings" (section 3.7) control how much data lives here.
- Image-tag backfill and keyword extraction (sections 3.5 / 3.6) also read from this table.

**③ API Access Logs** — `ci.api_access_logs`
- A call ledger for the public REST API: time / client / path / status code / duration / caller IP. It answers "have my downstream systems or bots been calling, and how many failed"; combined with rate limiting (429) it helps locate abuse.
- Retention is likewise controlled by "Log Settings"; `cli/cleanup-api-access-logs.php` (a worker cron job in the distributed variant) performs the offline cleanup.

### 7.7 Data Management Page (Backup / Tables / Migration Mode)

> Path: sidebar **Data Management** (page ID: `system-info`). **Every button on this page is `super_admin` only**; a normal admin sees only the table list and statistics, not the action buttons.

The page has three blocks for three different kinds of operations — do not mix them up:

**① System info card (read-only, `ci.system` permission)** — app version / schema version / seed version / **Migration Mode** (the effective `DB_AUTO_MIGRATE` value: auto = the app creates tables itself; manual = amber hint "tables are provisioned via database/*.sql or cli/migrate.php; the app only seeds") / **Table Consistency** (`schema_list_drift`: green "consistent (with the table definitions in the code)" normally; if it names tables in red, this instance's AppConfig table constants and the table-list probe list are out of sync (an upgrade or hand-patched build bypassed CI), which shows up as **the list missing tables / failing to report a missing table** — upgrade to a build with the fix; table creation and normal operation are unaffected) / database driver / time of the last schema sync. **This card is the first stop when deciding whether a migration is needed.**

**② Table list (read-only)** — each of the 18 business tables is probed for existence and row count, quickly revealing "which table is missing" (the diagnostic entry for empty or half-initialized databases).

**③ Backup & restore (`super_admin`, write operations)**
- "Backup Database": a full export-style backup (zip), recorded in the operation log; `php cli/backup-db.php --force` is the equivalent CLI (use it for cron).
- "Restore" is an **offline operation** — restore is not an online button; the zip artifact must be applied with `bin/restore.php` on the server (not shipped in the image, to prevent accidental clicks). The UI only lists and downloads backup artifacts.

**④ Sync Schema (`super_admin`)** — where the "Sync Schema" button from section 3.4 lives: it creates missing tables, adds missing columns and indexes, and converges the RBAC seed to the current code definitions (prune). If the app's DB account lacks DDL privileges the click returns 409 with an actionable hint (use `php cli/migrate.php` instead). **Do not** treat "Sync Schema" as a routine maintenance button — after a normal upgrade it happens automatically (in auto mode, on the first request); manual clicks are only for explicitly converging permissions or aligning after a rollback.



---

## 8. LDAP Login

Besides built-in accounts, the system supports logging in with enterprise **LDAP / Active Directory** accounts (since v2.6.3). This section covers the usage side; see the Admin Configuration Manual for installation / configuration.

### 8.1 Login order

Once LDAP is enabled, the login check order is: **local account → LDAP → config fallback**. That is:
1. The local `ci_admin_users` table is checked first;
2. If the local check fails / no such account, LDAP is checked next;
3. Only on first deployment (the `ci_admin_users` table is empty) does it fall back to the `app.env` fallback account.

### 8.2 Prerequisites

- An admin has enabled LDAP and completed the connection config (requires PHP's `ldap` extension).
- **Your account must first be bound by an admin in the system**: LDAP only **verifies whether the password is correct**; it never auto-creates accounts. Only usernames already bound in the backend can log in.
- Disabled accounts also cannot log in via LDAP.

### 8.3 How to use

1. Open the backend login page.
2. Enter **the username bound in LDAP and its password** (you log into the bound account, not any arbitrary LDAP user).
3. After login, the account's permissions and role are still those of the corresponding account inside the system — that is, set up an LDAP user's role and permissions under **User Management** just like any normal user.

---

## 9. Signing into Devops-CD with a Glue account (fresh DB to working)

> Applies to a joint Glue + Devops-CD deployment (where CD's login method is "Sign in with Glue"). Skip this section if you do not run CD.

### 9.1 Know the four gates first

Clicking sign-in in CD → redirect to Glue's authorize page → back to CD to exchange the code for a token → establish a session → re-validate the identity on every request. **Four gates** must all pass:

| Gate | Where to configure | Typical symptom when it is wrong |
|---|---|---|
| ① Glue's OIDC client whitelist (the `cd` row) | Glue admin "System Settings → Platform Management → OIDC / SSO Config" | Glue's authorize page returns **400 "invalid OAuth request"** |
| ② Glue's issuer (the public URL) | Same page (the Issuer field) | CD returns 502 / discovery's `issuer` does not match (worst case: misdiagnosed as a key problem) |
| ③ The signing-in account's system membership + role | Glue admin "User Management" (Access Systems / role) | Glue's login page **401 `auth.no_ci_access`**; or CD bounces to `/login?error=errors.no_cd_access` / `errors.identity_unavailable` |
| ④ CD's "integrated service" authorization + `CI_API_TOKEN` | Glue admin "API Management → 🔌 Integrated Services" + CD's `.env` | CD bounces to `/login?error=errors.cd_not_enabled` / `errors.cd_not_deployed` |

**Rule #1 when debugging: look at which side shows the error.** URL on Glue (`/oauth/authorize`) and the error rendered on Glue's login page → check ①②③; the error comes back to CD's login page (`/login?error=...`) → check ③④ and CD's own `.env`.

### 9.2 Five steps from scratch (each with a way to confirm)

**Step 0 · Make sure Glue is running**
Open `http://<glue>/admin`; getting a login page is enough (a fresh database creates its tables and seeds the admin account on first start).

**Step 1 · Configure OIDC / SSO in the Glue admin**
Path: "System Settings → Platform Management → **OIDC / SSO Config**".

1. **Issuer**: the **externally reachable full URL** of Glue (e.g. `https://glue.example.com`), **identical to CD's `OIDC_ISSUER`**.
   - ✅ Confirm: `curl -s https://glue.example.com/.well-known/openid-configuration | jq .issuer,.jwks_uri` — both values must be **external addresses** (never `172.x.x.x` or `devops-glue`).
   - ❌ Left empty: Glue derives it from the incoming request (behind a reverse proxy that is usually an internal address) → CD's discovery check fails, and the symptom looks like "the key is wrong".
2. **The `cd` client row** (if missing, click "➕ Add client" and use `client_id = cd`):

| Field | What to fill in | Consequence of getting it wrong |
|---|---|---|
| Redirect URI | CD's `CD_OIDC_REDIRECT_URI` verbatim (e.g. `http://cd.example.com/api/auth/oidc/callback`) | Compared **character for character** (scheme / host / port / path); one character off → 400 on the authorize page |
| Secret | Identical to CD's `CD_OIDC_SECRET` | Mismatch → Glue answers `invalid_client` (401) when CD exchanges the token → CD says "unified login service temporarily unavailable" |
| Owning system | **`cd`** | An empty value is stored as `ci`, which only affects "CD-only" accounts (see 9.3) |
| Enabled | Tick it | Unticked = the client does not exist → also a 400 |

   - The backend blocks the save when **"Enabled" is ticked but Secret / Redirect URI is empty → 400** (so a client is never "enabled and instantly dropped" by the fail-closed filter).
   - ✅ Confirm: after saving, the row shows Secret "configured" plus the `✓ database` badge, and `cd` appears in the list.
3. **id_token TTL**: defaults to 3600 seconds; normally leave it alone.

> On a fresh database env can seed this automatically (see the FAQ entry "do I still have to run a migration by hand"). **As soon as this page holds a hand-made row, the auto-seeder stands down** and will not overwrite your edits here.

**Step 2 · Have an account that may sign into CD**
In "User Management → Add user", watch the **"Access Systems"** field:

- Choose **"CD Only"** (`cd`) or **"CD + CI"** (`cd,ci`) — both contain `cd` and can sign into CD;
- Choosing **"CI Only"** (`ci`) → CD login is refused: Glue's login page shows 401 `auth.no_ci_access`, and CD bounces to `/login?error=errors.no_cd_access`;
- **The role must exist**: after login CD queries Glue's `GET /api/rbac/roles/{role}/permissions`, and **a missing role = 404 = login refused** (CD reports `errors.identity_unavailable`). When renaming a role, update the users that reference it;
- The seeded admin (`ci,cd`) can always sign into CD — the easiest account for a smoke test.

**Step 3 · Enable the "integrated service" in the Glue admin (authorize CD)**
Path: the **🔌 Integrated Services** card at the top of the "**API Management**" page → tick "Enable" on `CD 部署服务` (CD deployment service) → confirm.

- In the same transaction Glue issues a scope-limited service token (named `cd-service`, with `build.read` / `build.write` / `git` / `rbac.user.write`);
- **The plaintext is shown only once** — copy it into CD's `CI_API_TOKEN`;
- Ticking "Enable" is also the moment Glue creates CD's tables according to the current migration mode (a prerequisite for CD to work at all);
- ✅ Confirm: the card reads "✅ enabled" and `cd-service` appears in the token list;
- ❌ Not enabled: CD's deployment gate blocks the login flow and bounces to `/login?error=errors.cd_not_enabled`;
- To pause it, untick the box (freeze: the credential is kept, re-ticking restores the **same** token and CD needs no changes); to retire or rotate it, ⛔ revoke that token in the list and tick "Enable" again to reissue (remember to update CD).

**Step 4 · CD's `.env` (one-to-one with Glue)**

```ini
OIDC_ISSUER=https://glue.example.com          # = the Issuer from Step 1 (character for character)
CD_OIDC_SECRET=<the cd client's Secret from Step 1>   # character for character
CD_OIDC_REDIRECT_URI=http://cd.example.com/api/auth/oidc/callback   # = the Redirect URI registered in Step 1
CI_API_URL=http://<glue>/                      # Glue address (a service name works inside the container network)
CI_API_TOKEN=dg_xxx                            # the cd-service plaintext from Step 3
CD_API_TOKEN=<CI→CD inbound token, same value on Glue's side>
SECRET_KEY=<openssl rand -base64 32>           # required for container deployments
DB_DRIVER / DB_HOST / DB_NAME / DB_USER / DB_PASS   # shared or separate database
```

> **`CD_OIDC_SYSTEM` is not a CD setting**: CD's code never reads it, so putting it in CD's `.env` has no effect; it is only an optional input to Glue's "env → DB" seeding (see 9.3).
> If any of `OIDC_ISSUER` / `CD_OIDC_SECRET` / `CD_OIDC_REDIRECT_URI` is missing, the compose pre-flight aborts the start (bare metal logs an ERROR at startup) and the login page names the missing items (`errors.oidc_not_configured`).

**Step 5 · Walk through it and confirm**
1. Open CD's login page → click "Sign in with Glue" → it should 302 to Glue's `/oauth/authorize?client_id=cd&...` and render the **login form** (not a 400);
2. Enter the account from Step 2 → success 302s back to CD and lands on the home page;
3. If it fails, locate it with the table in 9.4.

### 9.3 Why `CD_OIDC_SYSTEM` is optional

- It is **not a CD-side setting**: CD reads only `OIDC_ISSUER / CD_OIDC_SECRET / CD_OIDC_REDIRECT_URI` (`client_id` is hard-wired to `cd`), so putting it in CD's `.env` does **nothing**;
- It is an input to **Glue's** "env → DB" seeding: it sets the **"Owning system"** column of the `cd` row in `ci_oauth_clients`. Glue does not read it at runtime either;
- **Login works without it** for two reasons:
  1. since v2.11.2 both the auto-seeder and `cli/migrate-oidc.php` **default the `cd` client's owning system to `cd`** (the older migration script stored an empty value as `ci`);
  2. even if the row ended up as `ci`, any account whose **Access Systems contains `ci`** passes — the seeded admin is `ci,cd`, which is why logging in as admin always works.
- **The case that actually bites**: a **CD-only account** (Access Systems = "CD Only") signing into a `cd` client whose **Owning system is `ci`** → Glue's login page returns 401 `auth.no_ci_access`. Fix: set that row's "Owning system" to `cd` and save — **no env change, no restart**.
- Conclusion: a new deployment **can leave `CD_OIDC_SYSTEM` unset entirely**; it only matters when you seed from env and want to state the ownership explicitly. **The correct way to set ownership is the client's "Owning system" field in the console** (the DB wins) — see [Section 10](#10-migrating-from-env-vars-to-the-console-ui-first) for the env migration/cleanup order.

### 9.4 Symptom → cause → fix (one-page cross-reference)

| Symptom | Shown on | Cause | Fix |
|---|---|---|---|
| 400 "invalid OAuth request (client_id or redirect_uri mismatch)" | Glue's authorize page | The `cd` row is missing / not enabled / Secret or Redirect URI empty (dropped) / Redirect URI not byte-identical to what CD sends | Step 1, item 2 |
| Login page 401 `auth.no_ci_access` | Glue's login page | The account's "Access Systems" lacks `cd`, or the client's "Owning system" is not the account's system | Step 2 / 9.3 |
| "unified login service temporarily unavailable" + CD log `token 端点返回 HTTP 500` | CD | Glue returned 500 while exchanging the token, usually the **signing key** (`OIDC_RSA_PRIVATE_KEY` not a PEM / `OIDC_KEY_FILE` unreadable) | Read Glue's log (5xx are always logged since v2.11.2); follow the private-key FAQ entry |
| "the unified login service returned a server error" (upstream 5xx) | CD | Same as above; CD now separates "upstream 5xx" from "cannot reach" | Look at Glue's ERROR log |
| "unified login service temporarily unavailable" (cannot reach) | CD | CD cannot reach Glue (network / DNS / port) or discovery fails | From inside CD: `curl <OIDC_ISSUER>/.well-known/openid-configuration` |
| Bounces to `/login?error=errors.no_cd_access` | CD | The account's "Access Systems" lacks `cd` (CD re-validation refuses it) | Step 2 |
| Bounces to `/login?error=errors.identity_unavailable` | CD | The account was deleted in Glue / **the role is dangling** (deleted or renamed) | Fix the user's role |
| Bounces to `/login?error=errors.cd_not_enabled` | CD | The integrated service is not enabled in Glue (CD not authorized) | Step 3 |
| Bounces to `/login?error=errors.cd_not_deployed` (with missing tables) | CD | Enabled, but CD's tables are not fully created | Step 3 (ticking "Enable" creates them), or create them as prompted |
| Bounces to `/login?error=errors.oidc_not_configured` (with missing items) | CD | CD's `.env` lacks `OIDC_ISSUER` / `CD_OIDC_SECRET` / `CD_OIDC_REDIRECT_URI` | Step 4 |
| Login succeeds, then immediately 401 / "service unavailable" | CD | Wrong or revoked `CI_API_TOKEN` → identity re-validation fails | Re-fetch it from "Integrated Services" (re-ticking "Enable" reissues) |

Three self-check commands:

```bash
# (1) discovery's issuer / jwks_uri must both be external addresses
curl -s https://glue.example.com/.well-known/openid-configuration | jq .issuer,.jwks_uri
# (2) the cd client is registered: the authorize page should render the login form, not a 400
curl -s -o /dev/null -w '%{http_code}\n' "https://glue.example.com/oauth/authorize?response_type=code&client_id=cd&redirect_uri=http://cd.example.com/api/auth/oidc/callback&scope=openid+profile+email&state=x"
# (3) the integrated service is authorized (using the cd-service plaintext)
curl -s -H "Authorization: Bearer dg_xxx" http://glue.example.com/api/integration/services/cd | jq .enabled
```

---

## 10. Migrating from env vars to the console (UI first)

> Applies when upgrading from a pre-v2.10.0 version, or on a first joint deployment when you are unsure whether a value belongs in env or in the console.
> One-line principle: **the database is the single source of truth for connection-type settings — maintain them in the console UI; env is only a one-time migration input.** Keeping them in config files serves no purpose and misleads troubleshooting ("I changed the env var, so why does nothing happen?").

### 10.1 First, tell the two apart

| Setting | Single source of truth | Can it still live in env? |
|---|---|---|
| Jenkins / Git / Harbor connections (URL, account, token / password) | Console: "System Settings → Platform Management → **Platform Connections**" (`ci_platform_connections`) | ❌ Not read at runtime; only `cli/migrate-platform-connections.php` writes it once |
| OIDC **issuer** / **id_token TTL** | Console: "**OIDC / SSO Config**" (`ci_app_settings`) | ⚠️ Don't: a non-empty DB value always wins; only an empty DB falls back to `OIDC_ISSUER` / `OIDC_ID_TOKEN_TTL` |
| OIDC **client whitelist** (`cd` / `jenkins` / `harbor`: Secret, Redirect URI, **owning system**, enabled) | Same page (`ci_oauth_clients`) | ❌ Not read at runtime; one-off migration / fresh-DB seeding only |
| The `cd` client's **owning system** (formerly `CD_OIDC_SYSTEM`) | Same page, the client's "**Owning system**" field | ❌ **Don't**: CD never reads it, Glue does not read it at runtime either; the **DB wins** |
| OIDC **signing key** | env / file (`OIDC_RSA_PRIVATE_KEY` or `OIDC_KEY_FILE`) | ✅ Must: never migrated to the DB, and all replicas need the same one |
| `DATA_ENC_KEY` / `DB_*` / `SECRET_KEY` / `CI_API_TOKEN` / `CD_API_TOKEN` / `TZ` / `LOG_*` | env | ✅ Must: still read at runtime |
| `CD_OIDC_SECRET` / `CD_OIDC_REDIRECT_URI` | The Glue console is authoritative, but **the CD container reads them too** | ⚠️ **Keep** them in a joint deployment (CD has no other source for its client_secret / callback URL) |

### 10.2 The migration, step by step (finish one step before starting the next)

The order below is for **upgrading from an earlier version**; on a **fresh database you can skip step 3** (the boot seeder handles it). The core habit: **confirm the values in the UI first, then remove the env keys**.

1. **Back up the database** — console "Data Management → Backup", or `docker compose exec web php cli/backup-db.php --force`. The migration writes to the DB, so never skip this.
2. **Upgrade the code** to v2.10.0+ (which ships `cli/migrate-*.php`). **Do not touch env yet** — the old env values are exactly the migration input.
3. **Run the one-off migration (optional; idempotent, never overwrites existing values)**:

   ```bash
   docker compose exec web php cli/migrate-platform-connections.php   # Jenkins/Git/Harbor connections → ci_platform_connections
   docker compose exec web php cli/migrate-oidc.php                   # issuer + client whitelist → ci_app_settings / ci_oauth_clients
   ```
   - On a fresh database (empty `ci_oauth_clients`) you **do not need it**: since v2.11.2 the boot seeder populates it from non-empty env values, and it stands down as soon as the table has any row (delete that row first if you want to force a rebuild).
   - Run them anyway when you want a per-item "written / skipped" report, or when you later added env values that should be merged into the DB.

4. **Verify every item in the console (the key step)** — the script only moves values into the DB; **the DB is authoritative from then on**, so confirm the real values through the UI:
   - "System Settings → Platform Management → **Platform Connections**": all six platforms have a URL / credential (the `✓ configured` badge);
   - "System Settings → Platform Management → **OIDC / SSO Config**": the **Issuer** is Glue's external URL, and the **`cd` client**'s Redirect URI / Secret / **Owning system** / Enabled switch are all correct (the Secret only ever reads "configured" — it is never echoed back);
   - Click "Devops-CD → Sign in with Glue" once to prove the chain end to end (match errors against the [9.4 table](#94-symptom--cause--fix-one-page-cross-reference)).
   - If a UI value is wrong (say, the Issuer was saved as an internal address): **edit and save it right here** — do not go back to env.

5. **Only after the console is correct, clean up env** — delete the migrated keys from `config/app.env` and the root `.env` (`JENKINS_*` / `GIT_*` / `HARBOR_*` / `OIDC_ISSUER` / `OIDC_ID_TOKEN_TTL`, …). **Remember the two exceptions**:
   - `CD_OIDC_SECRET` / `CD_OIDC_REDIRECT_URI`: **keep** (the CD container reads them in a joint deployment);
   - `CD_OIDC_SYSTEM`: **delete** (it should never have been in a config file; set ownership in the console instead).
   - Keys still read at runtime and therefore mandatory: `DB_*`, `DATA_ENC_KEY`, `OIDC_RSA_PRIVATE_KEY` / `OIDC_KEY_FILE`, `SECRET_KEY`, `CI_API_TOKEN`, `CD_API_TOKEN`, `TZ`, `LOG_*`.

6. **Re-create the containers and smoke-test once**:

   ```bash
   docker compose up -d --force-recreate web worker
   ```
   Then verify in order: console login → mapping / build pages show data → registry query works → CD login works.

7. **Know how to roll back** (write this down before upgrading):
   - **issuer / id_token TTL**: clear the Issuer in the console and save (= deleting that settings row) to fall back to the env value;
   - **client whitelist**: since v2.10.0 there is **no env fallback** — roll back by re-entering it in the console, or re-run `cli/migrate-oidc.php` while env still holds values;
   - **connections**: likewise, re-enter them in the console or re-run `cli/migrate-platform-connections.php`.

### 10.3 Four common misreads

| Symptom | Cause | What to do |
|---|---|---|
| "I changed `config/app.env` / the root `.env` and nothing happens" | That key moved into the DB and env is no longer read at runtime | Edit and save it on the corresponding console page; retry after at most one revalidation window (60 s by default) |
| "`OIDC_ISSUER` is set in env, yet CD still reports an issuer mismatch / 400" | **A non-empty DB value overrides env**: as soon as the console has an Issuer, the code only uses that | Fix it on the "OIDC / SSO Config" page; env is only the fallback when the DB is empty |
| "Filling in `CD_OIDC_SYSTEM` changes nothing" | Its only job is to seed the `cd` client's owning system on a fresh, empty table | Expected: change the client's "**Owning system**" field in the console instead |
| "I changed the console but did not restart the container — does it matter?" | These settings are read from the DB on each request (with a short cache / revalidation window) | No restart needed; saves apply immediately. Only env keys still read at runtime (`OIDC_RSA_PRIVATE_KEY`, `DATA_ENC_KEY`, `DB_*`, …) require re-creating containers |

**One-line mnemonic**: connections and SSO settings — **the DB (console UI) is the single source of truth; environment variables are only for the one-off migration and fresh-DB seeding**. Once the migration is done, remove those keys from the config files (exceptions: `CD_OIDC_SECRET` / `CD_OIDC_REDIRECT_URI` must stay for the CD container, while `CD_OIDC_SYSTEM` is not even read by CD — just delete it).

---

*This manual focuses on daily usage. For configuration details, API references, and troubleshooting, please refer to the Admin Configuration Manual, API Documentation, and FAQ.*

---