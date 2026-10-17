# Devops-Glue Single Sign-On (OAuth2 / OIDC)

> Glue ships a built-in **OAuth2 / OIDC Provider** that lets **Jenkins, Harbor, and GitLab** sign in with a Glue account,
> mapping the Glue role (`groups`) onto each system's local roles.
>
> All three use **full OIDC** (OpenID Connect 1.0 / RFC 8414 Discovery / RFC 7517 JWKS, RS256-signed `id_token`);
> the provider also supports the plain OAuth2 authorization code flow (no `id_token` is issued when the request omits `scope=openid`).
>
> This document covers: enabling SSO on the Glue side, integration examples for the three systems, and the `groups` (Glue role) → local role mapping table.

## Table of Contents

1. [Overview](#1-overview)
2. [Enabling on the Glue Side](#2-enabling-on-the-glue-side)
3. [Integration Examples](#3-integration-examples)
   - [3.1 Harbor](#31-harbor)
   - [3.2 Jenkins](#32-jenkins)
   - [3.3 GitLab](#33-gitlab)
4. [groups → Role Mapping Table](#4-groups--role-mapping-table)
5. [Verification](#5-verification)

---

## 1. Overview

Glue exposes the following endpoints (`<issuer>` is Glue's externally reachable address):

| Endpoint | Path | Description |
|:---|:---|:---|
| Authorization | `<issuer>/oauth/authorize` | Browser redirect entry point, renders the login form |
| Token | `<issuer>/oauth/token` | Exchanges code for `access_token` (plus `id_token` for OIDC) |
| UserInfo | `<issuer>/oauth/userinfo` | Returns user info for a Bearer token |
| Emails sub-endpoint | `<issuer>/oauth/userinfo/emails` | GitHub-style emails endpoint (for clients that still fetch email via the GitHub emails flow; not listed in the discovery document) |
| Discovery | `<issuer>/.well-known/openid-configuration` | OIDC discovery document |
| JWKS | `<issuer>/.well-known/jwks.json` | Publishes the RS256 public key (`n`/`e`/`kid`, never the private key) |

Key features:

- **Signature algorithm**: the `id_token` is signed with **RS256**. The private key lives only on the Glue side; the public key is published via JWKS, so each system validates against `jwks.json` alone.
- **Supported scopes**: `openid profile email`.
- **`groups` claim**: both `id_token` and `userinfo` return `groups`, an array of Glue user roles (e.g. `["super_admin"]`), which each system maps onto local roles.
- **Backward compatible**: when the authorization request omits `scope=openid`, the flow stays plain OAuth2 and no `id_token` is issued.
- **nonce passthrough**: when the request carries a `nonce`, the `id_token` echoes it back for replay protection.

---

## 2. Enabling on the Glue Side

### 2.1 Generate / configure the signing key

If Glue has no private key when first issuing an `id_token`, it **auto-generates RSA-2048** and persists it to `OIDC_KEY_FILE` (`chmod 0600` after writing).
Bare metal defaults to `config/data/oidc_rsa.pem`; **the Docker image ENV is `/data/cache/oidc_rsa.pem`** (compose mounts `./data/cache`, so the key survives rebuilds). If an existing key still lives at `/app/config/data/oidc_rsa.pem` inside the container, copy it to `./data/cache/oidc_rsa.pem` on the host before recreating, or `kid`/JWKS will change and every downstream SSO client will break.

In production, strongly prefer **pinning the key explicitly** so that `kid` and JWKS stay stable across restarts / multiple instances.

> **Since v2.10.0**: the issuer and the id_token lifetime (`OIDC_ID_TOKEN_TTL`) have moved into the DB (maintained on the "System Settings → Platform Management → OIDC / SSO Config" page; the app no longer reads env — migrate old values once with `php cli/migrate-oidc.php`); **the signing private key stays in env / files**. Of the block below, only `OIDC_RSA_PRIVATE_KEY` / `OIDC_KEY_FILE` are still effective:

```ini
# id_token signing private key (RS256, RSA >= 2048-bit PEM). Empty = auto-generate and persist to OIDC_KEY_FILE.
# Multi-line PEM uses \n escapes, or inject it via a k8s secret.
# OIDC_RSA_PRIVATE_KEY=

# Persistence path when auto-generating (bare metal: config/data/oidc_rsa.pem; Docker: /data/cache/oidc_rsa.pem)
# OIDC_KEY_FILE=/data/cache/oidc_rsa.pem

# The two keys below have moved to the DB (v2.10.0); the app no longer reads them —
# they are consumed only by the one-time cli/migrate-oidc.php migration:
# OIDC_ISSUER=https://glue.example.com   # issuer: maintained on the OIDC / SSO Config page
# OIDC_ID_TOKEN_TTL=3600                 # id_token lifetime in seconds
```

> ⚠️ The issuer (on the OIDC / SSO Config page) must be the URL each system **actually uses to reach Glue** (scheme + host, plus port when non-default),
> and it must match the `issuer` in the discovery document exactly — OIDC clients verify that `issuer` matches the request URL and reject the login otherwise.
>
> **Verify before integration** (when left empty it is derived from the request `scheme+host`; behind a reverse proxy that
> derivation yields an **internal** address: not only does `iss` verification fail, the discovery `jwks_uri` also points
> inward — clients report a **signature-verification failure**, not a network error, which is easy to misdiagnose as a key issue):
>
> ```bash
> curl -s https://glue.example.com/.well-known/openid-configuration | jq .issuer,.jwks_uri
> ```
>
> Both must be the externally reachable address the clients actually use; deployments behind nginx / Ingress must set the issuer explicitly on the OIDC / SSO Config page.

### 2.2 Register clients

Register one client per system (`client_id` + `secret` + an exactly-matching `redirect_uri`) on the "System Settings → Platform Management → OIDC / SSO Config" page. Only the `jenkins` and `cd` clients are built in by default (migrated from the old env by `cli/migrate-oidc.php` on upgrade, and also addable/removable on this page):

- **jenkins**: `redirect_uri` defaults to `http://localhost/securityRealm/finishLogin` (change it to the real callback in production).
- **cd** (Devops-Glue CD): BFF confidential client (`client_secret` stays on CD's backend; the browser only holds CD's own cookie). The callback has no sane default — empty drops the whole client; `system` declares which system the accounts signing in through this client belong to (default `cd`).
- **harbor / gitlab**: add them as needed; unregistered `client_id`s are rejected fail-closed at authorize.

> ⚠️ Security convention (fail-closed): a client whose `secret` or `redirect_uri` is empty or whitespace-only is **dropped** (treated as unconfigured).
> Do not use an empty secret as a default — that would let the token endpoint be bypassed with an empty secret. Every client must have a strong random secret.
>
> ⚠️ `redirect_uri` uses **exact matching** (`hash_equals`). The callback URL configured on each system must match character-for-character (scheme, host, port, path), or `authorize` rejects the request. In production set the callback to the real value; do not commit LAN IPs to git.
>
> ℹ️ `system` (optional, falls back to `ci`) declares **which system the accounts signing in through this client belong to**; authorization checks the account's `systems` against it. The CD client declares `cd`, so CD accounts created in the backend (`systems` containing `cd`) can sign in while `systems=ci` accounts get `auth.no_cd_access`. Jenkins / Harbor / GitLab do not declare the key, so their behaviour is unchanged. Note: without it there is no per-system admission check, and a rejected account is reported with the CI message `auth.no_ci_access`.

### 2.3 Confirm the key is ready

After restarting Glue, open `https://glue.example.com/.well-known/jwks.json` and confirm `keys[0]` contains `kid`/`n`/`e` and no `d` (the private key parameter).

---

## 3. Integration Examples

In the examples below, `<issuer>` = the issuer configured on the OIDC / SSO Config page (e.g. `https://glue.example.com`), and the client credentials match the `secret` registered in 2.2.

### 3.1 Harbor

Harbor has native OIDC support (`AUTH_MODE=oidc_auth`). Configure in `harbor.yml` (or Helm `values.yaml`):

```yaml
auth_mode: oidc_auth
oidc_name: Devops-Glue
oidc_endpoint: <issuer>                 # e.g. https://glue.example.com
oidc_client_id: harbor
oidc_client_secret: <secret>            # matches the harbor client secret on the OIDC / SSO Config page
oidc_scope: openid,profile,email
oidc_groups_claim: groups               # read roles from the id_token groups claim
oidc_user_claim: preferred_username
oidc_auto_onboard: "true"
oidc_verify_cert: "true"
# Grant Harbor admin to users in this group (mapped from Glue roles, see section 4)
# oidc_admin_group: super_admin
```

> Harbor's callback URL is fixed at `https://<harbor>/c/oidc/callback` and must match the `redirect_uri` in 2.2.
> The Docker/Helm CLI cannot perform the browser OIDC redirect, so Harbor provides a **CLI secret** for OIDC users
> (generated on the "User Profile" page after login); use `docker login -u <user> -p <cli_secret> <harbor>`.

### 3.2 Jenkins

Jenkins uses the **oic-auth** plugin (OpenId Connect Authentication), auto-populated via Discovery. JCasC (`jenkins.yaml`) configuration:

```yaml
jenkins:
  securityRealm:
    oic:
      wellKnownOpenIDConfigurationUrl: "<issuer>/.well-known/openid-configuration"
      clientId: jenkins
      clientSecret: <secret>            # matches the jenkins client secret on the OIDC / SSO Config page
      userNameField: preferred_username
      fullNameFieldName: name
      emailFieldName: email
      groupsFieldName: groups           # read roles from the id_token groups claim
      scopes: "openid profile email"
      disableSslVerification: false
      # post-auth callback: <jenkins>/securityRealm/finishLogin (matches redirect_uri)
  authorizationStrategy:
    roleBased:
      roles:
        - name: "admin"                 # Jenkins role name
          permissions:
            - "Overall/Administer"
          assignments:
            - "super_admin"             # OIDC group value (= Glue role)
        - name: "developer"
          permissions:
            - "Overall/Read"
            - "Job/Build"
          assignments:
            - "ci_admin"                # example: map other Glue roles as needed
```

> Equivalent UI config: `Global Security` → `Security Realm` → `OpenID Connect`. Set `Well-known configuration endpoint` = `<issuer>/.well-known/openid-configuration`,
> then `Client id` / `Client secret`, `User name field` = `preferred_username`, `Groups field name` = `groups`.
> Role mapping requires the **Role-based Authorization Strategy** plugin: `assignments` holds the **OIDC group values** (i.e. Glue roles), not Jenkins role names.

### 3.3 GitLab

GitLab integrates via the OmniAuth `openid_connect` provider. For Omnibus installs, edit `/etc/gitlab/gitlab.rb`:

```ruby
gitlab_rails['omniauth_providers'] = [
  {
    name: "openid_connect",            # fixed value, do not change
    label: "Devops-Glue",              # login button text
    args: {
      name: "openid_connect",
      scope: ["openid", "profile", "email"],
      response_type: "code",
      issuer: "<issuer>",              # e.g. https://glue.example.com
      discovery: true,                 # auto-discover via <issuer>/.well-known/openid-configuration
      client_auth_method: "basic",     # client_secret_basic, supported by Glue
      uid_field: "preferred_username",
      client_options: {
        identifier: "gitlab",
        secret: "<secret>",            # matches the gitlab client secret on the OIDC / SSO Config page
        redirect_uri: "https://gitlab.example.com/users/auth/openid_connect/callback"
      }
    }
  }
]
```

Run `gitlab-ctl reconfigure` to apply.

> GitLab's callback URL is `https://<gitlab>/users/auth/openid_connect/callback` and must match the `redirect_uri` in 2.2.
> The `groups` claim maps to GitLab external groups via OmniAuth's `groups_attribute` (default `groups`);
> override it in `args` if needed. Admin-group mapping uses `admin_group`. Field names vary by GitLab version.

---

## 4. groups → Role Mapping Table

The Glue role is delivered as the `groups` claim (an array). Each system maps it as follows:

| Glue role (`groups` value) | Harbor | Jenkins (Role Strategy) | GitLab (external groups) |
|:---|:---|:---|:---|
| `super_admin` | `oidc_admin_group` → admin | `assignments` attached to a role with `Overall/Administer` | `admin_group` → admin |
| `ci_admin` and other custom roles | regular user (per-project permissions assigned separately) | `assignments` attached to the matching role | matching external group |
| unconfigured role | regular user | no mapping (no permissions by default, configure as needed) | no mapping |

Mapping principles:

- Every system maps "**the string in `groups` → a local role**". Glue only puts the user's role verbatim into `groups` and is unaware of each system's local role names.
- When adding a new Glue role, add a corresponding mapping in every system, otherwise that role lands in "no permission / default group" after login.

---

## 5. Verification

1. **Discovery**: `curl https://glue.example.com/.well-known/openid-configuration` — confirm `issuer`, the four endpoints, and that `id_token_signing_alg_values_supported` includes `RS256`.
2. **JWKS**: `curl https://glue.example.com/.well-known/jwks.json` — confirm `keys[0]` contains `kid`/`n`/`e`.
3. **id_token**: paste the `id_token` from the authorization code flow into [jwt.io](https://jwt.io) — confirm the signature verifies, `alg=RS256`, `kid` matches `jwks.json`, and claims include `iss/sub/aud/exp/nonce/name/preferred_username/email/groups`.
4. **End-to-end**: on each system's login page, choose "Sign in with Devops-Glue", sign in with a Glue account, and confirm the resulting role matches the mapping.
