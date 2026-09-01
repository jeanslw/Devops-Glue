# Integrated Service Version Compatibility

> This document lists the version support and key version boundaries for each service Devops-Glue integrates with (Jenkins / GitLab / Gitea / Harbor).
> All version boundaries are verified against official documentation / release notes.
> Document version: v2.7.0 (2026-08-31)

---

## Overview

| Service | Minimum supported | API version | Auth method | Version detection needed? |
|---|---|---|---|---|
| Jenkins | v2.60+ | REST `/api/json` (no version) | Basic Auth (`user:token`) | No (API is long-stable) |
| GitLab | v9.0+ | v4 (`/api/v4`) | `PRIVATE-TOKEN` (PAT) | No (v4 stable since 9.0) |
| Gitea | 1.x series | v1 (`/api/v1`) | `Authorization: token` | No (v1 long-stable) |
| Harbor | v1.10.1 / v2.x | v1 / v2.0 | Basic Auth (account/password or secret) | **Yes** |

**Key takeaway**: Jenkins / GitLab / Gitea have long-stable API versions.

---

## 1. Jenkins

### 1.1 Version support

| Version | Status | Notes |
|---|---|---|
| < 2.60 | ❌ Unsupported | Requires Java 7 or older, below the official Java 8 baseline |
| 2.60+ | ✅ Supported (minimum) | First LTS requiring Java 8 (2017-06) |
| 2.96 (weekly) / 2.107 (LTS)+ | ✅ Supported | API-token + Basic Auth requests no longer need a CSRF crumb |
| 2.129+ | ✅ Recommended | New API token system (SHA-256 hashed, multiple named tokens, revocable) |
| 2.176.2+ | ⚠️ Note | Crumb is bound to the web session it was created in; scripts must keep the session cookie |
| 2.555.x (current LTS) | ✅ Recommended | Crumb no longer includes client IP; tokens support expiry dates |

### 1.2 Key version boundaries (per official upgrade guide / release notes)

| Version | Event |
|---|---|
| 2.60 (2017-06, LTS) | First version requiring **Java 8** (minimum baseline) |
| 2.96 / 2.107 (2018) | **API token + Basic Auth requests exempt from CSRF crumb** |
| 2.129 (2018-07) | **New API token system**: tokens stored as SHA-256 hash, multiple named tokens, individually revocable; legacy tokens highlighted for rotation |
| 2.138.1 | API token system improvements |
| 2.176.2 (2019, LTS) | **Crumb bound to web session**: scripts must keep the session cookie to reuse the crumb |
| 2.543 / 2.555.1 (2025) | Crumb no longer includes client IP (proxy compatibility); tokens support expiry dates |

### 1.3 Compatibility strategy

- **Auth**: Basic Auth (`JENKINS_USER` + `JENKINS_TOKEN`); works with both legacy and new token systems (both are `user:token` Basic Auth).
- **CSRF crumb**: already handled — on a 403 during build trigger (POST), the code fetches a crumb from `/crumbIssuer/api/json` with cookies and retries, so it works with/without CSRF and across pre-/post-2.96 versions.
- **Minimum**: `v2.60` (Java 8 baseline). Older instances should be upgraded to at least 2.60.

---

## 2. GitLab

### 2.1 API version evolution (per official `doc/api/v3_to_v4.md`)

| Version | Event |
|---|---|
| 9.0 (2017-03) | **API v4 introduced**, becoming the preferred version |
| 9.5 (2017-08) | API v3 marked unsupported |
| 11.0 (2018-06) | **API v3 removed**, only v4 remains |
| since then | The only API version is **v4**, root path `/api/v4` |

- Backward-incompatible changes require a major API version bump; it is still v4, so **v4 has been backward-compatible since 9.0**.
- GraphQL coexists with the v4 REST API; a future v5 would be a compatibility layer over GraphQL.

### 2.2 GitLab release policy (per official Maintenance Policy)

- Versioning follows SemVer `(Major).(Minor).(Patch)`.
- **Major**: yearly, scheduled for May by default (e.g. 18.0 = 2025-05, 19.0 = 2026-05).
- **Minor**: monthly (third Thursday).
- **Patch**: twice monthly (Wednesday before and after the monthly minor).
- **Maintenance**:
  - Bug fixes are backported only to the **current stable** release;
  - Security fixes are backported to the **current stable + previous two monthly releases**;
  - **No long-term support for older majors** (running the latest stable is encouraged).
- **Cross-major upgrade**: not guaranteed seamless; upgrade to the latest minor of the current major first.

### 2.3 Compatibility strategy

- **Minimum**: GitLab `v9.0` (where API v4 begins).
- **API version**: always use `/api/v4`; no version detection needed (v4 is stable).
- **Auth**: `PRIVATE-TOKEN` header (personal access token, PAT).
- **Project path**: `/projects/{id}` requires URL-encoded project paths (already handled).
- **Recommendation**: GitLab officially maintains only the latest stable (security patches only for current + two previous monthly releases), so production instances should be on a supported version; for API integration, v4 works from 9.0 onward regardless.

---

## 3. Gitea

### 3.1 Version support

| Item | Detail |
|---|---|
| API version | **v1** (root `/api/v1`); API v2 not planned |
| Release line | Community 1.x series (1.21 ~ 1.26 as of 2026) |
| Auth | `Authorization: token <token>` header |
| Auth change | `?token=` query-parameter auth is deprecated in **Gitea 1.23**; use the `Authorization` header instead |

### 3.2 Key version boundaries

| Version | Event |
|---|---|
| Early 1.x | API v1 introduced, long-stable, no v2 split |
| 1.23 | `token` query-parameter auth deprecated (Devops-Glue uses the `Authorization` header, unaffected) |

### 3.3 Compatibility strategy

- **API version**: always use `/api/v1`; no version detection needed.
- **Auth**: `Authorization: token <token>` header (Gitea's GitHub-compatible auth style).
- **Minimum**: Gitea 1.x series (API v1 stable, no version split).

---

## 4. Harbor (supplementary)

Unlike the other three, Harbor has a v1→v2 API split and a robot-account JWT→secret evolution, so it needs concrete-version detection. Devops-Glue implements this on the admin "Platform API Version" page:

- Detect the concrete version (`harbor_version` from `/api/v2.0/systeminfo`, anonymous first, authenticated fallback).
- Decide robot-account support based on the version (boundary v2.2.0).

See the ADMIN_MANUAL "Connect Harbor" section.

| Harbor version | Can a robot account call the REST API? |
|---|---|
| v1.x / v2.0.x / v2.1.x | ❌ No (JWT, Docker/Helm CLI only) |
| v2.2.0+ | ✅ Yes (secret, Basic Auth works) |

---

## 5. Compatibility strategy summary

| Service | Version detection needed? | Compatibility key points | Code status |
|---|---|---|---|
| Jenkins | No | CSRF crumb, legacy/new API token | ✅ Handled (`getCrumb()` auto-retry) |
| GitLab | No | PAT auth, project-path encoding | ✅ Handled (`PRIVATE-TOKEN` + urlencode) |
| Gitea | No | `Authorization: token` auth | ✅ Handled |
| Harbor | Yes | v1/v2 split, robot-account boundary | ✅ Handled (version detection + robot-account check) |

---

## References

- Jenkins upgrade guide (2.60 Java 8 baseline): https://www.jenkins.io/doc/upgrade-guide/2.60/
- Jenkins new API token system (2.129+): https://www.jenkins.io/blog/2018/07/02/new-api-token-system/
- GitLab API v3 → v4: https://gitlab.com/gitlab-org/gitlab/-/blob/master/doc/api/v3_to_v4.md
- GitLab maintenance policy: https://docs.gitlab.com/ee/policy/maintenance.html
- Gitea API overview: https://docs.gitea.com/api/
- Harbor system info API: `/api/v2.0/systeminfo`
