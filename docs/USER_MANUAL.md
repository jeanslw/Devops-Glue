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

---

## 1. Application Settings

After installation and login (default address `http://localhost:8080/admin`), the first step is **connection configuration** — letting the system reach your Jenkins, Git platforms, and Harbor. These credentials are usually stored in the `config/app.env` file (filled in by whoever deployed it). This section explains what each item means and the key points.

### 1.1 Jenkins (CI build source)

Settings: `JENKINS_BASE_URL`, `JENKINS_USER`, `JENKINS_TOKEN`.

- **URL `JENKINS_BASE_URL`**: your Jenkins address, e.g. `http://your-jenkins:8080`. Mind the protocol and port.
- **User `JENKINS_USER`**: a Jenkins account with sufficient rights — a dedicated bot account is recommended over a personal one.
- **Token `JENKINS_TOKEN`**: an **API Token** generated in Jenkins (Profile → Credentials / Token), **not** your login password. Using a token is safer.

> **Key points**: A token is not a password — generate a dedicated API Token in Jenkins. The account only needs routine build rights (read jobs, list branches, trigger builds, check status); no admin rights required.

### 1.2 Git Platforms

The system supports GitLab, GitHub, Gitee, and Gitea (custom platforms can also be added). Each platform is a similar group of settings:

| Setting | Meaning | Key points |
|---|---|---|
| `GITLAB_BASE_URL` / `GITLAB_TOKEN` | GitLab address and access token | For self-hosted GitLab, use the intranet address; use a personal token with `api`/`read_api` scope |
| `GITHUB_BASE_URL` / `GITHUB_TOKEN` | GitHub address and access token | Use `https://api.github.com` and a Personal Access Token |
| `GITEE_BASE_URL` / `GITEE_TOKEN` | Gitee address and access token | Use `https://gitee.com/api/v5` |
| `GITEA_BASE_URL` / `GITEA_TOKEN` | Gitea address and access token | For self-hosted Gitea, use the intranet address and a Gitea API token |
| `DEFAULT_GIT_PLATFORM` | Fallback platform when the URL can't be auto-detected | Set `gitlab`/`gitea` etc. for self-hosted instances to avoid mis-detection |

> **Key points**
> - Each platform token only needs the common rights: read repositories, list branches, write commit status.
> - Self-hosted GitLab/Gitea domains usually contain no platform keyword, so the platform may not be auto-recognized. Set `DEFAULT_GIT_PLATFORM` as a fallback.

### 1.3 Harbor (image registry)

Settings: `HARBOR_BASE_URL`, `HARBOR_USER`, `HARBOR_PASSWORD`.

Harbor supports two account types; the key point depends on your Harbor version:

- **Robot account (Harbor v2.2.0 and later) — recommended**
  - Create a robot account in the Harbor project and grant the needed permissions (at least **read / list tags**; add scan rights if you want to trigger scans).
  - Fill the robot account's **username and token** into `HARBOR_USER` / `HARBOR_PASSWORD`.
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

### 2.3 Linking a Harbor repository

Fill in the **Harbor repo** field with a two-part `project/repository` path. Once linked, when the system displays image tags for this job, it checks the real tag list of that repository in Harbor.

> **Note**: The repository must actually exist in Harbor and use the two-part format (`project/repository`). Accurate linking is required for correct image-tag association and scan write-back later on.

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

### 3.2 Scan-sync and Harbor tag linking

When a build produces an image and pushes it to Harbor, the system can perform two automatic "write-back" actions:

1. **Harbor image-tag linking**: the system links a build to the real Harbor tag of its repository, so the Monitor Overview / Build Records show which image tag this job's build produced.
2. **Commit status write-back**: after scanning the image for vulnerabilities, the scan result (success / failed / not scanned) is written back as a status on the linked Git repository's commit, so your CI / git hosting platform shows the scan outcome directly.

> **Key points**
> - This depends on correct mappings (Ch. 2): only when a job is linked to both a Git repo and a Harbor repo does write-back have a target.
> - Triggering scan/write-back requires the corresponding **API Token scopes** (see Ch. 6: `harbor.scan` / `build.report`).

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

> **First, understand the two origins of image-tag records and their trust levels** — 3.4 / 3.5 / 3.6 are three stations on one pipeline:
>
> | Origin | Description | Trust |
> |---|---|---|
> | **Scan write-back (scan-sync)** | Tags written explicitly by the Harbor image-scan callback (see 3.2) | **Authoritative** (canonical); consumed directly by the deployment layer |
> | **Log-derived** | Tags the system **guesses** from build logs using the "push-success keyword" while pulling build records (see 3.6) | **Candidate**; used for display and backfill only |
>
> The closed loop: **3.6 keyword** decides what counts as a successful push in a log and produces candidate tags → **3.5 backfill** verifies candidates against Harbor and promotes them to authoritative records (written only when Harbor explicitly confirms the tag exists; never overwrites scan-sync results) → **3.4 cleanup** removes authoritative records whose tags Harbor has already deleted, so the deployment layer never reads a dead tag. All three treat **Harbor as the single source of truth**; their switches are independent of one another.

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
| `git` | Read-only: git branches / tags (includes the combined `/api/git/{job}/refs`, used by CD's build page to pick a ref) |
| `harbor.read` | Read-only: Harbor projects / repositories / tags |
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

**② Platform Connectivity Status** — `ci.platform.edit`
- A table listing Git (GitLab/GitHub/Gitee/Gitea/custom) and Harbor integrations: base URL, whether a token is configured, connectivity verification. Each row has a "Test connection" button (probed live; the result is only displayed, not persisted).
- **Problem it solves**: with several platforms in parallel, quickly answer "is this platform configured, and does it work". When a webhook / build trigger fails, look here first instead of digging through env files.

**③ Other settings cards** — each with its own permission
- **Build-record push allowlist** (`ci.build_records.push`): which jobs may report final states via `POST /api/build/{job}/report` — without an allowlist any job can report (the security boundary of push-style CI).
- Security-related cards such as **OIDC / OAuth clients** and **login security policy** are covered in chapter 6 and are not repeated here.


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

**② Table list (read-only)** — each of the 17 business tables is probed for existence and row count, quickly revealing "which table is missing" (the diagnostic entry for empty or half-initialized databases).

**③ Backup & restore (`super_admin`, write operations)**
- "Backup Database": a full export-style backup (zip), recorded in the operation log; `php cli/backup-db.php --force` is the equivalent CLI (use it for cron).
- "Restore" is an **offline operation** — restore is not an online button; the zip artifact must be applied with `bin/restore.php` on the server (not shipped in the image, to prevent accidental clicks). The UI only lists and downloads backup artifacts.

**④ Sync Schema (`super_admin`)** — where the "Sync Schema" button from section 3.4 lives: it creates missing tables, adds missing columns and indexes, and converges the RBAC seed to the current code definitions (prune). If the app's DB account lacks DDL privileges the click returns 409 with an actionable hint (use `php cli/migrate.php` instead). **Do not** treat "Sync Schema" as a routine maintenance button — after a normal upgrade it happens automatically (in auto mode, on the first request); manual clicks are only for explicitly converging permissions or aligning after a rollback.



---

## 8. LDAP Login

Besides built-in accounts, the system supports logging in with enterprise **LDAP / Active Directory** accounts (since v2.6.3). This section covers the usage side; see the Admin Configuration Manual for installation / configuration.

### 8.1 Login order

Once LDAP is enabled, the login check order is: **local account → LDAP → config fallback**. That is:
1. The local `admin_users` table is checked first;
2. If the local check fails / no such account, LDAP is checked next;
3. Only on first deployment (the `admin_users` table is empty) does it fall back to the `app.env` fallback account.

### 8.2 Prerequisites

- An admin has enabled LDAP and completed the connection config (requires PHP's `ldap` extension).
- **Your account must first be bound by an admin in the system**: LDAP only **verifies whether the password is correct**; it never auto-creates accounts. Only usernames already bound in the backend can log in.
- Disabled accounts also cannot log in via LDAP.

### 8.3 How to use

1. Open the backend login page.
2. Enter **the username bound in LDAP and its password** (you log into the bound account, not any arbitrary LDAP user).
3. After login, the account's permissions and role are still those of the corresponding account inside the system — that is, set up an LDAP user's role and permissions under **User Management** just like any normal user.

---

*This manual focuses on daily usage. For configuration details, API references, and troubleshooting, please refer to the Admin Configuration Manual, API Documentation, and FAQ.*

---