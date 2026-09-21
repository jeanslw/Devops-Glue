# User Manual

> Written for backend administrators and daily users, this manual covers the main functions of Devops-Glue from a **user's point of view**. Menu names and button labels match the admin UI so you can follow along step by step. It focuses on **how to use** things, not how they work inside. For detailed configuration, please see the Admin Configuration Manual.

## Contents

1. [Application Settings](#1-application-settings)
2. [Mapping Management](#2-mapping-management)
3. [Build Mode](#3-build-mode)
4. [User Management](#4-user-management)
5. [Permission Management](#5-permission-management)
6. [API Management](#6-api-management)
7. [Version Compatibility / Security Audit / Monitor Overview / Build Records](#7-version-compatibility--security-audit--monitor-overview--build-records)
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

The **Build Mode** page has a **🧹 Enable stale tag cleanup** switch.

- **What it does**: the system treats **Harbor as the source of truth** and removes internal image-tag records that no longer exist in Harbor, avoiding stale data and keeping the deployment layer from reading tags that are already gone.
- **When to use**: when Harbor routinely prunes tags (e.g. a "keep last N tags" retention policy), enable this so internal records always stay in sync with Harbor's actual state.
- **How to use**: turn the switch on and the system automatically compares and cleans at suitable times (e.g. while handling build records) — no manual, per-tag deletion needed. If Harbor is temporarily unreachable during a check, records are skipped rather than wrongly deleted.

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

"API Management" issues **API Tokens** for third-party services / scripts (such as the Devops-Glue CD service or Jenkins scripts). An API token is independent of the backend account system and directly carries a set of interface scopes, so access is easy to grant and revoke. Only `super_admin` can create / revoke / delete tokens.

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
| `git` | Read-only: git branches |
| `harbor.read` | Read-only: Harbor projects / repositories / tags |
| `harbor.scan` | Trigger Harbor image scans |
| `build.read` | Read-only: build pipelines / logs / branches |
| `build.write` | Write: trigger / retry / cancel builds |
| `build.report` | Write-back: scan-sync / commit-status / report |

> **Recommendations**
> - When authorizing a third-party service (especially CD), check the minimal set of scopes it actually needs — don't select everything.
> - To let the system do "scan write-back", check both `harbor.scan` and `build.report`.
> - Set an expiry time as needed; never-expiring is convenient long-term but keep an eye on security.

### 6.3 Revoking / Deleting a token

- **Revoke**: immediately disables the token while keeping the record (good when you suspect a leak or want a temporary pause). It can be re-enabled later.
- **Delete**: removes the token record entirely; not recoverable.

For day-to-day: use **Revoke** for temporary suspension, **Delete** when it is clearly no longer used.

---
## 7. Version Compatibility / Security Audit / Monitor Overview / Build Records

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

---

## 8. LDAP Login

Besides built-in accounts, the system supports logging in with enterprise **LDAP / Active Directory** accounts (since v2.6.3). This section covers the usage side; see the Admin Configuration Manual for installation / configuration.

### 8.1 Login order

Once LDAP is enabled, the login check order is: **local account → LDAP → config fallback**. That is:
1. The local `admin_users` table is checked first;
2. If the local check fails / no such account, LDAP is checked next;
3. Only in extreme cases (e.g. the DB is unreachable) does it fall back to the `app.env` fallback account.

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