# CD 接入 Glue OIDC 设计文档

> **状态**：P0 已实现（分支 `feature/cd-oidc-p0`：`d75bde7` P0 落地 + `68181a4` 契约收口 + `6250a15` v2.9.0 更新日志；389 单测 / 1699 断言 + PHPStan L8 + phpcs 全绿）—— 本文档作为 CD 侧联调依据，契约回签见 §7.5
> **日期**：2026-10-12
> **参与方**：Glue（OAuth2/OIDC Provider、用户主数据）· CD（OIDC Relying Party）
> **范围**：仅「CD 的**登录用户**认证走 Glue OIDC」
> **明确非目标**：① 不改动 Glue 对外 API 的鉴权模型（第三方调 Glue API 仍走 API token）；② 不实现 PKCE；③ 不实现 OIDC 单点登出（end_session）；④ v1 不实现 refresh_token

> **CD 回签（2026-10-12）**：CD 侧已回签 v1 —— ① **接受「角色不存在 = 404」、Glue 接口零改动**（404 在其侧按 `errors.identity_unavailable` 走 fail-closed，其行为表见 §7.5「回签 3」）；② 7 项契约精化**全部采纳**；③ 对 Glue 的 4 项收口按其 `file:line` 复核通过。CD 侧文档提交 `91541c8`（`feature/oidc-bff`）。

---

## 0. TL;DR（一页纸）

**结论：可行，且成本集中在 CD 侧。** Glue 侧 OAuth2 Provider + OIDC Provider 自 v2.6.1 已上线（Harbor / Jenkins / GitLab / Grafana 四家已在用），本方案**不新增任何协议能力**，只需补 3 个 P0 缺口；CD 侧则是一份标准的 BFF 形态 OIDC RP 工程。

**⚠️ 但存在 1 个阻塞级发现（P0-0）**：Glue 管理后台按默认创建的 CD 账号 `systems = 'cd'`，而 Glue 的 OAuth 登录页**硬编码按 CI 系统校验账号归属**，因此该账号走 OIDC 登录**必然失败**（提示「该账号无 CI 系统权限」）。**P0-0 不修，CD 侧完全无法联调**。三种解法见 §4，推荐方案 (b)。

### P0 交付清单（Glue 3 项 + CD 1 项）

| # | 交付项 | Owner | 阻塞关系 |
|:--|:--|:--|:--|
| **0** | OAuth 授权流按 client 决定账号归属系统（解 `systems='cd'` 登录被拒） | Glue | 🔴 **阻塞 CD 全部工作** |
| 1 | 注册 `cd` 客户端（secret + 精确 redirect_uri） | Glue | 🔴 阻塞 CD 登录联调 |
| 2 | access token 改 sha256 存储 | Glue | 不阻塞（安全修补） |
| 4 | 新增 `GET /api/rbac/roles/{name}/permissions` | Glue | 🟠 阻塞 CD 权限映射 |
| 5 | CD 侧 BFF 形态 OIDC RP（主工作量） | **CD** | 依赖 0 / 1 |
| **前置** | 显式配置 `OIDC_ISSUER=<对外地址>`（§7.1） | Glue（部署动作） | 🟡 不阻塞编码；但经反向代理而不配，`iss` / `jwks_uri` 会推导成内网地址 ⇒ CD 验签失败且极易误判为密钥问题（§7.5 回签 2） |

### P0 落地状态（2026-10-12 更新）

| # | 交付项 | 状态 | 落点 / 证据 |
|:--|:--|:--|:--|
| 0 | OAuth 授权按 client 决定账号归属系统 | ✅ 已实现 | `OAuthService::clientSystem` + `OAuthController::authorizeSubmit`（保留 `?: AppConfig::SYSTEM_CI` 回退） |
| 1 | 注册 `cd` 客户端（secret + 精确 redirect_uri） | ✅ 已实现 | `config/settings.php` `oauth_clients['cd']`；两者默认空串 ⇒ fail-closed 剔除 |
| 2 | access token 改 sha256 存储 | ✅ 已实现 | `OAuthService::hashAccessToken`；升级瞬间在途 token 失效（≤1h） |
| 4 | `GET /api/rbac/roles/{name}/permissions` | ✅ 已实现 | `RbacController::rolePermissions` + `config/routes.php`；响应形状见 §7.5 回签 1 |
| 5 | CD 侧 BFF 形态 OIDC RP | ⏳ CD 侧进行中 | 依赖 0 / 1 已就绪，可开工 |
| 前置 | `OIDC_ISSUER` 显式配置 | ⏳ 部署项（联调前必做） | `config/app.env:164`；核对命令见 §7.1 / §7.5 |
| 护栏 | P0 四项的接线守卫测试 | ✅ 已实现 | `tests/Unit/CdOidcLoginWiringTest.php`（源码守卫 + 真 SQL 行为断言） |

**分支状态与切档前置**：P0 全部实现位于**仅本地**的分支 `feature/cd-oidc-p0`（`d75bde7` → `68181a4` → `6250a15` → `0ca9a1f` → `f25bcac` + 本文档 v1.0.2 / v1.0.3 的文档提交；`68181a4` 之后均为**纯文档**提交 ⇒ **代码内容与 `68181a4` 等价**，CD 引用的行号不受影响）。✅ **已于 2026-10-12 本地快进合入 `release/2.9.0`**（`262d42a` → `97981e7`，**无合并提交、零冲突**；合入后全量单测 389 / 1699 断言全绿）；⚠️ **未推送任何远端**（远端 `release/2.9.0` 仍为 `a08eb74`，本地领先 14 个提交，收敛方式待定）；**CD 侧切档（L1b / L1c）的前置条件是目标环境已部署含 P0 的 Glue 版本**（CD 已在其文档写明），故联调前须先确认合并与部署已完成。**合入路径已确定并已于本地执行**：`feature/cd-oidc-p0` →（本地快进）→ `release/2.9.0` →（**稳定后**）→ `main`；feature 是本地 2.9.0 头的直系后代 ⇒ 合入为**快进、零冲突**（实际带入 6 个提交：2 代码 + 4 文档）。⚠️ 仍待处理：`release/2.9.0` 本地头领先远端 `a08eb74` 共 **14 个提交**，且两边**各自重写过「集成服务启用契约」**（本地 v2「禁用只冻结」/ 远端 v1「禁用即冻结+撤销」）⇒ 收敛方式须人工裁定（见 §9.2 未决项 7）。**任何远端推送均须显式授权。**

### 已定架构决策（不再讨论）

| 决策 | 结论 | 依据 |
|:--|:--|:--|
| CD 接入形态 | **BFF 机密客户端**：CD 后端持 client_secret 换 token，浏览器只持 CD 自己的 HttpOnly cookie | CD 前端由后端同源托管、凭证从不进 JS、注销靠服务端删会话行 —— SPA 直连会击穿这三条既有不变量 |
| PKCE | ❌ **不做** | BFF 是机密客户端；Glue 的 `/oauth/token` 本就强制 client_secret（`client_secret_basic` / `client_secret_post`），正好够用 |
| OIDC 单点登出 | ❌ **不做** | Glue 的 `POST /oauth/authorize` **不创建任何会话、不种 cookie**，只发一次性授权码；「CD 登出顺手登出 CI」的风险不存在（没有可登出的对象） |
| refresh_token | ❌ **v1 不做** | 改用「CD 用 `dg_` 服务 token 周期性调 `/api/rbac/users/{username}`」，零 Glue 改动即可实现停用/降权收敛，见 §6 |
| `systems` claim | ⏸ **降为 P2** | v1 已从 rbac 端点拿到 `systems`，claim 属于"少一次调用"的优化，不阻塞 |
| OAuth token 接入 `/api/*` | ❌ **不做** | 与"API 提供的服务不变"冲突；且会绕过 scope 校验直达管理端点（`AuthMiddleware` 会话分支不校验 scope、`super_admin` 权限判定恒真），风险不可控 |

---

## 1. 信任模型：两套凭证正交，互不替代

本方案容易与「第三方服务调 Glue API」混淆，二者是**不同的信任模型**，不能用同一套答案：

| 主体 | 场景 | 协议 | 认证出的身份 | 是否需要 OIDC |
|:--|:--|:--|:--|:--|
| **人** | CD 的登录用户 | OIDC 授权码（Glue = IdP） | 某个具体用户（`sub` / `role` / `groups`） | ✅ 需要 |
| **系统** | CD 后端 / CI / 第三方调 Glue API | API token（`Authorization: Bearer dg_…`） | **该 token 本身**（`api_tokens.name`，角色为哨兵值 `api_token`） | ❌ **不需要** |

**关键区别**：

- API token 是 **Bearer 凭证，认证与授权一体**：不需要任何人参与登录，`AuthMiddleware` 认证出的主体是 token 名而非某个用户，权限由 scope 反查（`AppConfig::API_SCOPE_PERMS`），路径白名单由 `ApiScopeResolver` 决定（`/api/admin/*` 与未登记路径 fail-closed → 403）。
- OIDC 解决的是「**这个人是谁**」，且必须由用户在浏览器中亲自输入凭据（可见、可知情）；`client_credentials` 等机器流程拿不到"人的身份"。
- **推论**：`id_token` 只能用于建立登录态，**绝不能当 API 凭证使用**（其 `aud` 面向 RP，语义不同）。

> **因此**：CD 会**同时持有两条凭证** —— 人 → OIDC 登录态（CD 自己的会话）；机器 → `dg_` API token（调 Glue API）。二者并存，不合并。

---

## 2. 现状能力清单（逐条已代码核验）

### 2.1 已具备（无需开发）

| 能力 | 实现位置 | 说明 |
|:--|:--|:--|
| 授权码流程 `GET/POST /oauth/authorize` | `config/routes.php:172-180`、`OAuthController::authorizeForm/authorizeSubmit` | 路由组**不挂 AuthMiddleware**（公开） |
| 授权码安全 | `OAuthService.php:19, 114-176` | 一次性、TTL 60s、绑定 `client_id` + `redirect_uri`（`hash_equals` 逐字符匹配）、先删后校验防重放 |
| 客户端白名单 + fail-closed | `config/settings.php:202-225`、`OAuthService.php:37-54` | `secret` 或 `redirect_uri` 为空/纯空白 → 客户端被剔除（防空 secret 绕过） |
| token 端点鉴权 | `OAuthController.php:135-160` | 支持 `client_secret_post` 与 `client_secret_basic`（RFC 6749 §2.3.1） |
| 登录限流 | `OAuthController.php:81-94` | 与 `/api/admin/login` 同策略（IP + 用户名，5 次 / 15 分钟），防 OAuth 页成为暴破入口 |
| 账号状态即时收敛 | `OAuthService.php:199-223`（`validateAccessToken`）+ `:234-269`（`refreshAccount`） | `validateAccessToken` **每次都回查** `admin_users`：`status=0` → 立即失效；`role` 变更 → **降权即时生效** |
| OIDC Discovery / JWKS | `routes.php:184-185`、`OidcService.php:241-281` | 公开元数据；RS256 / RSA-2048 / 稳定 `kid`；私钥 0600 且从不输出 |
| `id_token` 签发 | `OidcService::signIdToken:219-241` | `iss/sub/aud/iat/exp` + `nonce` 回显 + 白名单 claims |
| RBAC 读接口（服务账号用） | `routes.php:124-133`、`RbacController` | `GET /api/rbac/users`、`/users/{username}`、`/roles`；scope 统一 `rbac.user.write` |
| 权限查询内部能力 | `TokenService::loadPermissions:172-185` | `role_permissions JOIN roles`，**新增权限端点可直接复用，零新 SQL** |
| API token 生命周期 | `AdminController::apiTokenCreate`、`ApiTokenService` | 签发（仅 super_admin）、改 scope 即时生效、吊销、冻结/恢复 |

### 2.2 缺口 / 需改动（详见 §4、§5）

| 缺口 | 影响 | 处置 |
|:--|:--|:--|
| **OAuth 登录按 CI 硬编码校验 `systems`** | 🔴 CD 账号（`systems='cd'`）**无法登录** | **P0-0**，§4 |
| 无 `cd` 客户端注册 | CD 无法发起授权 | P0-1 |
| access token 明文入 `cache.cache_key` | 与管理员 token 的 sha256 存储不一致 | P0-2 |
| 无角色→权限读接口 | CD 无法做权限映射（只能硬编码 Glue 权限清单） | P0-4 |
| 无 `systems` / `permissions` claim | 需多一次服务端调用 | P2 |

---

## 3. 协议交互契约（CD 侧实现依据）

### 3.1 时序

```
① 用户访问 CD，未登录
② CD 后端生成 state + nonce，302 →
     {issuer}/oauth/authorize
       ?response_type=code
       &client_id=cd
       &redirect_uri={CD 回调地址，与 Glue 配置逐字符一致}
       &scope=openid profile email
       &state={随机}
       &nonce={随机}
③ Glue 渲染登录页（POST 同路径提交；IP+用户名 5 次/15 分钟 限流）
     —— Glue 侧不创建任何会话、不种 cookie
④ 校验通过 → 302 → {redirect_uri}?code=…&state=…
⑤ CD 校验 state → POST {issuer}/oauth/token
       grant_type=authorization_code
       &code=…
       &redirect_uri={与②完全相同的值}      ← ⚠️ 必须重传，否则 invalid_grant
     + client_id / client_secret（Basic 或表单）
⑥ Glue 返回 { access_token, token_type, expires_in:3600, id_token?, scope? }
       （scope 含 openid 才返回 id_token）
⑦ CD 用 {issuer}/.well-known/jwks.json 验签 id_token，校验 iss / aud / exp / nonce
⑧ CD 建立本地会话（HttpOnly cookie），把 sub / role / groups 落到会话
    ── 后续每个请求：CD 只认自己的会话，不再依赖 Glue token
    ── 周期性（建议 60s）：CD 用 dg_ 服务 token 调 /api/rbac/users/{username} 做状态/权限收敛（§6）
```

### 3.2 请求 / 响应要点

| 项 | 值 / 约束 | 依据 |
|:--|:--|:--|
| 授权端点 | `GET /oauth/authorize`（表单页）、`POST /oauth/authorize`（提交） | `routes.php:173-174` |
| token 端点 | `POST /oauth/token`，`application/x-www-form-urlencoded` | `OAuthController.php:130-137` |
| 唯一支持的 `grant_type` | `authorization_code`（其他 → `unsupported_grant_type` 400） | `OAuthController.php:155-157` |
| client 认证方式 | `client_secret_basic` 或 `client_secret_post` | `OAuthController.php:145-153`、`OidcService.php:254` |
| `redirect_uri` | **token 换码时必须重传且精确匹配** | `OAuthController.php:162` → `consumeCode()` |
| 授权码有效期 | 60 秒、一次性、绑定 client + redirect_uri | `OAuthService.php:19, 114-176` |
| access token 有效期 | 3600 秒（`Cache-Control: no-store`） | `OAuthService.php:27`（唯一事实来源）、`OAuthController.php:181`（引用常量）、`:198`（`no-store`） |
| access token 格式 | **不透明随机串**（不是 JWT），只能由 Glue 解析 | `OAuthService.php:179`（`bin2hex(random_bytes(32))`） |
| `id_token` 有效期 | 默认 3600 秒（`OIDC_ID_TOKEN_TTL`） | `settings.php:224` |
| `id_token` 算法 | RS256，`kid` 稳定（私钥持久化后跨重启不变） | `OidcService.php:188-195, 231` |
| userinfo | `GET/POST /oauth/userinfo`（Bearer） | `routes.php:177` |

### 3.3 `id_token` / userinfo 的 claims（现状）

```
iss, sub, aud, iat, exp, nonce(有则)
name, preferred_username, email, email_verified, groups
        └ groups = [Glue 角色名]（单元素数组）
```

**CD 需要的字段与现状对照**：

| CD 需要 | 现状来源 | 是否满足 |
|:--|:--|:--|
| 稳定唯一标识 `sub` | id_token / userinfo | ✅ `sub` = Glue `username` |
| 角色 | `groups`（= Glue role 名） | ✅ |
| 是否归属 CD（`systems` 含 `cd`） | id_token/userinfo **无**；`GET /api/rbac/users/{username}` **有** | ✅ v1 走 rbac 端点（P0-4 可选加 claim） |
| 细粒度权限（`cd.deploy.approve` 等） | **无任何读接口** | ❌ 必须新增端点（P0-4） |

> ⚠️ **权限不要放进 `id_token`**：`id_token` 只在登录时校验一次，24h 会话内权限变更不会反映（Glue 自身会话是每请求重查的）。权限应按需实时查询 + CD 侧短 TTL 缓存。

---

## 4. 🔴 P0-0：CD 账号无法登录 Glue OAuth 页（阻塞项）

### 4.1 现象

Glue 管理后台创建的 CD 账号，在 Glue OAuth 登录页输入正确凭据后，被拒并提示「**该账号无 CI 系统权限**」（`auth.no_ci_access`），导致 CD 无法通过 OIDC 登录。

### 4.2 因果链（代码路径，逐段可核验）

```
CD 用户来源（CD 无用户管理，账号 100% 由 Glue 侧创建）
  ├─ 路径 A：Glue 管理后台 → AdminController::userCreate:886
  │      $systems = trim($body['systems'] ?? AppConfig::SYSTEM_CD)     ← 不传即 'cd'
  │      前端 users.js:123 新建表单默认 populateSystemsSelect('cd')     ← UI 默认也是 'cd'
  │      前端 users.js:144 编辑表单隐藏 systems（且提交 body 不带 systems）← 事后改不了
  └─ 路径 B：RbacController::userCreate:99
         createUser(..., AppConfig::SYSTEM_CD)                          ← 硬编码 'cd'
                                              ↓
                              admin_users.systems = 'cd'
                                              ↓
CD 发起登录 → OAuthController::authorizeSubmit:97
      $this->auth->authenticate($username, $password, AppConfig::SYSTEM_CI)   ← 硬编码 CI
                                              ↓
      AdminAuthService::authenticate:43 → isAllowedSystem($dbUser['systems'], 'ci')
                                              ↓  (isAllowedSystem:153-157)
                            systemsContain($systems, 'ci')
                                              ↓  (systemsContain:165-176 → explode(',') + in_array)
                 systems = 'cd'  →  false  →  401 + 'auth.no_ci_access'
```

**结论：这不是边缘情况，而是默认路径。** 建号不指定 `systems` → `'cd'`；UI 默认选 `'cd'`；编辑页不提供 `systems` 字段。**任何按常规流程创建的 CD 账号都必然撞上此校验。**

### 4.3 重要区分：P0-0 只影响「能否登录」，不影响 CD 侧准入判断

核验时发现一处意外行为，需一并记录（避免误判影响面）：

> `AdminController::userCreate:911-912`：
> 若 `systems` 不含 `cd`，**服务端会强制追加 `cd`**（`$systems = $systems === '' ? 'cd' : $systems . ',cd'`）。
> ⇒ **凡是 Glue 后台创建的账号，`systems` 必然至少含 `cd`。**
> ⇒ 推论 1：CD 侧「准入判断 = systems 含 cd」对 Glue 建的账号**恒为真**。
> ⇒ 推论 2：UI 上的「仅 CI」选项，实际落库为 `'ci,cd'`（与文案不符，属既有小瑕疵，建议单独记账，**不在本方案范围内修改**）。
> ⇒ 推论 3：因此 P0-0 修复后，CD 账号能登录；同时**不会**因为"含 cd"而被 CD 误拒。

### 4.4 三种解法对比

| 方案 | 做法 | 改动量 | 评价 |
|:--|:--|:--|:--|
| **(a) 让 CD 账号带 `ci`** | 给每个 CD 账号的 `systems` 加上 `ci` | — | ❌ **不可行**，三个理由：① 与既有设计决策直接冲突（`AdminController:917-918` 规定非授权创建者带 ci → 403，i18n 键 `user.cd_no_ci_access` = 「CD 侧创建的用户不能拥有 CI 登录权限」）；② `AdminUserRepository::updateUser($username, ?$passwordHash, ?$role, ?$email)` **签名无 `systems`** → 已建错的账号**没有任何 API 可修补**，只能改 SQL 或重建；③ 语义错误：把 CI 系统访问权送给 CD 用户 |
| **(b) 按 client 决定账号归属系统** ⭐ | `oauth_clients['cd']` 声明 `system => 'cd'`；授权时用该值而非硬编码 CI | **小**（约 2h，见 4.5） | ✅ **推荐目标态**。语义正确：每个 client 有明确归属；将来 CI 侧自己的 client 也可复用；错误提示自动变为正确的 `auth.no_cd_access`；**默认值保持 CI → 现有 4 家客户端零回归** |
| **(c) 复用已有 fallback 分支** | 新增 `SYSTEM_ANY` 常量并在授权时传入，走 `isAllowedSystem:161-162` 的"ci 或 cd 任一即可"分支 | 极小（约 30min） | ⚡ 可作应急。但**判据被放宽**（ci 或 cd 任一），丧失"这个账号属不属于 CD"的区分能力，与 CD 侧 `_check_cd_access` 的严格判据不一致 |

**推荐：目标态 (b)。** 若 CD 联调时间紧，可先上 (c) 打通链路、排期换 (b)，但**不建议长期停留在 (c)**。

### 4.5 方案 (b) 实现细节（精确改动点）

| # | 文件 | 改动 | 说明 |
|:--|:--|:--|:--|
| 1 | `config/settings.php` `oauth_clients` | `'cd' => ['secret' => env('CD_OIDC_SECRET',''), 'redirect_uri' => env('CD_OIDC_REDIRECT_URI',''), 'system' => env('CD_OIDC_SYSTEM','cd')]` | 新增键，**不参与** `OAuthService::__construct:37-54` 的 fail-closed 过滤（该过滤只查 `secret`/`redirect_uri`）→ 实现安全 |
| 2 | `src/Service/OAuthService.php` | 新增只读访问器，如 `clientSystem(string $clientId): ?string`（与既有 `getClientRedirectUri:85` 同风格） | 复用 `$this->clients` 白名单，未注册 client 返回 `null`（**已实现**：`clientSystem:99`） |
| 3 | `src/Controller/OAuthController.php:97` | `authenticate($username, $password, $this->oauth->clientSystem($clientId) ?: AppConfig::SYSTEM_CI)` | **默认回退 CI** → Grafana/Jenkins/Harbor/GitLab 行为**零变化** |

**零回归论证**：① 未声明 `system` 的 client 走 `?: AppConfig::SYSTEM_CI` 分支，与现状完全一致；② `AdminAuthService` 内**没有**任何"按 systemType 限制角色"的额外逻辑（已核验 `:25-53`，仅做 status + 密码 + `isAllowedSystem`）；③ 错误文案已存在（`getSystemErrorKey:147-151` 的 `SYSTEM_CD` 分支 → `auth.no_cd_access`，i18n 已备），**无需新增 i18n**。

---

## 5. 缺口清单与分期

| # | 缺口 | Owner | 优先级 | 预估 | 说明 |
|:--|:--|:--|:--|:--|:--|
| **0** | OAuth 授权按 client 决定 `systemType`（§4） | Glue | **P0** | 2h（或 (c) 30min） | 🔴 阻塞 CD 全部工作 |
| 1 | 注册 `cd` 客户端（secret + 精确 redirect_uri） | Glue | **P0** | 30min | 🔴 阻塞联调 |
| 2 | access token 改 sha256 存储 | Glue | **P0** | 1h | 与 `TokenService::hashAdminToken` 语义一致；部署瞬间在途 token 失效（≤1h，可接受） |
| 4 | 新增 `GET /api/rbac/roles/{name}/permissions` | Glue | **P0** | 2h | 🟠 阻塞 CD 权限映射；复用 `TokenService::loadPermissions`，**零新 scope**（`ApiScopeResolver:45-47` 已覆盖整个 `/api/rbac/*`） |
| 5 | CD 侧 BFF 形态 OIDC RP | **CD** | **P0** | CD 主工作量 | `state`/`nonce`/JWKS 验签/`iss`/`aud`/`exp`；**换 token 必须重传 redirect_uri** |
| 3 | `systems` claim（userinfo + id_token 白名单） | Glue | P2 | 2h | v1 已从 rbac 端点取得，属优化项。**若做，必须四处同改（漏 1 处 = 静默丢弃，不报错）**：① `OAuthController::buildIdTokenClaims:326-338` 组装取值；② `OidcService::OPTIONAL_CLAIMS:46` 加白名单（`signIdToken:232-238` 是**唯一透传入口**）；③ `OidcService::CLAIMS_SUPPORTED:30-33` 声明；④ `OAuthController::userinfo:221-230`（若也要在 userinfo 暴露）。护栏：`OidcServiceTest::testClaimOutsideWhitelistIsSilentlyDropped`（tripwire，落地 P2 时必须变红） |
| 6 | `refresh_token` | Glue | P2 | 1d | v1 用 §6 的周期校验替代。⚠️ **红线见 §6.3** |
| 7 | PKCE | — | ❌ 不做 | — | BFF 机密客户端不需要 |
| 8 | OIDC 单点登出（end_session） | — | ❌ 不做 | — | Glue 无会话可登出 |

**排期建议**：Glue 侧 P0-0 / P0-1 / P0-4 三项互相独立、对现网零影响，**应最先落地**；CD 侧的 RP 开发应在这三项就位后再排（否则只能对着 mock 开发）。

---

## 6. 会话与吊销设计

### 6.1 v1 定案

```
登录时：id_token / userinfo 取身份（sub / role / groups）
        + GET /api/rbac/users/{username}（服务 token）取 systems / status
CD 会话：24h（沿用 CD 现有 CD_SESSIONS 机制与 HttpOnly cookie）
周期校验：建议 60s，用 dg_ 服务 token 调 GET /api/rbac/users/{username}
        ├─ status = 0            → CD 立即失效该会话
        ├─ systems 不含 cd       → CD 立即失效该会话
        └─ role 变更             → CD 按新角色重新加载权限
权限获取：GET /api/rbac/roles/{role}/permissions（P0-4，按角色缓存，TTL 建议 ≥60s）
```

### 6.2 为什么不用 userinfo 做周期校验（重要机理）

`OAuthService::validateAccessToken:199-223` 的查询条件含 `expires_at > time()`，而 `ACCESS_TOKEN_TTL = 3600`（`OAuthService.php:27`）。

⇒ **Glue 的 access token 1 小时后必然失效**，`/oauth/userinfo` 随之 401。
⇒ 因此「用 userinfo 周期校验」**撑不起 24h 的 CD 会话**（只能在首小时内有效）。
⇒ 而 `dg_` 服务 token 不受此限（有效至吊销），且 `GET /api/rbac/users/{username}` **一次返回 `role` + `systems` + `status`**，正好覆盖 CD 需要的三件事。

> 这也说明：**id_token/userinfo 只适合"登录时取一次身份"，长期状态收敛应走服务 token。**

### 6.3 refresh_token 的 P2 红线（若将来要实现）

`OAuthService::issueAccessToken:177-192` **不查账号状态**。若 `grant_type=refresh_token` 直接调用它，则**账号被停用后仍可无限续期**，等于打掉 Glue 现有最好的安全特性（停用即失效）。实现时必须：

1. refresh 分支**先**回查账号（`refreshAccount()` 或等价查询），`status=0` / 用户不存在 → 返回 `invalid_grant`；
2. 实施 refresh token 轮转（一次一换），并对旧值做吊销；
3. ~~顺手把 `OAuthController.php:179` 的 `expires_in => 3600` 字面量改为引用 `OAuthService::ACCESS_TOKEN_TTL`（两处各自硬编码易漂移）~~ → **已在 P0 收口（`68181a4`）**：`OAuthController.php:181` 现直接引用 `OAuthService::ACCESS_TOKEN_TTL`，字面量已消失（本条不再是 P2 的待办项）。

### 6.4 时效矩阵（CD 可据此设计 UX）

| 变更 | Glue 行为 | CD 感知时效（v1，60s 校验） |
|:--|:--|:--|
| 账号停用（`status=0`） | 令牌立即失效（`refreshAccount`） | ≤ 60s |
| 账号删除 | 令牌立即失效 | ≤ 60s |
| 角色变更（降权） | 令牌仍在，但 role 被实时覆盖 | ≤ 60s（CD 重新拉权限） |
| `systems` 变更 | — | ≤ 60s（但**当前无 API 可改 `systems`**，见 §9） |
| 用户改密码 | 令牌不失效 | 不受影响；如需强制下线，用停用/删除 |

---

## 7. 配置清单

### 7.1 Glue 侧环境变量

| 变量 | 必填 | 说明 |
|:--|:--|:--|
| `CD_OIDC_SECRET` | ✅ | CD 客户端的 client_secret（**留空 → 客户端被剔除，fail-closed**） |
| `CD_OIDC_REDIRECT_URI` | ✅ | CD 回调地址，与 CD 侧发起的 `redirect_uri` **逐字符一致**（`hash_equals` 精确比较） |
| `CD_OIDC_SYSTEM` | 可选 | 该客户端的账号归属系统，默认 `cd`（P0-0 方案 (b) 使用） |
| `OIDC_ISSUER` | ✅ **反代必填（P0 前置）** | 为空时运行时从请求 `scheme+host` 推导，且**有意不读** `X-Forwarded-*`（伪造头会污染 discovery）⇒ 反代下可能推成内网地址：① CD 侧 `iss` 校验失败；② Discovery 的 `jwks_uri` 也指向内网 ⇒ 表现为**验签失败**而非网络不通（极易误判为密钥问题）。联调前核对：`curl -s <issuer>/.well-known/openid-configuration | jq .issuer,.jwks_uri` 两者都须是对外地址 |
| `OIDC_ID_TOKEN_TTL` | 可选 | 默认 3600 |

### 7.2 `config/settings.php` 片段

```php
'oauth_clients' => [
    // …… grafana / jenkins 既有条目保持不变 ……
    'cd' => [
        'secret'       => env('CD_OIDC_SECRET', ''),
        'redirect_uri' => env('CD_OIDC_REDIRECT_URI', ''),
        'system'       => env('CD_OIDC_SYSTEM', 'cd'),   // P0-0 (b)：该 client 的账号归属
    ],
],
```

> ⚠️ 不要为本地调试填默认值通行：`secret` / `redirect_uri` 为空即被剔除，这是**有意的 fail-closed 设计**（防止空 secret 与空输入 `hash_equals` 相等被绕过）。

### 7.3 CD 侧需要的凭据

| 项 | 用途 | 现有/新增 |
|:--|:--|:--|
| `CD_OIDC_SECRET` | 换 token 用 | 新增（Glue 生成并线下交付） |
| `dg_` 服务 token（含 `rbac.user.write` scope） | 周期校验 + 拉权限 | CD 已有 `CD_SERVICE_TOKEN_SCOPES` 机制，**需确认该 token 含 `rbac.user.write`** |

### 7.4 部署顺序（避免中途不可用）

1. Glue：先上 P0-0（`system` 键 + 默认回退 CI）→ 现有客户端零影响；
2. Glue：注册 `cd` client（此时 CD 尚未接，无影响）；
3. Glue：上 P0-4 权限端点 + P0-2 token 哈希（后者使在途 OAuth token 全部失效，≤1h 影响，建议低峰执行）；
4. CD：上线 RP → 灰度 1~2 个账号验证 → 全量。

### 7.5 与 CD 的契约回签（2026-10-12）

> 本节是 Glue 侧对 CD 送审的《CD ↔ Glue OIDC 接口冻结契约 v1》的**回签记录**，双方以此为准。

**回签 1：P0-4 权限端点响应结构 —— 采纳 `{"role":…,"permissions":[…]}`，但**保留「角色不存在 = 404」****

| 场景 | HTTP | Body |
|:--|:--|:--|
| 正常 | 200 | `{"role":"dev","permissions":["cd.deploy","cd.deploy.approve"]}` |
| 角色存在但零权限 | 200 | `{"role":"some-role","permissions":[]}` |
| `super_admin` | 200 | `{"role":"super_admin","permissions":["*"]}` |
| **角色不存在** | **404** | `{"code":404,"message":"角色不存在"}` |
| `name` 为空 | 400 | `{"code":400,"message":"…"}` |
| 非 API token（登录态） | 403 | `{"code":403,"message":"…"}` |

- CD 只需读 `permissions`；`role` 是 Glue 归一后的**回显**（证明命中的是哪个角色名）。**角色名不做大小写归一**（精确匹配 `roles.name`）——请原样透传 `/api/rbac/users/{username}` 返回的 `role`，不要自行 lower/trim。
- **与 CD 建议的差异及理由**：CD 建议「角色不存在 → 200 + `[]`（deny-by-default，避免降权瞬间刷错误日志）」。Glue 侧保留 404，因为 `404` 与「角色存在但零权限」是**两个不同事实**，合并会制造**静默失效**：CD 会把「角色名写错 / 角色被删」当成「该角色没有任何权限」而把用户权限收敛为空，且没有任何可诊断信号。`roleExists` 检查本身也正是为「防角色名悬空导致权限加载为空」而存在（`AdminUserRepository::roleExists:127-134`，实现为 `WHERE name = ?` **精确匹配** → `:129`）。CD 侧配合方式：404 → fail-closed 拒绝 + 按角色名缓存失败结果并**限流记日志**（而不是每请求一条）—— **CD 已回签接受此分工**，其侧冻结行为表见本节「回签 3」（含窗口去重、限流 WARN 与 ≤1 窗口自愈）。
- ~~若 CD 既有代码只能判 200，告知即可（1 个 `if` + 1 条单测 + OpenAPI 双语）~~ → **✅ 已裁定（2026-10-12）：CD 侧回签「接受 404，不需要 Glue 改」**，其处理为 fail-closed（登录阶段 `302 /login?error=errors.identity_unavailable`；已登录复核阶段**删会话行 + 401**）。**Glue 侧保持 404 不变**，本条即最终口径。
- 不返回 `implied_rules`：写侧 `roleCreate` / `roleUpdate` 已用 `expandPermissions` 展开入库，读侧再展开会与「实际生效权限」口径分叉。

**回签 2：`OIDC_ISSUER` 显式配置 —— 采纳，并升为 P0 前置（部署项）**

- CD 的证据链经 Glue 复核**成立**：`OidcService::issuer()` 只读配置；`OAuthController::resolveIssuer` 缺省从 `$request->getUri()` 的 `scheme+host(+非默认端口)` 推导，**不读 `X-Forwarded-*`**；`OidcService::discovery()` 用它拼出 `issuer` / `authorization_endpoint` / `token_endpoint` / `userinfo_endpoint` / `jwks_uri`。
- 后果（反代未显式配置时）：① CD 侧 `iss` 校验失败；② `jwks_uri` 指向内网 ⇒ CD 拉不到公钥，报「验签失败」而非网络错误（最耗时的误判路径）。
- 落地：`config/app.env` / `config/app.env.example` 注释已加警告与核对命令；联调 checklist **第 0 项**即 `curl -s <issuer>/.well-known/openid-configuration | jq .issuer,.jwks_uri`；用例 E 验收。
- 有意**不**改成读 `X-Forwarded-Proto/Host`：可伪造头会污染 discovery 文档（与 `ClientIp` 仅在对端为可信反代时才信 XFF 的保守取向一致）。若确需，应单独立项并同时引入可信代理白名单。

**CD 送审的 5 项结论 —— Glue 侧全部确认，无分歧**

BFF 机密客户端（不做 PKCE）· 不做 OIDC 单点登出（`POST /oauth/authorize` **不建会话** ⇒ 无物可登出，比「BFF 自管会话」的理由更本质）· v1 不做 `refresh_token`（改周期调 rbac）· `systems` claim 降 P2 · `/api/rbac/roles` 只回 `name`/`description` 故权限端点必须为 P0。

**`POST /oauth/token` 与 id_token 的冻结要点（Glue 侧实据核验后）**

| 项 | 值 / 依据 |
|:--|:--|
| 客户端认证 | HTTP Basic（`client_secret_basic`）或表单体（`client_secret_post`）—— `OAuthController:146-155` |
| 必传 | `grant_type=authorization_code`、`code`、**`redirect_uri` 逐字符重传**（`consumeCode` 精确比对）、**`scope` 必须含 `openid`** |
| ⚠️ 静默项 | `scope` 不含 `openid` ⇒ **不下发 `id_token`**（`hasOpenidScope:304-311`），且不报错 |
| 成功体 | `{"access_token","token_type":"Bearer","expires_in","id_token","scope"}` + `Cache-Control: no-store`；`expires_in` 现已引用 `OAuthService::ACCESS_TOKEN_TTL`（不再写字面量；CD v1 无需依赖它） |
| 失败体 | `invalid_client` 401 / `invalid_grant` 400（code 无效·已用·**redirect_uri 不匹配**）/ `unsupported_grant_type` 400（含 `code` 为空） |
| `iss` / `aud` / `sub` | `iss` = Discovery 的 issuer（见回签 2）；`aud` = client_id = `cd`；`sub` = **username**（`buildIdTokenClaims`） |
| `iat` / `exp` | `exp = now + OIDC_ID_TOKEN_TTL`（默认 3600）；建议 CD 容忍 60s 钟差 |
| `nonce` | 授权请求带 `nonce` 时原样回显（`OidcService::signIdToken:229-231`）—— **CD 必须校验** |
| `groups` | `[role]`（role 空则 `[]`，`OidcService::groupsFromRole`）；v1 不用，P2 可交叉校验 |
| ⚠️ `email` | 用户未配邮箱时是**占位值** `username@devops-glue.local` 且 `email_verified=false` ⇒ **不可当身份标识**（Grafana 按 email 匹配用户才需要它） |
| 不在 id_token 里 | `role` 只在 `/oauth/userinfo`（Bearer **OAuth** token）返回；`systems` 无（P2）。注意 OAuth access_token **不能**调 `/api/*`（§0 已定案） |

**`/api/rbac/*` 两条硬约束 + 8 个易错点**（序号与 CD 侧文档**不一一对应**：CD 侧同批列为 10 条、多出 2 条为其实现内部细节 —— **双方以内容对齐，不以序号对齐**）

1. **绝不能加 `?format=json`** —— `BaseController::output` 默认 `format=raw`，加了会被包成 `{"data":{…}}`（`BaseController:88-101`）。
2. **只按 HTTP 状态码分支** —— `message` 经 `jsonError` 翻译，是给人看的诊断文本、**不是稳定 key**（`BaseController:140-152`）。

| # | 坑 | 现象 |
|:--|:--|:--|
| 1 | 加 `?format=json` | 响应变 `{"data":{…}}`，按顶层取键拿空 |
| 2 | 用 `message` 文本判错 | Glue 改文案 / 切语言后 CD 逻辑静默失效 |
| 3 | `scope` 漏 `openid` | 响应里**没有 `id_token`**，且不报错 |
| 4 | `redirect_uri` 漏传 / 不一致 | `invalid_grant`，且看起来像 code 问题 |
| 5 | `iss` / `jwks_uri` 取推导（内网）值 | 验签失败，误判为密钥问题 |
| 6 | 把 `role` 自行 lower/trim 后再调权限端点 | 角色名**大小写敏感**（`WHERE name = ?` 精确匹配）⇒ **404**，进而被误读成「角色被删」而 fail-closed 拒绝登录；请**原样透传** `/api/rbac/users/{username}` 返回的 `role` |
| 7 | 把 `/oauth/authorize` 的失败当 JSON 解析 | 失败是 **401 + HTML 登录页**（不是 JSON error body）；CD 侧不得对该响应做 `json()` |
| 8 | 把授权码当可重试凭证 | 授权码 TTL 仅 **60s**（`OAuthService::CODE_TTL:19`）且一次性；超时/已用都返回 `invalid_grant`。CD 应**静默重走 `/api/auth/oidc/start`**（一次跳转，**不当错误页**） |

**P2（`systems` claim）的实现约束 —— 4 处同改，漏 1 处静默丢弃**

`signIdToken` 只透传 `OidcService::OPTIONAL_CLAIMS` 白名单内的 claim（`OidcService:232-238`），故必须同时改：
① `OAuthController::buildIdTokenClaims` 组装取值；② `OidcService::OPTIONAL_CLAIMS` 加白名单；③ `OidcService::CLAIMS_SUPPORTED` 声明（Discovery 的 `claims_supported`）；④ `OAuthController::userinfo` 返回数组（若该 claim 也要在 userinfo 暴露）。

护栏已加：`OidcServiceTest` 断言「白名单 ⊆ `claims_supported`」与「每个白名单 claim 都能被签出」；另有 tripwire 断言当前 `systems` **被静默丢弃**（P2 落地时该断言变红，强制四处同改）。

**回签 3：CD 侧二次回签（2026-10-12，CD 文档提交 `91541c8` / CD 分支 `feature/oidc-bff`）—— 「接受 404 + 自行 fail-closed」，Glue 侧接口零改动**

CD 的裁定与 Glue 侧同向：**404 与「零权限」是两个事实**，合会制造静默失效；而「降权瞬间刷日志」的代价由 **CD 侧**消化，不是把两个事实合并。CD 侧已冻结的行为（对 Glue 而言，这是「谁负责什么」的最终分工）：

| CD 侧场景 | CD 侧行为（已冻结） |
|:--|:--|
| 200 真实权限 | 按 `permissions` 判权 |
| 200 `[]` | deny-by-default（**这是真实事实**：该角色确实未被授予任何权限） |
| 200 `["*"]` | **通配放行**（`require_perm` / `enforce_deploy_perm` 均认 `*`） |
| **404** `user.role_not_found` | **fail-closed**：登录阶段 `302 /login?error=errors.identity_unavailable`；已登录复核阶段**删会话行 + 401**（回登录页）；**绝不**降级为空权限 |
| 404 的日志 / 性能 | 同角色名在 `OIDC_REVALIDATE_SECONDS`（60s）窗口内**只查一次、只打一条 WARN（含角色名）**；角色行恢复后 **≤1 个窗口自动恢复** |
| 人工排查 | 需要核对角色目录时用 `GET /api/rbac/roles`（只回 `name`/`description`）；**不**在 404 时自动回退「猜」权限 |

- **CD 新开 1 个 i18n key**：`errors.identity_unavailable`（其侧新 key 由 4 个 → **5 个**），并**刻意不复用** `errors.no_cd_access` / `errors.user_disabled` —— 404 不是「账号问题」而是「管理侧配置问题」，混用会把排查方向带偏。**Glue 侧无需任何改动**（Glue 侧对应文案仍是 `user.role_not_found` + 404）。
- **双方共同持有的不变量**：任何情况下**不得出现「已登录但权限为空」的静默降权** —— 要么拿到明确权限（含 `*` 通配），要么 fail-closed 拒绝并留痕（WARN + 状态码）。
- **7 项契约精化：CD 全部采纳**，本文对应落点 —— ① `role` **原样透传**（本节坑 6 / §3.3）；② 角色改名会同步 `admin_users.role` ⇒ **404 语义可靠**（回签 1）；③ `email` 可能是占位值 `username@devops-glue.local` 且 `email_verified=false` ⇒ **不可作身份标识**（本节冻结要点表）；④ `role` 只在 `/oauth/userinfo`（OAuth Bearer，**不能**调 `/api/*`）⇒ CD v1 不调 userinfo（角色取自 `/api/rbac/users/{u}`）（本节冻结要点表）；⑤ `/oauth/authorize` 失败 = **401 + HTML**、授权码 TTL **60s**（本节坑 7 / 坑 8），且授权码失效时 CD **静默重走 start**；⑥ **单向**：Glue 侧无任何出站调用，只有 CD→Glue 的**入站**路径（`/oauth/authorize` GET+POST、`/oauth/token`、`/oauth/userinfo`（GET+POST）、`/.well-known/openid-configuration` + `/.well-known/jwks.json`、`/api/rbac/*`）；⑦ **不返回 `implied_rules`**（回签 1）。
- **CD 对 Glue 4 项收口的取证复核 —— 我们已逐条复核回去（结果：全部为真，无锚点漂移）**：`OidcService::OPTIONAL_CLAIMS:46` 为唯一白名单、`signIdToken:232-238` 为唯一消费点 ✅；`OAuthService::ACCESS_TOKEN_TTL:27`（`public const`）+ `:189` 引用 + `CODE_TTL:19` = 60 ✅；权限端点形状 ✅（`RbacController::rolePermissions:291`、404 → `:302-303`、`super_admin → ['*']` → `:305-307`、`role` 只 `trim` → `:297`、`AdminUserRepository::roleExists:127` 精确匹配 → `:129`）；P2 四处同改的 tripwire ✅（`OidcServiceTest::testClaimOutsideWhitelistIsSilentlyDropped:188`）。⚠️ 唯一需更正的是**分支事实**：CD 看到的是**已被撤下的远端分支** `68181a4`，当前为**仅本地**（已于 2026-10-12 **本地快进合入 `release/2.9.0`**；最新一条即本文档的文档提交，见 `git log`；**未推送任何远端**），见 §0「分支状态与切档前置」。
- **CD 侧承诺的护栏（已落其文档 §9 / §11 / §12）**：① **联调第 0 项必做** `curl -s <issuer>/.well-known/openid-configuration | jq .issuer,.jwks_uri`，两端都须为对外地址；且 CD 的 `iss` 不符时**同时打印** discovery 值与期望值；② 验收用例「手工 `DELETE` 角色行 → fail-closed + 限流 WARN + ≤60s 自愈」（本文用例 G）；③ 授权码失效（含 60s 超时）**静默重走 start**，一次跳转、不当错误页；④ P2 的 tripwire 测试作为落地护栏。

---

## 8. 验收用例

| # | 场景 | 步骤 | 预期结果 | 现状 |
|:--|:--|:--|:--|:--|
| **A** | **默认 CD 账号可登录（P0-0 回归）** | Glue 后台建号**不填 systems**（落库 `'cd'`）→ 走 OAuth 登录页 | 登录成功、正确回跳 CD | ✅ Glue 侧已修复（`authorizeSubmit` 按 client 取归属系统）；同时该账号在 Glue admin 仍登不上 ⇒ 证明用的是 CD 专属 systemType |
| B | 管理员角色账号 | 建号时选「CD + CI」→ 登录 CD | 成功 | ✅ |
| C | `systems='ci'` 的账号 | 该账号登录 CD | 被拒，提示 `auth.no_cd_access`（文案已存在） | ✅（前提是存在此类账号；注意 §4.3 推论 2） |
| D | 会话内降权 | CD 会话有效期内，Glue 把用户 role 改小 | ≤60s 内 CD 权限随之变化 | 待 CD 实现 |
| 1 | 停用即失效 | Glue 停用账号（`status=0`） | CD 下个校验周期内踢下线 | 待 CD 实现 |
| 2 | 错误 client_secret | 用错误 secret 换 token | `invalid_client` 401 | ✅ |
| 3 | 授权码重放 | 同一 code 换两次 token | 第二次 `invalid_grant` 400 | ✅ |
| 4 | 漏传 `redirect_uri` 或值不一致 | 换 token 时省略/改动 redirect_uri | `invalid_grant` 400 | ✅ |
| 5 | 未注册 client | 用 `client_id=unknown` 授权 | 400 `oauth.invalid_request` | ✅ |
| 6 | 停用账号登录 | 停用账号走 OAuth 登录页 | 401「账号已停用」（`auth.user_disabled`） | ✅ |
| 7 | 登录暴破限流 | 连续 5 次错误密码 | 第 6 次 429 `auth.login_locked`（15 分钟） | ✅ |
| 8 | `id_token` 验签 | CD 用 JWKS 验签并校验 `iss`/`aud`/`exp`/`nonce` | 全部通过 | 待 CD 实现 |
| **E** | **issuer 口径（§7.5 回签 2）** | 配置 `OIDC_ISSUER` 后拉 discovery，并让 CD 打印其期望值 | `discovery.issuer` 与 CD 期望值**逐字符一致**，且 `jwks_uri` 为对外可达地址 | ⏳ 部署时核对 |
| F | 权限端点 404 分支 | CD 用不存在的角色名调 `/api/rbac/roles/{name}/permissions` | 404（**不是** `{"permissions":[]}`）；CD 侧 fail-closed 拒绝并限流记日志 | ✅ Glue 侧已具备（`CdOidcLoginWiringTest`） |
| **G** | **404 的端到端 fail-closed 与自愈（CD 侧）** | 手工 `DELETE` 角色行（或让用户名指向不存在的角色）→ CD 登录 / 已登录复核 | 登录阶段 `302 /login?error=errors.identity_unavailable`；复核阶段**删会话行 + 401**；60s 窗口内同角色**只打 1 条 WARN**；角色行恢复后 ≤1 个窗口自动恢复。**不得出现「已登录但权限为空」** | 待 CD 实现 |

> **用例编号对照（防口径分叉）**：本文 **E** = CD 侧文档 `SSO_OIDC.md` §12 的 **F**（issuer 口径 / 联调第 0 项）；本文 **F + G** 合起来对应 CD 侧文档的 **G**（本文 F = Glue 侧响应形状，G = CD 侧端到端行为）。**跨方沟通请以场景名（而非字母）指代用例**。

---

## 9. 风险与未决项

### 9.1 已识别风险

| 风险 | 影响 | 缓解 |
|:--|:--|:--|
| **若 CD 走 SPA 直连（非 BFF）** | token 落入浏览器 JS，击穿 CD 现有 XSS 安全不变量；且需 Glue 补 PKCE | 已定案走 BFF（§0），本风险不成立 |
| **若把 OAuth token 接入 Glue `/api/*`** | `AuthMiddleware` 会话分支**不校验 scope**，且 `BaseController::hasPermission` 对 `super_admin` 恒真 → 一枚 super_admin 的 OAuth token 可直达 `/api/admin/*`（用户/token/集成服务管理） | 明确不做（§0）；如未来要做，必须先加 `aud`/resource 区分 + 管理端点 fail-closed |
| **生产 issuer 漂移（已升 P0 前置）** | `OIDC_ISSUER` 为空时按请求 `scheme+host` 推导（不读 `X-Forwarded-*`），反代下 CD 侧 `iss` 校验失败，且 Discovery 的 `jwks_uri` 指向内网 ⇒ 被误判为「验签失败 / 密钥问题」 | 部署显式配置 `OIDC_ISSUER`（§7.1）+ 联调 checklist 第 0 项用 curl 核对（§7.5 回签 2） |
| **`redirect_uri` 不一致** | 换 token 时 `invalid_grant`（最常见的联调卡点） | 配置逐字符比对；建议把该值作为联调 checklist 第一项 |
| **服务 token scope 不足** | CD 周期校验/拉权限 403 | 确认 `dg_` token 含 `rbac.user.write`（§7.3） |
| **60s 校验带来的 QPS** | CD 活跃用户数 × (1/60) 次/秒 打到 Glue | 单用户端点轻量（一次索引查询）；必要时 CD 侧加本地缓存兜底，或放宽到 120s |
| **access token 改哈希导致在途失效** | 部署瞬间已发出的 OAuth token 全部失效（Grafana 等需重登） | ≤1h 自然恢复；低峰部署（§7.4） |

### 9.2 未决项（需 CD / Glue 共同确认）

| # | 未决项 | 建议 |
|:--|:--|:--|
| 1 | P0-4 权限端点的响应结构 | ✅ **已裁定（§7.5 回签 1）**：`{"role":"…","permissions":[…]}`；**角色不存在 = 404**（不是空数组）；`super_admin` = `["*"]`；不返回 `implied_rules`（写侧 `expandPermissions` 已展开入库，读侧再展开会与「实际生效权限」口径分叉） |
| 2 | CD 侧 `permissions` 的缓存 TTL 与失效策略 | ✅ **已裁定（CD `91541c8`）**：按角色缓存，窗口 `OIDC_REVALIDATE_SECONDS` = 60s，与周期校验同频；窗口内同角色**只查一次、只打一条 WARN** |
| 3 | CD 校验失败（Glue 不可用 / 角色 404）时的降级策略 | ✅ **已裁定（CD `91541c8`）**：**fail-closed** —— 登录阶段 `302 /login?error=errors.identity_unavailable`、已登录复核阶段删会话行 + 401，**绝不**降级为空权限；Glue 恢复后 ≤1 个窗口自愈 |
| 4 | `systems` 事后修改能力缺失 | 当前**无任何 API/UI 可修改已建账号的 `systems`**（`updateUser` 无该参数、编辑表单隐藏）。若业务需要（如把 `ci,cd` 账号改为仅 `cd`），需单独立项 |
| 5 | 「仅 CI」选项落库为 `'ci,cd'`（§4.3 推论 2） | 既有小瑕疵，建议单独记账，不与本方案混合修改 |
| 6 | 是否需要 `groups` 之外的权限表达 | 若后续多系统共用 Glue 为 IdP，建议统一以 `role` + 权限端点表达，避免在 id_token 内堆叠权限 |
| 7 | **P0 何时合入 `release/2.9.0` 并部署** | ✅ **合并路径已定并已执行本地合并（2026-10-12）**：`feature/cd-oidc-p0`（临时分支）→ **`release/2.9.0`（本地快进：`262d42a` → `97981e7`，零冲突）** → 稳定后 → `main`（`main` 非 2.9.0 祖先 ⇒ 届时为**合并提交**，沿用仓库既有惯例）。⚠️ 未解决：2.9.0 本地/远端血统分叉（同一「集成服务启用契约」被写两遍：本地 v2 / 远端 v1）之收敛方式待裁；**未推送任何远端**。CD 侧对合并节奏无异议；但 **CD 切档（L1b / L1c）以「目标环境已部署含 P0 的 Glue 版本」为前置**（CD 已在其文档写明）。设计文档随 `feature/cd-oidc-p0` 一并带入 —— **勿让「文档在 release 血统、代码在 feature 血统」的口径分叉**（见 §0 分支状态） |

---

## 10. 附录：涉及文件与精确改动点索引

### 10.1 Glue 侧（本方案要改）

| 文件 | 位置 | 改动 |
|:--|:--|:--|
| `config/settings.php` | `oauth_clients`（第 202-225 行） | 新增 `cd` 客户端 + `system` 键 |
| `src/Service/OAuthService.php` | `oauth_clients` 过滤（参考 `getClientRedirectUri:85`） | `clientSystem(string $clientId): ?string`（**已实现**：`:99`） |
| `src/Controller/OAuthController.php` | `:97` | `authenticate()` 第三参改为按 client 解析、默认回退 `SYSTEM_CI` |
| `src/Service/OAuthService.php` | `:177`（签发 `issueAccessToken`，改动前 `:162`）+ `:199`（校验 `validateAccessToken`，改动前 `:181`） | access token 改以 `hash('sha256', $token)` 作 `cache_key`，与 `TokenService::hashAdminToken` 语义一致（**已实现**：`hashAccessToken:270`） |
| `config/routes.php` | `/rbac` 组（第 124-133 行） | 新增 `GET /roles/{name}/permissions` |
| `src/Controller/RbacController.php` | 新增方法 | `rolePermissions()`，复用 `TokenService::loadPermissions`（需注入） |
| `src/Service/AdminUserRepository.php` | 可选 | 如需"角色→权限"读取，可在此加方法（当前 `loadPermissions` 在 `TokenService`） |
| `templates/openapi.json` / `openapi.en.json` | `/api/rbac/roles/{name}/permissions` | 补 OpenAPI 文档 |
| `docs/单点登录.md` / `docs/Single-Sign-On.md` | 新增「CD 接入」小节 | 配置示例 + 常见错误码 |
| `tests/Unit/` | 新增 `*WiringTest` 风格测试 | 覆盖：`system` 键解析与默认回退、权限端点 scope 与响应结构、token 哈希存储 |

### 10.2 关键证据位置（供评审复核）

| 断言 | 证据 |
|:--|:--|
| OAuth 路由公开、不挂 AuthMiddleware | `config/routes.php:170-185` |
| 仅支持 `authorization_code` | `OAuthController.php:155-157` |
| client_secret 必填、支持 Basic/Post | `OAuthController.php:144-160` |
| 换 token 需重传 redirect_uri | `OAuthController.php:164` → `OAuthService::consumeCode:139` |
| **登录页硬编码按 CI 校验** | `OAuthController.php:97` + `AdminAuthService::authenticate:43`、`isAllowedSystem:153-157` |
| 建号默认 `systems='cd'` | `AdminController.php:886`、`public/assets/modules/users.js:123,170-179` |
| RbacController 建号硬编码 `'cd'` | `RbacController.php:99`（注释 `:21,66`） |
| 建号强制追加 `cd`（§4.3） | `AdminController.php:911-912` |
| 编辑账号无法改 `systems` | `AdminUserRepository::updateUser:134`（签名无该参数）、`users.js:144,211` |
| 非授权创建者带 `ci` 被拒 | `AdminController.php:917-918`、`lang/zh_CN/messages.php:500` |
| `auth.no_cd_access` 文案已存在 | `lang/zh_CN/messages.php:61`、`lang/en/messages.php:60` |
| access token 1h 且不透明 | `OAuthService.php:27, 177-192, 199-223` |
| 每次校验回查账号（停用/降权即时生效） | `OAuthService.php:215-218`（调用点）+ `:234-269`（`refreshAccount`） |
| `/api/rbac/*` 统一 scope、自动覆盖新子路径 | `ApiScopeResolver.php:45-47` |
| rbac 用户端点返回 `systems` | `AdminUserRepository::findUserForApi:104-113`、`listUsersForApi:93-98` |
| **rbac 角色端点不返回权限** | `AdminUserRepository::listRolesForApi:119-124`（只有 `name, description`） |
| 权限查询可复用 | `TokenService::loadPermissions:172-185` |
| rbac 端点仅 API token 可调 | `RbacController::requireApiToken:62-68`（前缀 docblock `:59-61`） |
| `groups` = 单角色 | `OidcService::groupsFromRole:299`、`OAuthController::buildIdTokenClaims:326-338` |
| id_token claims 白名单（新增 claim 必须同步改） | `OidcService::OPTIONAL_CLAIMS:46`（白名单常量）、`signIdToken:232-238`（唯一透传点）、`CLAIMS_SUPPORTED:30-33`、`OAuthController::userinfo:221-230` |

---

## 11. 评审记录

| 日期 | 版本 | 变更 | 评审结论 |
|:--|:--|:--|:--|
| 2026-10-12 | v0.1 | 初稿：确立 BFF 形态、划掉 PKCE/end_session/refresh_token、识别 P0-0 阻塞项 | 待 CD 评审 |
| 2026-10-12 | v1.0 | CD 送审回执 + Glue 落地：P0-0/1/2/4 已实现（`d75bde7`）；新增契约回签 §7.5、补充 §二 `OIDC_ISSUER` 升 P0 前置、claim 白名单 4 处同改约束（`68181a4`） | CD 的 5 项结论全部确认，无分歧；权限端点形状保留「角色不存在 = 404」（§7.5 回签 1） |
| 2026-10-12 | v1.0.1 | 收口提交 `68181a4`：`expires_in` 改引 `ACCESS_TOKEN_TTL` 常量、`OPTIONAL_CLAIMS` 白名单结构化 + 4 条护栏测试、`OIDC_ISSUER` 升 P0 前置（中英双语手册与 `app.env.example` 加告警）；v2.9.0 更新日志补齐（`6250a15`）；分支 `feature/cd-oidc-p0` 已就绪（**仅本地，未推送**） | **契约内容零变化**（仍以 §7.5 为准），本次仅加固与文档；CD 侧回签项无需调整 |
| 2026-10-12 | v1.0.2 | **CD 侧二次回签**（CD 文档提交 `91541c8`）：**接受 404** 并自行 fail-closed（新增 i18n key `errors.identity_unavailable`，其侧 4→5）、7 项契约精化全部采纳、`OIDC_REVALIDATE_SECONDS` 60s 去重 / 限流 WARN / ≤1 窗口自愈、P2 tripwire 采纳。本文随之补：§7.5「回签 3」+ 坑表 5→8、§8 用例 G 与**编号对照**（本文 E = CD 文档 F）、§9.2 未决项 2/3 标为已裁定 + 新增未决项 7、§0 分支状态与切档前置、§5 与 §10.2 行锚点校正（`systems` 「3 处」→**四处同改**、`groupsFromRole:290`→`:299`、`signIdToken:219-229`→`:232-238`） | **Glue 侧接口零改动**；仍以 §7.5 为唯一权威口径。CD 侧切档前置 = 目标环境已部署含 P0 的 Glue 版本（§0） |

| 2026-10-12 | v1.0.3 | **本地合并落档**：P0 于本地**快进合并**入 `release/2.9.0`（`262d42a` → `97981e7`，零冲突）；合入后全量单测 **389 / 1699 全绿**（1 skipped）；后续路径 = 稳定后合入 `main`。**未推送任何远端**（远端 2.9.0 仍 `a08eb74`，本地领先 14 个提交） | 契约仍以 §7.5 为准、内容零变化；本次仅血统与状态记录 |
**评审通过后的实施约定**：Glue 侧先落 P0-0 / P0-1 / P0-4（三项独立、零回归），CD 侧 RP 开发随后启动 —— **截至 2026-10-12 已完成**，CD 侧可开工并按 §7.5 契约联调。

