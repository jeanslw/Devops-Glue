# Devops-Glue API 参考 v2.4

基础 URL: `http://your-domain.com/api`

所有接口默认同时支持 `GET` 和 `POST` 方法（`trigger` 和扫描类接口仅支持 `POST`）。

---

## 响应格式

通过 `?format=` 查询参数控制输出：

| 参数 | 示例 | Content-Type |
|---|---|---|
| `?format=raw`（默认） | `["java/registry","static"]` | `application/json` (原始数组) |
| `?format=json` | `{"data":["java/registry","static"]}` | `application/json` |
| `?format=xml` | `<?xml...><root><item>java/registry</item></root>` | `application/xml` |

## 通用错误

```json
{
  "code": 400,
  "message": "错误描述"
}
```

| 状态码 | 含义 |
|---|---|
| 200 | 成功 |
| 204 | CORS 预检成功 |
| 400 | 请求参数错误 |
| 401 | 未认证（缺少或无效的 Token） |
| 403 | 无权限（权限不足） |
| 404 | 资源不存在 |
| 500 | 服务端错误（Jenkins/Harbor 不可达等） |
| 503 | 服务不可用（Harbor 扫描器未启用等） |

## CORS

所有接口默认允许跨域访问（`Access-Control-Allow-Origin: *`）。

允许的方法：GET、POST、PUT、DELETE、PATCH、OPTIONS。
允许的请求头：Content-Type、Authorization、X-Requested-With、Accept。

如需限制来源，在 `config/settings.php` 中配置：

```php
'cors' => [
    'allowed_origins' => ['https://your-frontend.com'],
],
```

---

## 公开接口（无需认证）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/health` | GET | 健康检查 |
| `/api/i18n/{locale}` | GET | 获取语言包（`zh_CN` 或 `en`） |
| `/api/docs` | GET | Swagger UI 文档页面（需文档鉴权） |
| `/api/openapi.json` | GET | OpenAPI 规范（需文档鉴权） |
| `/api/admin/login` | POST | 登录，返回 Bearer Token |
| `/api/admin/logout` | POST | 登出，撤销 Token |

---

## 健康检查

```
GET /api/health
```

返回 Jenkins、Git 平台和 Harbor 的连通性状态，以及系统统计数据。

```json
{
  "status": "ok",
  "checks": {
    "jenkins": true,
    "jenkins_version": "2.504",
    "git": [{"name": "gitlab", "api_version": "v4", "reachable": true}],
    "harbor": true,
    "harbor_version": "v2",
    "harbor_components": {"core": true, "jobservice": true, "registry": true}
  },
  "stats": {"total_maps": 15, "active_maps": 12, "git_platforms": 2, "harbor_repos": 8},
  "build_mode": "both",
  "build_mode_source": "database",
  "db_driver": "mysql",
  "app_version": "v2.4.3",
  "app_env": "production",
  "time": "2026-08-10 12:00:00"
}
```

- `status`: `ok` | `degraded`
- `jenkins`: `true` / `false` / `null`（null = gitlab_ci 模式，不检查 Jenkins）
- `harbor`: `true` / `false` / `null`（null = 未配置 Harbor）
- HTTP 200 (正常) / 503 (降级)

---

## Main 模块 (`/api/main`)

> **认证：** 所有 Main 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

### 获取 Job 列表
```
GET /api/main/jobs/list
```

返回字符串数组：`["java/registry", "php/myapp", "static"]`

`gitlab_ci` 模式下返回映射中的活跃 Job 名称；其他模式返回所有 Jenkins Job（出错时降级到映射数据）。

### Job/Git/Harbor 映射（按项目分组，30 秒缓存）
```
GET /api/main/map/list
```

返回 JSON，`projects` 以 Job 名称（或 current_path）为键，附加平台 URL：

```json
{
  "projects": {
    "tools/registry": {
      "git_platform": "gitlab",
      "build_provider": "jenkins",
      "git_remote": "http://URL/tools/registry.git",
      "project_id": 2,
      "web_url": "http://your-gitlab/group/project",
      "harbor_repository": "mycode/code-runtime",
      "platform_source": "auto",
      "detection_method": "",
      "jobs": ["java/registry"]
    }
  },
  "jenkins_url": "http://your-jenkins:8080",
  "harbor_url": "http://your-harbor"
}
```

### Git 平台列表（静态配置）
```
GET /api/main/git/platforms
```

返回已配置的 Git 平台和 Harbor API 信息。

### 平台发现（动态扫描）
```
GET /api/main/git/discovery
```

返回已配置和未配置的 Git 平台列表。

---

## Build 模块 (`/api/build`) — v2.3.0

> Jenkins 和 GitLab CI 的统一入口。旧版 `/api/jenkins/*` 路由已弃用。
>
> **认证：** 所有 Build 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/build/jobs/list` | GET/POST | 构建 Job 列表（raw: Job 名数组；json: 含 Provider 信息） |
| `/api/build/config-mode` | GET | 构建配置模式（`{mode, source, has_jenkins, has_gitlab_ci}`） |
| `/api/build/{path}/trigger` | GET/POST | 触发构建（JSON body: `{"ref":"","variables":{"param":"value"}}`） |
| `/api/build/{path}/variables` | GET/POST | 构建参数 / CI 变量（raw: 参数名数组；json: 完整元数据） |
| `/api/build/{path}/branches` | GET/POST | Git 分支列表（纯字符串数组） |
| `/api/build/{path}/pipelines` | GET/POST | 流水线列表（`?list=id\|build\|time\|success`） |
| `/api/build/{path}/pipelines/{id}` | GET/POST | 流水线详情 + Jobs |
| `/api/build/{path}/logs/{id}` | GET/POST | 构建日志（text/plain） |
| `/api/build/{path}/pipelines/{id}/retry` | POST | 重试流水线（仅 GitLab CI） |
| `/api/build/{path}/pipelines/{id}/cancel` | POST | 取消流水线（仅 GitLab CI） |
| `/api/build/{path}/scan-sync` | POST | Harbor 扫描同步（`{"tag":"v3.0.0"}`，tag 可选，不传取最新） |
| `/api/build/{path}/tag` | GET/POST | 流水线 → 标签映射（`?pipeline=10`） |
| `/api/build/{path}/commit-status` | POST | 提交状态回写（安全扫描） |

`{path}` 为 Job 名称或映射中的 `current_path`。

### 触发构建

POST JSON body（或 GET query string 兜底，兼容旧版 Jenkins 调用）：
```json
{
  "ref": "master",
  "variables": {"param": "value"}
}
```
body 根级字段（除 `ref`、`variables`、`format`、`token` 外）会自动合并到 variables 中。

### 提交状态回写

必填：`sha`、`state`（`pending`/`success`/`failed`/`error`）、`context`、`description`。
可选：`target_url`、`check_type`（默认等于 `context`）、`tag`。

支持所有 Git 提供商（GitLab/GitHub/Gitee/Gitea），与 CI 系统无关。同时记录到 `ci_security_checks` 表用于审计追踪。

### 扫描同步

触发 Harbor 漏洞扫描，并将结果回写 Git 平台 commit status（`harbor-scan` 上下文）。`tag` 不传则取 Harbor 最新标签。

---

## Git 模块 (`/api/git`)

> **认证：** 所有 Git 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

```
GET /api/git/{path}/branches
```

`{path}` 为映射中的 Job 名称。返回：`["master","devops","main"]`

支持 GitLab、Gitee、GitHub 和 Gitea。自动从 Job 映射中解析 Git 仓库。

---

## Harbor 模块 (`/api/harbor`)

> **认证：** 所有 Harbor 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/harbor/projects` | GET/POST | 项目列表 |
| `/api/harbor/{project}/repositories` | GET/POST | 仓库列表 |
| `/api/harbor/{project}/repositories/{repository}/tags` | GET/POST | 标签列表（`/` 需双重编码为 `%2F`） |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | POST | 触发镜像扫描 |
| `/api/harbor/{project}/repositories/{repository}/tags/{tag}/scan` | GET | 获取扫描报告 |

---

## Admin 模块 (`/api/admin`)

所有 Admin 接口（除 `login` 和 `logout` 外）需要 Bearer Token（通过 `POST /api/admin/login` 获取）。

### 登录

```
POST /api/admin/login
```

请求体：
```json
{
  "user": "admin",
  "password": "your_password"
}
```

响应：
```json
{
  "token": "a1b2c3...（64字符 hex）",
  "role": "admin",
  "user": "admin",
  "is_root": true,
  "permissions": ["ci.manage", "ci.users.manage", "..."]
}
```

Token 有效期 24 小时。`super_admin` 角色的 permissions 返回 `"*"` 通配符。

### Admin 接口列表

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/admin/login` | POST | 登录（公开，无需认证） |
| `/api/admin/logout` | POST | 登出，撤销 Token（公开，无需认证） |
| `/api/admin/password` | PUT | 修改自己的密码（需验证旧密码） |
| `/api/admin/job_git_map` | GET | 映射列表（支持 `?search=&platform=&provider=&page=&per_page=` 筛选） |
| `/api/admin/job_git_map` | POST | 新增映射 |
| `/api/admin/job_git_map` | PUT | 更新映射（需 `_original_job_name`） |
| `/api/admin/job_git_map` | DELETE | 删除映射（`?job_name=...`） |
| `/api/admin/discover` | POST | 自动发现 Jenkins Job |
| `/api/admin/security_checks` | GET | 安全扫描审计记录（支持 `?project=&check_type=&state=&exclude=&page=&per_page=` 筛选） |
| `/api/admin/platform_versions` | GET/PUT | 平台 API 版本配置 |
| `/api/admin/build_mode` | GET/PUT | 构建模式（jenkins/gitlab_ci/both） |
| `/api/admin/users` | GET | 用户列表（admin 可见全部；非 admin 看不到 admin 用户） |
| `/api/admin/users` | POST | 创建用户（body: `username`、`password`、`role`、`systems`） |
| `/api/admin/users/{username}` | PUT | 更新用户（body: `password` 和/或 `role`） |
| `/api/admin/users/{username}` | DELETE | 删除用户（不可删除根账号和自己） |
| `/api/admin/roles` | GET | 角色列表 |
| `/api/admin/roles` | POST | 创建自定义角色（需 `ci.users.manage_admin`） |
| `/api/admin/roles/{id}` | PUT | 更新角色权限（全量替换，需 `ci.users.manage_admin`） |
| `/api/admin/roles/{id}` | DELETE | 删除自定义角色（需 `ci.users.manage_admin`） |
| `/api/admin/permissions` | GET | 权限目录 + 隐含规则（需 `ci.permissions.list`） |
| `/api/admin/permissions` | POST | 注册新权限（body: `perm_key`、`description`、`parent_key?`；需 `ci.permissions.register`） |
| `/api/admin/permissions/{perm_key}` | DELETE | 删除权限（内置权限受保护不可删；需 `ci.permissions.register`） |
| `/api/admin/implied_rules` | POST | 创建隐含规则（body: `source_key`、`target_key`；需 `ci.permissions.rules`） |
| `/api/admin/implied_rules` | DELETE | 删除隐含规则（`?source_key=&target_key=`；需 `ci.permissions.rules`） |
| `/api/admin/me/permissions` | GET | 获取当前用户权限（super_admin 返回通配符 `"*"`） |

### 权限校验规则

- 创建/修改/删除 **管理员** 用户需要 `ci.users.manage_admin` 权限
- 创建/修改自定义角色需要 `ci.users.manage_admin` 权限
- 权限管理操作需要对应的 `ci.permissions.*` 权限

---

## 快速测试命令

```bash
# 健康检查（无需认证）
curl "http://URL/api/health"

# 登录获取 Token
TOKEN=$(curl -s -X POST "http://URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"user":"admin","password":"your_password"}' | jq -r '.data.token')

# 触发构建（POST JSON，需要认证）
curl -X POST "http://URL/api/build/static/trigger" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"ref":"master","variables":{"branches":"master"}}'

# 查询构建变量（需要认证）
curl "http://URL/api/build/static/variables" \
  -H "Authorization: Bearer $TOKEN"

# 通过 build 接口查询 Git 分支（需要认证）
curl "http://URL/api/build/static/branches" \
  -H "Authorization: Bearer $TOKEN"

# 通过 git 接口查询 Git 分支（需要认证）
curl "http://URL/api/git/static/branches" \
  -H "Authorization: Bearer $TOKEN"

# 查询三方映射（需要认证）
curl "http://URL/api/main/map/list" \
  -H "Authorization: Bearer $TOKEN"

# 查询已配置的 Git 平台（需要认证）
curl "http://URL/api/main/git/platforms" \
  -H "Authorization: Bearer $TOKEN"

# 查询平台发现（需要认证）
curl "http://URL/api/main/git/discovery" \
  -H "Authorization: Bearer $TOKEN"

# 构建 Job 列表（需要认证）
curl "http://URL/api/build/jobs/list" \
  -H "Authorization: Bearer $TOKEN"

# 构建配置模式（需要认证）
curl "http://URL/api/build/config-mode" \
  -H "Authorization: Bearer $TOKEN"

# Harbor 项目列表（需要认证）
curl "http://URL/api/harbor/projects" \
  -H "Authorization: Bearer $TOKEN"

# Harbor 标签列表（需要认证）
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags" \
  -H "Authorization: Bearer $TOKEN"

# 触发 Harbor 扫描（需要认证）
curl -X POST "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan" \
  -H "Authorization: Bearer $TOKEN"

# 获取 Harbor 扫描报告（需要认证）
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan" \
  -H "Authorization: Bearer $TOKEN"

# 提交状态回写（需要认证）
curl -X POST "http://URL/api/build/static/commit-status" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"sha":"abc123","state":"success","context":"security-scan","description":"No vulnerabilities"}'

# CORS 预检测试（无需认证）
curl -X OPTIONS "http://URL/api/main/jobs/list" -H "Origin: http://example.com" -v
```
