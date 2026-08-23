# Devops-Glue Single Sign-On (OAuth2 / OIDC)

> Glue ships a built-in **OAuth2 / OIDC Provider** that lets **Grafana, Jenkins, Harbor, and GitLab** sign in with a Glue account,
> mapping the Glue role (`groups`) onto each system's local roles.
>
> Grafana uses **pure OAuth2** (Generic OAuth, authorization code flow), while Jenkins / Harbor / GitLab use **full OIDC**
> (OpenID Connect 1.0 / RFC 8414 Discovery / RFC 7517 JWKS, RS256-signed `id_token`).
>
> This document covers: enabling SSO on the Glue side, integration examples for all four systems, and the `groups` (Glue role) → local role mapping table.

## Table of Contents

1. [Overview](#1-overview)
2. [Enabling on the Glue Side](#2-enabling-on-the-glue-side)
3. [Integration Examples](#3-integration-examples)
   - [3.1 Harbor](#31-harbor)
   - [3.2 Jenkins](#32-jenkins)
   - [3.3 GitLab](#33-gitlab)
   - [3.4 Grafana](#34-grafana)
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
| Discovery | `<issuer>/.well-known/openid-configuration` | OIDC discovery document |
| JWKS | `<issuer>/.well-known/jwks.json` | Publishes the RS256 public key (`n`/`e`/`kid`, never the private key) |

Key features:

- **Signature algorithm**: the `id_token` is signed with **RS256**. The private key lives only on the Glue side; the public key is published via JWKS, so each system validates against `jwks.json` alone.
- **Supported scopes**: `openid profile email`.
- **`groups` claim**: both `id_token` and `userinfo` return `groups`, an array of Glue user roles (e.g. `["super_admin"]`), which each system maps onto local roles.
- **Backward compatible**: when the authorization request omits `scope=openid`, the flow stays pure OAuth2 (the original Grafana path) and no `id_token` is issued.
- **nonce passthrough**: when the request carries a `nonce`, the `id_token` echoes it back for replay protection.

---

## 2. Enabling on the Glue Side

### 2.1 Generate / configure the signing key

If Glue has no private key when first issuing an `id_token`, it **auto-generates RSA-2048** and persists it to `OIDC_KEY_FILE` (default `config/data/oidc_rsa.pem`, `chmod 0600` after writing).

In production, strongly prefer **pinning the key explicitly** so that `kid` and JWKS stay stable across restarts / multiple instances. Configure in `config/.env`:

```ini
# Glue's external address as the OIDC issuer. Empty = derived at runtime from request scheme+host+port.
# In production, set this explicitly to the full externally reachable URL (e.g. https://glue.example.com).
OIDC_ISSUER=https://glue.example.com

# id_token signing private key (RS256, RSA >= 2048-bit PEM). Empty = auto-generate and persist to OIDC_KEY_FILE.
# Multi-line PEM uses \n escapes, or inject it via a k8s secret.
# OIDC_RSA_PRIVATE_KEY=

# Persistence path when auto-generating the key (default config/data/oidc_rsa.pem)
# OIDC_KEY_FILE=

# id_token lifetime in seconds (default 3600)
# OIDC_ID_TOKEN_TTL=3600
```

> ⚠️ `OIDC_ISSUER` must be the URL each system **actually uses to reach Glue** (scheme + host, plus port when non-default),
> and it must match the `issuer` in the discovery document exactly — OIDC clients verify that `issuer` matches the request URL and reject the login otherwise.

### 2.2 Register clients

Register one client per system in `oauth_clients` in `config/settings.php` (`client_id` + `secret` + an exactly-matching `redirect_uri`):

```php
'oauth_clients' => [
    'grafana' => [
        'secret'       => env('GRAFANA_OAUTH_SECRET', ''),
        'redirect_uri' => 'http://localhost:3000/login/generic_oauth',
    ],
    'harbor'  => [
        'secret'       => env('HARBOR_OIDC_SECRET', ''),
        'redirect_uri' => 'https://harbor.example.com/c/oidc/callback',
    ],
    'jenkins' => [
        'secret'       => env('JENKINS_OIDC_SECRET', ''),
        'redirect_uri' => 'https://jenkins.example.com/securityRealm/finishLogin',
    ],
    'gitlab'  => [
        'secret'       => env('GITLAB_OIDC_SECRET', ''),
        'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback',
    ],
],
```

> ⚠️ Security convention (fail-closed): a client whose `secret` is empty or whitespace-only is **dropped** (treated as unconfigured).
> Do not use an empty secret as a default — that would let the token endpoint be bypassed with an empty secret. Every client must have a strong random secret.
>
> ⚠️ `redirect_uri` uses **exact matching** (`hash_equals`). The callback URL configured on each system must match character-for-character (scheme, host, port, path), or `authorize` rejects the request.

### 2.3 Confirm the key is ready

After restarting Glue, open `https://glue.example.com/.well-known/jwks.json` and confirm `keys[0]` contains `kid`/`n`/`e` and no `d` (the private key parameter).

---

## 3. Integration Examples

In the examples below, `<issuer>` = `OIDC_ISSUER` (e.g. `https://glue.example.com`), and the client credentials match the `secret` registered in 2.2.

### 3.1 Harbor

Harbor has native OIDC support (`AUTH_MODE=oidc_auth`). Configure in `harbor.yml` (or Helm `values.yaml`):

```yaml
auth_mode: oidc_auth
oidc_name: Devops-Glue
oidc_endpoint: <issuer>                 # e.g. https://glue.example.com
oidc_client_id: harbor
oidc_client_secret: <secret>            # matches the harbor client secret in settings.php
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
      clientSecret: <secret>            # matches the jenkins client secret in settings.php
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
        secret: "<secret>",            # matches the gitlab client secret in settings.php
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

### 3.4 Grafana

Grafana uses **Generic OAuth** (pure OAuth2 authorization code flow — no `id_token` / OIDC discovery). The client is already registered in 2.2 (`client_id` = `grafana`).

**3.4.1 Grafana environment variables** (`docker-compose.yml` or `grafana.ini`):

```yaml
environment:
  GF_AUTH_GENERIC_OAUTH_ENABLED: "true"
  GF_AUTH_GENERIC_OAUTH_NAME: "Devops-Glue"
  GF_AUTH_GENERIC_OAUTH_CLIENT_ID: "grafana"
  GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET: "your-strong-secret"   # matches the grafana client secret in 2.2
  GF_AUTH_GENERIC_OAUTH_SCOPES: "openid"
  GF_AUTH_GENERIC_OAUTH_AUTH_URL: "http://your-glue:8080/oauth/authorize"
  GF_AUTH_GENERIC_OAUTH_TOKEN_URL: "http://your-glue:8080/oauth/token"
  GF_AUTH_GENERIC_OAUTH_API_URL: "http://your-glue:8080/oauth/userinfo"
  GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH: "contains(role, 'super_admin') && 'Admin' || 'Viewer'"
```

> Grafana's callback URL is fixed at `http://<grafana>:3000/login/generic_oauth` and must match the `redirect_uri` in 2.2.

**3.4.2 Role mapping**:

- Glue `super_admin` role → Grafana `Admin`
- All other roles (including `viewer`) → Grafana `Viewer`
- `role_attribute_path` is a JMESPath expression, re-evaluated on every login, so role changes take effect immediately
- Grafana matches existing users by `email`; when a Glue user has no email set, the system falls back to `username@devops-glue.local`

**3.4.3 Verification**: restart Glue and Grafana → open the Grafana login page → click "Sign in with Devops-Glue" → sign in with a Glue account and confirm the role is correct.

---

## 4. groups → Role Mapping Table

The Glue role is delivered as the `groups` claim (an array). Each system maps it as follows:

| Glue role (`groups` value) | Harbor | Jenkins (Role Strategy) | GitLab (external groups) | Grafana |
|:---|:---|:---|:---|:---|
| `super_admin` | `oidc_admin_group` → admin | `assignments` attached to a role with `Overall/Administer` | `admin_group` → admin | `Admin` |
| `ci_admin` and other custom roles | regular user (per-project permissions assigned separately) | `assignments` attached to the matching role | matching external group | `Viewer` |
| unconfigured role | regular user | no mapping (no permissions by default, configure as needed) | no mapping | `Viewer` |

Mapping principles:

- Every system maps "**the string in `groups` → a local role**". Glue only puts the user's role verbatim into `groups` and is unaware of each system's local role names.
- When adding a new Glue role, add a corresponding mapping in every system, otherwise that role lands in "no permission / default group" after login.

---

## 5. Verification

1. **Discovery**: `curl https://glue.example.com/.well-known/openid-configuration` — confirm `issuer`, the four endpoints, and that `id_token_signing_alg_values_supported` includes `RS256`.
2. **JWKS**: `curl https://glue.example.com/.well-known/jwks.json` — confirm `keys[0]` contains `kid`/`n`/`e`.
3. **id_token**: paste the `id_token` from the authorization code flow into [jwt.io](https://jwt.io) — confirm the signature verifies, `alg=RS256`, `kid` matches `jwks.json`, and claims include `iss/sub/aud/exp/nonce/name/preferred_username/email/groups`.
4. **End-to-end**: on each system's login page, choose "Sign in with Devops-Glue", sign in with a Glue account, and confirm the resulting role matches the mapping.
