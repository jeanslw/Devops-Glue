# Devops-Glue API 参考 v2.11.0

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
| `/healthz` | GET | 存活/就绪探针（无需认证，只探数据库） |
| `/api/i18n/{locale}` | GET | 获取语言包（`zh_CN` 或 `en`） |
| `/api/docs` | GET | Swagger UI 文档页面（未登录时同页显示登录框） |
| `/api/openapi.json` | GET | OpenAPI 规范（需登录凭证） |
| `/api/admin/login` | POST | 登录，返回 Bearer Token |
| `/api/admin/logout` | POST | 登出，撤销 Token |
| `/admin` | GET | 管理后台页面（HTML，非 JSON API） |
| `/oauth/authorize` | GET/POST | OAuth2 授权码流程：授权确认页 / 提交授权 |
| `/oauth/token` | POST | OAuth2 授权码换取 access token |
| `/oauth/userinfo` | GET/POST | OAuth2 用户信息 |
| `/oauth/userinfo/emails` | GET | GitHub 风格邮箱子端点（兼容按 GitHub emails 流程取邮箱的客户端） |
| `/.well-known/openid-configuration` | GET | OIDC 发现文档（公开；详见[单点登录](单点登录.md)） |
| `/.well-known/jwks.json` | GET | OIDC 签名公钥（公开） |

> **注意：** `GET /api/health` 与 `GET /api/health/static` **需要 Bearer Token**（详见下文「健康检查」章节），不属于公开接口。

---

## 存活/就绪探针

```
GET /healthz
```

无需认证的外部探针，供 Uptime Kuma / 云负载均衡 / 容器编排抓取。只检测数据库可达性、已应用版本与 schema 对齐状态，不探测 Jenkins/Git/Harbor，毫秒级返回。

```json
{
  "status": "ok",
  "db": true,
  "app_version": "2.11.0",
  "schema_version": "2.11.0",
  "seed_version": "2.11.0",
  "schema_current": true,
  "schema_ahead": false,
  "auto_migrate": true,
  "time": 1760000000
}
```

- **HTTP 200 + `status=ok`**：数据库可达且 `schema_current` 为 `true`，或为 `null`（手动建库模式从未跑过结构迁移＝未知：不降级也不谎报对齐）。
- **HTTP 200 + `status=degraded`**：数据库可达但版本未对齐（`schema_current=false`）——可能是**升级了代码还没跑迁移**（`schema_ahead=false`），也可能是**代码回滚降级**（`schema_ahead=true`）；若确实缺表，同时下发 `tables_missing`（缺失的核心表名）。**这种情况不返回 503**：滚动升级期「新代码 + 旧 schema」与回滚期「旧代码 + 新库」都是预期中间态，若一并 503，LB / K8s 会把全部副本摘掉。另：代码内自检发现表清单漂移（`tables_list_drift`）时同样只降级不摘流——坏的是台账，不是数据。
- **HTTP 503 + `status=degraded`**：未就绪——数据库连接失败（`db=false`），或连接正常但版本表缺失（空库未初始化，此时 `db=true`，便于与「真宕机」区分）。
- `schema_current`：**只认 `schema_version`**（仅由执行过 DDL 的路径写入）与当前 `APP_VERSION` 比较的结果；无 `schema_version` 时为 `null`（未知，不判降级）。刻意不用 `seed_version` 回落——种子补齐不代表业务表新增列已就绪。
- `seed_version`：仅 `DB_AUTO_MIGRATE=false` 手动建库模式会记录（自动建库模式为 `null`），服务于播种短路与观测。
- `schema_ahead`：方向位。`schema_version` 高于 `APP_VERSION` 时为 `true`（库比代码新＝疑似**代码降级/回滚**）；无记录时为 `false`。与 `schema_current=false` 组合可区分「没跑结构迁移」与「已回滚降级」。
- `tables_list_drift`：**代码台账自检**（`Database::schemaListDrift()`，纯反射、与库无关），仅在发现不一致时出现，列出「后台数据表清单 / 本探针的 `tables_missing` 会漏报的表名」。正常代码恒不出现；一旦出现，说明该实例代码里 `Database::schemaTables()` 与 `AppConfig` 的 `TABLE_*` 表常量不同步（升级或手工打补丁绕过了 CI）——请更新到含修复的版本，同时会写一条 error 文件日志、后台「系统信息 → 数据表一致性」行也会红字点名。
- 修复：升级后没跑迁移 → 执行 `php cli/migrate.php` 或点后台「数据管理 → 🗄️ 数据库」卡片的「同步库结构」按钮，`schema_current` 会回到 `true`；**回滚降级** → 先按「常见问题 → 回滚/降级指引」处理，再对齐标记。

- `status`: `ok`（数据库可达） | `degraded`（数据库不可达）
- `db`: `true` / `false`
- `schema_version`: 已应用的 schema/种子版本；首次部署尚未标记时为 `null`
- `time`: Unix 时间戳（秒）
- HTTP 200（正常）/ 503（数据库不可达，探针如实上报而非 500）

---

## 健康检查

> **认证：** `/api/health` 需要 Bearer Token（管理员登录 Token 或任意有效 API Token 均可，无需特定 scope）。

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
  "build_mode": "jenkins,gitlab_ci,gitea_ci",
  "build_modes": ["jenkins", "gitlab_ci", "gitea_ci"],
  "build_mode_source": "database",
  "db_driver": "mysql",
  "app_version": "2.11.0",
  "app_env": "production",
  "time": "2026-08-10 12:00:00"
}
```

- `status`: `ok` | `degraded`
- `jenkins`: `true` / `false` / `null`（null = gitlab_ci 模式，不检查 Jenkins）
- `harbor`: `true` / `false` / `null`（null = 未配置镜像仓库地址）
- `stats.harbor_repos`: 活跃映射中出现的不同 `registry_repository` 数量（Harbor 与 registry:2 同口径）
- HTTP 200 (正常) / 503 (降级)

> **说明：** 当镜像仓库类型为 `registry:2`（`registry_type=registry`）时，`checks.harbor` 为 `/v2/` 的可达性布尔值，`harbor_version` 为探测到的 distribution 版本（如 `2.0`，探测不到则为空），可达时 `harbor_components` 为 `{"registry": true}`；Harbor 类型仍按 `core` / `jobservice` / `registry` 三组件探测。两种类型都额外返回 `registry_type`（配置类型）、`registry_detected_type`（地址实际类型：harbor/registry/unknown）、`registry_type_mismatch`（配置与实际不符时为 true，仅告警不阻断）。

### 快速健康信息

```
GET /api/health/static
```

不探测 Jenkins / Git / Harbor 等外部平台，毫秒级返回数据统计 + 系统信息 + Custom_Push 状态，供前端先渲染非探测卡片，避免被外部探测拖慢整个页面：

```json
{
  "status": "ok",
  "stats": {"total_maps": 15, "active_maps": 12, "git_platforms": 2, "harbor_repos": 8},
  "build_mode": "jenkins,gitlab_ci,gitea_ci",
  "build_modes": ["jenkins", "gitlab_ci", "gitea_ci"],
  "build_mode_source": "database",
  "custom_push_enabled": true,
  "custom_push_providers": ["custom_push"],
  "db_driver": "mysql",
  "app_version": "2.11.0",
  "app_env": "production",
  "time": "2026-08-10 12:00:00"
}
```

> **注意：** `/api/health/static` 仅接受管理员登录 Token；API Token 访问一律返回 403（fail-closed）。

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
      "registry_repository": "mycode/code-runtime",
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

> Jenkins / GitLab CI（拉取式）与 Custom_Push（推送式，理论上支持所有 CI）的统一入口。旧版 `/api/jenkins/*` 路由已弃用。
>
> **认证：** 所有 Build 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/build/jobs/list` | GET/POST | CI 管理页视角的构建 Job 列表（raw: Job 名数组；json: 含 Provider 信息）。CD 部署侧请用 `/api/build/projects` |
| `/api/build/projects` | GET/POST | 活跃映射 + 每项目最新 tag（供 CD 部署侧；不过滤 build_mode） |
| `/api/build/config-mode` | GET | 构建配置模式（`{mode, source, has_jenkins, has_gitlab_ci}`） |
| `/api/build/{path}/trigger` | GET/POST | 触发构建（JSON body: `{"ref":"","variables":{"param":"value"}}`） |
| `/api/build/{path}/variables` | GET/POST | 构建参数 / CI 变量（raw: 参数名数组；json: 完整元数据） |
| `/api/build/{path}/branches` | GET/POST | Git 分支列表（纯字符串数组） |
| `/api/build/{path}/pipelines` | GET/POST | 流水线列表（`?list=id\|build\|time\|success`） |
| `/api/build/{path}/pipelines/{id}` | GET/POST | 流水线详情 + Jobs（每项含 `id` + `log_url`）—— 仅 Gitea CI |
| `/api/build/{path}/pipelines/{id}/logs` | GET/POST | 流水线日志（text/plain；`id`=run id，返回该 run 下全部 job 日志拼接）—— 仅 Gitea CI |
| `/api/build/{path}/pipelines/{id}/resolve-tag` | POST | 镜像 Tag 日志兜底懒解析：对 success 且未命中 harbor-scan 回写的流水线，遍历 job 日志提取 tag 并写入缓存（body 可带 `sha` 命中缓存、`force=1` 强制重解析覆盖旧值） |
| `/api/build/{path}/logs/{id}` | GET/POST | 构建日志（text/plain；`id`=job id，取自 `/pipelines/{runId}` 返回的 `jobs[].id`，非 run id） |
| `/api/build/{path}/pipelines/{id}/retry` | POST | 重试流水线（仅 GitLab CI） |
| `/api/build/{path}/pipelines/{id}/cancel` | POST | 取消流水线（仅 GitLab CI） |
| `/api/build/{path}/scan-sync` | POST | Harbor 扫描同步（Harbor 存量入口；与 registry-sync 共用同一套回写实现，额外读取 Harbor 扫描报告。**ci_status 必填**：`success` / `failed` / `running`。`tag` 可选（省略取仓库 tag 列表首个；`registry:2` 顺序由仓库决定，建议显式传）；`pipeline_iid` 与 `sha` 须成对提供，都不提供时回退到最新流水线。artifact 写入闸门：仅 `ci_status=success` 且流水线身份完整；commit status 回写独立于 CI 成败） |
| `/api/build/{path}/registry-sync` | POST | 任意已配置镜像仓库的基础回写；不读取扫描结果（**ci_status 必填**：`success` / `failed` / `running`。`tag` 可选（省略取仓库 tag 列表首个；Harbor 为最近推送，`registry:2` 顺序由仓库决定，建议显式传）；`pipeline_iid` 与 `sha` 须成对提供，都不提供时回退到最新流水线。Harbor 与 `registry:2` 均可用；artifact 写入闸门：仅 `ci_status=success` 且流水线身份完整。响应字段：`build_provider`、`sha`（未知时为 `N/A`）、`tag`、`registry_repository`、`vulnerability_count`、`scan_state`（`unknown`）、`commit_status` `{success, message}`） |
| `/api/build/{path}/tag` | GET/POST | 流水线 → 标签映射（`?pipeline=10`） |
| `/api/build/{path}/tags` | GET/POST | 项目 tag 列表（分页，`?page=1&page_size=50`） |
| `/api/build/{path}/commit-status` | POST | 提交状态回写（安全扫描） |
| `/api/build/{path}/report` | POST | 自定义推送式 CI 构建结果上报（custom_push 专用，单次终态写入） |

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

`POST /api/build/{path}/scan-sync` 为 Harbor 存量入口：除基础回写外，还会读取 Harbor 扫描报告，并将结果回写 Git 平台 commit status（`harbor-scan` 上下文）。`tag` 不传则取仓库 tag 列表的第一个（Harbor 为最近推送的 tag；`registry:2` 的顺序由仓库决定，建议显式传 `tag`）。

两个回写端点共用同一套回写实现（`RegistrySyncService`），差异仅在于是否读取扫描结果。扫描能力由镜像仓库提供方决定：Harbor 支持扫描，`registry:2` 不支持；`registry:2` 场景请使用 `registry-sync`。

请求体：`ci_status` 必填（`success` / `failed` / `running`）；`tag` 可选（省略取仓库 tag 列表首个；Harbor 为最近推送，`registry:2` 顺序由仓库决定，建议显式传）；`sha` 与 `pipeline_iid` 须成对提供，都不提供时回退到最新流水线；`source_updated_at` 可选。

### 镜像仓库回写

`POST /api/build/{path}/registry-sync` 只做基础回写：tag 校验、`ci_pipeline_artifacts` 写入、审计记录与 Git commit status 回写，不读取漏洞扫描结果；Harbor 与 `registry:2` 都可用。

请求体：`ci_status` 必填（`success` / `failed` / `running`）；`tag` 可选（省略取仓库 tag 列表首个；`registry:2` 顺序由仓库决定，建议显式传）；`sha` 与 `pipeline_iid` 须成对提供，都不提供时回退到最新流水线；`source_updated_at` 可选。

写入规则：`ci_pipeline_artifacts` 仅当 `ci_status=success` 且流水线身份完整时写（`failed` / `running` 不产出可部署产物，防止被投影成「已推送成功」）；`ci_security_checks` 审计记录任何 `ci_status` 都写——`writeback_status=success`（推送事实）/ `skipped`（failed、running，仅保留审计记录），commit status 回写不受影响。响应字段：`build_provider`、`sha`（未知时返回 `N/A`）、`tag`、`registry_repository`、`vulnerability_count`、`scan_state`（本端点恒为 `unknown`，除非调用方走 `scan-sync`）、`commit_status`（`{success, message}`）。

审计记录的 `check_type` 为 `registry-sync`；`scan-sync` 为 `harbor-scan`。读取端两者都接受，历史记录仍可见。

### 自定义推送式 CI 结果上报（report）

custom_push 构建源专用：用户 CI 在构建完成后一次性上报终态结果（success/failed/aborted），无 pending/running 中间态。Devops-Glue 只存元数据与日志 URL 指针，不参与构建也不存日志内容。

必填：`pipeline_iid`（构建编号，整数）、`status`（`success`/`failed`/`aborted`）、`finished_at`（构建完成时间）；`status=success` 时 `tag` 必填，`registry_repository` 由 `job_git_map` 配置决定（body 传入会被忽略）。`registry_repository` 格式为至少两段路径（如 `team/project/image`），不得包含 scheme、主机名或端口，不符合格式返回 400；上报时校验仓库与 `tag` 均真实存在于镜像仓库，仓库不存在或 tag 不存在均返回 400。
可选：`started_at`、`ref`、`sha`、`exit_code`、`log_url`、`web_url`。
自定义变量：其余任意字段（如 `env`）存入 `variables_json`。

```json
{
  "pipeline_iid": 123,
  "status": "success",
  "finished_at": "2026-08-18T10:05:12+08:00",
  "started_at": "2026-08-18T10:00:00+08:00",
  "sha": "a1b2c3d4e5f6",
  "ref": "main",
  "exit_code": 0,
  "log_url": "https://ci.example.com/build/123/log",
  "web_url": "https://ci.example.com/build/123",
  "tag": "v1.0.0",
  "env": "prod"
}
```

> `success` 时必须带 `tag` 且能解析出 `registry_repository`，同步写入 `ci_pipeline_artifacts`（provider、project_id、pipeline_iid、project_key、repository、tag、status），CD 层通过 `GET /api/build/{path}/tag` 读取（列表视图可用 `GET /api/build/projects` / `GET /api/build/{path}/tags`）。
> 控制字段（`pipeline_iid`/`status`/`finished_at`/`started_at`/`ref`/`sha`/`exit_code`/`log_url`/`web_url`/`tag`/`registry_repository`）以外的字段存入 `variables_json`。
> `(job_name, pipeline_iid)` 为唯一键，重复上报按覆盖（UPDATE）处理。
```

---

## Git 模块 (`/api/git`)

> **认证：** 所有 Git 接口需要 Bearer Token（通过 `POST /api/admin/login` 获取）。请在请求中添加 `Authorization: Bearer <token>` 头。

```
GET /api/git/{path}/branches
GET /api/git/{path}/tags
GET /api/git/{path}/refs
```

`{path}` 为映射中的 Job 名称（支持 `group/sub/repo` 形式的多级名）。

| 接口 | 方法 | 返回 |
|---|---|---|
| `/api/git/{path}/branches` | GET/POST | 分支名数组：`["master","devops","main"]` |
| `/api/git/{path}/tags` | GET/POST | Tag 名数组：`["v1.0.0","v1.0.1"]` |
| `/api/git/{path}/refs` | GET/POST | `{"branches":[...],"tags":[...]}`（一次拿全，CD 构建页 ref 下拉推荐） |

支持 GitLab、Gitee、GitHub 和 Gitea。自动从 Job 映射中解析 Git 仓库。

> - `{path}` 无仓库映射、或自动识别出的平台未注册时返回空数组（`[]`）；平台不可达时返回 `404` + `git.query_failed`（不把平台故障伪装成「仓库没有 tag」）。
> - 需要 `git` scope。CD 服务 Token 由「集成服务 → 启用」自动签发并包含该 scope（v2.9.0 起）；v2.8.x 已签发的存量凭据在升级引导时会**自动补齐**该 scope，无需重新启用服务、也无需更换 Token。
> - `refs` 等价于同时调用 `branches` 与 `tags`，但只解析一次 Job→仓库映射、只构造一次平台客户端，且两组值必然来自同一仓库快照。任一类型查询失败即整体 404。

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

## Registry 模块 (`/api/registry`)

> **认证：** 所有 Registry 接口需要 Bearer Token，scope 为 `registry.read`（复用现有 Harbor 读权限，已持有 `registry.read` 的 Token 对两种仓库类型都继续可用，无需重签）。**注意：CD 服务凭据不含 `registry.read`**，用服务凭据调用本模块会被判 403——请在「API 管理」新建或编辑一个 API Token 并授予 `registry.read`，或改用 `POST /api/admin/login` 登录。请在请求中添加 `Authorization: Bearer <token>` 头。
>
> **与 `/api/harbor` 的关系：** 本模块按全局镜像仓库类型（`ci_app_settings.registry_type`：`harbor` | `registry`）分派到当前提供方，Harbor 与标准 OCI Distribution（`registry:2`）**都可用**；`/api/harbor` 保持 Harbor 专用契约不变（扫描触发/扫描报告等仅 Harbor 有），已对接方无需改动。因此 `registry:2` 部署下想列出项目/仓库/tag，请用本模块。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/registry/projects` | GET/POST | 项目列表。Harbor 取 Harbor 项目；`registry:2` 取 `/v2/_catalog` 中仓库名的首段去重聚合（该协议没有 project 概念） |
| `/api/registry/{project}/repositories` | GET/POST | 仓库列表（**去项目前缀的短名**，与 `/api/harbor` 同口径） |
| `/api/registry/{project}/repositories/{repository}/tags` | GET/POST | Tag 列表。`repository` 传短名；短名含 `/`（如 `mycode/a/b` 的短名 `a/b`）时需双重编码为 `%252F` |

状态码约定（与 `/api/harbor/*` 一致，避免同一端点在两种仓库类型下状态码不同）：

| 状态码 | 含义 |
|---|---|
| `200` | 名称/tag 数组 |
| `400` | 项目名 / 仓库短名非法（含 `..`、空段、路径分隔以外的非法字符）；`/api/harbor/*` 维持既有契约不做该校验 |
| `403` | 当前用户 / Token 缺少 `ci.build-records.pull` 权限 |
| `409` | 镜像仓库未配置 `url`（配置问题，不当作服务异常） |
| `500` | 提供方业务错误（项目/仓库不存在、`/v2/_catalog` 未开启、上游认证失败等），响应带原始 `message` |
| `502` | 连接级失败（提供方不可达，由中间件统一映射） |

> **`registry:2` 注意事项：**
> - 项目列表依赖 `/v2/_catalog`。仓库侧未开启（多数生产配置默认关闭）时返回 `500` + `message`，此时只能按已知的完整仓库名直接查 tag。
> - Tag 顺序由仓库决定（非「最近推送在前」，Harbor 才是最近推送在前）；调用方勿假设顺序，需要最新 tag 时用 Digest/创建时间自行判断或显式指定。
> - 单段根级仓库（如 `/v2/static/tags/list`，完整名无 `/`）不计入项目列表——它没有可归属的首段项目名。

**curl 示例：**

```bash
# 项目列表
curl "http://URL/api/registry/projects" -H "Authorization: Bearer $TOKEN"

# 仓库列表
curl "http://URL/api/registry/mycode/repositories" -H "Authorization: Bearer $TOKEN"

# Tag 列表（短名不含 / 时直接写；含 / 时用 %252F 双重编码）
curl "http://URL/api/registry/mycode/repositories/diagnosis-runtime/tags" -H "Authorization: Bearer $TOKEN"
```

---

## 集成服务契约模块 (`/api/integration`)

> **用途：** 供 CD 等集成服务在运行时自检「本服务是否被 Glue 授权、以哪种建表方式授权」，**免于直读共享库的 `ci_integrated_services` 表**（表名/列名/驱动都是耦合面：CI 改库结构会弄坏 CD，将来拆库独立部署更是直接失效）。契约仍由 CI 唯一写入，消费方只读这一个窄接口。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/integration/services/{service}` | GET | 集成服务契约状态（`service` 目前仅支持 `cd`） |

响应（`format=json` 时包在 `data` 里，默认 `raw` 直接输出对象）：

```json
{
  "service": "cd",
  "enabled": true,
  "provision": "auto",
  "token_active": true,
  "updated_at": "2026-10-14 10:00:00",
  "registry_type": "harbor",
  "registry_url": "https://hub.example.com"
}
```

**判定口径（消费方必须按此实现，不要自行发明解释）：**

| 响应 | 含义 | 消费方动作 |
|---|---|---|
| `200` + `enabled=false` | **未启用**（契约行不存在或 `enabled=0`，两者对外同义） | 拒绝对外服务（零 DDL）；`provision` 此时归一化为 `auto`，仅为日志措辞，**不得**据此建表 |
| `200` + `enabled=true` + `provision=auto` | 被授权**自动建表 / 补齐既有表缺失列** | 可建表 |
| `200` + `enabled=true` + `provision=manual` | 禁止建表 | 只认运维预建表，缺表按未部署处理 |
| `404` | 服务名不在白名单（配置写错） | **不得当成「未启用」**：拼错服务名会静默退化成「一直未启用」而无人察觉 |
| `401` / `403` | Token 无效 / 缺 `build.read` scope | 先修凭据，不要据此改变业务判定 |
| 其它错误（含旧版 CI 无此端点时的 404/405、网络异常） | 端点不可用 | **回退到旧口径**（直读契约表），不得把「端点不可用」当成「未启用」 |

> - **scope：** 复用 `build.read`（CD 服务凭据已持有，无需扩权）；控制器内二次校验 `ci.build-records.pull`（由 scope 自动注入）。
> - **`token_active` 仅供展示**（契约行绑定凭据的活性，如提示「凭据已失效，请在 CI 重新启用集成服务」），它与调用方自己的 Token 通常是同一枚（否则本次调用已被拒），**不可作为闸门条件**。
> - 本端点刻意**不返回** `token_id` / `token_name` / `token_scopes` / `updated_by`（管理面字段，见 `/api/admin/integrated_services/{service}`）。字段名与管理的同名子集保持一致，便于同时使用两条通道的消费方共用映射。
> - **`registry_type` / `registry_url`（v2.11 起）**：镜像仓库类型（`harbor` | `registry`）与地址。Glue 是镜像仓库的事实源（镜像 push 由 Glue 构建任务完成），消费方应以契约值为准、本地环境变量仅作端点不可用（旧版 CI / 网络异常）时的兜底。**两字段仅当凭据持有 镜像仓库 查询 scope（registry.read，同时管 Harbor 与 registry:2 查询）时下发**——消费方拿到地址后会用自己的凭据直连仓库，此后不再经过 Glue 接口，闸门必须设在下发这一步；无该 scope 或 `registry_url` 未配置时均为空串。CD 的服务凭据默认不含该 scope：需要使用镜像制品库时，在「API 管理」中为凭据勾选 镜像仓库 查询。契约四要素（service / enabled / provision / token_active）不受该 scope 影响。`registry:2` 的认证方式由消费方按 Docker Auth 规范自动探测，不经契约传递。

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
  "username": "admin",
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
| `/api/admin/custom_builds` | GET | Custom_Push 上报的构建记录（支持 `?page=&per_page=` 分页；需 `ci.mode.edit`） |
| `/api/admin/security_checks` | GET | 安全扫描审计记录（支持 `?project=&check_type=&state=&writeback=&exclude=&page=&per_page=` 筛选） |
| `/api/admin/platform_versions` | GET/PUT | 平台 API 版本配置 |
| `/api/admin/platform_versions/probe` | GET | 实际探测 Harbor / Jenkins 版本（慢速探测独立端点，与毫秒级返回配置态的列表分离，避免平台不可达时整页卡住；需 `ci.platform-edit`） |
| `/api/admin/build_mode` | GET/PUT | 构建模式（启用的 CI 源集合，如 jenkins,gitlab_ci,gitea_ci） |
| `/api/admin/platform_config` | GET/PUT | 平台接入状态（脱敏，仅返回各平台 `configured` 布尔，不返回 URL/账号/凭证）+ 平台级 tag 设置（清理/回填开关、日志关键字）与 API 调用日志设置；PUT 仅更新请求体显式携带的键，避免互相覆盖（需 `ci.platform-config`） |
| `/api/admin/system_info` | GET | 数据库 schema 状态（驱动 / schema 版本 / 是否当前 / 迁移模式 `auto_migrate`（`DB_AUTO_MIGRATE` 生效值）/ PHP 版本 / 核心表存在性 / 探测清单自检 `schema_list_drift`（`AppConfig` 表常量与探测清单是否一致，即后台「数据表一致性」行的数据源）；需 `ci.system`） |
| `/api/admin/migrate` | POST | 手动同步库结构（补建缺失表 + 补齐字段/索引 + 种子权限 + 标记 schema 当前；账号无 DDL 权限时返回 409 并提示改用 `php cli/migrate.php`；仅 super_admin） |
| `/api/admin/backup` | POST | 手动执行数据库备份（zip 归档，滚动留存最近 10 份；启用 CD 时额外产出一份 CD 库归档；仅 super_admin） |
| `/api/admin/backups` | GET | 列出已生成的数据库备份文件（仅 super_admin） |
| `/api/admin/integrated_services/{service}` | GET | 集成服务启用状态（当前仅 `cd`；返回 enabled / provision（auto\|manual）/ default_provision（按当前迁移模式推导）/ 配套 token 软引用与三态（`token_exists` / `token_enabled` / `token_expired`）及活性 `token_active`（= 存在 && 未停用 && 未过期）/ 启用时将签发的 scopes；行不存在返回 enabled=false 默认态；需 `ci.system`） |
| `/api/admin/tag_cleanup` | POST | 手动立即执行一次过期 tag 清理（等价 `cli/cleanup-pipeline-tags.php`；对应开关未开启或 Harbor 未配置时返回 409 安全跳过；需 `ci.platform-config`） |
| `/api/admin/tag_backfill` | POST | 手动立即执行一次镜像 Tag 日志回填（等价 `cli/backfill-pipeline-tags.php`；同上，仅补缺失 tag 不覆盖扫描结果；需 `ci.platform-config`） |
| `/api/admin/operation_logs` | GET | 操作审计日志（支持 `?username=&action=&result=&operator_type=&date_from=&date_to=&page=&per_page=` 筛选；需 `ci.operation-logs`） |
| `/api/admin/deploy_logs` | GET | CD 部署记录审计列表（只读，经 CD 的 `/api/integration/deploy-logs` 只读端点拉取，不直读 CD 表；支持 `?project=&status=&deploy_type=&date_from=&date_to=&page=&per_page=` 筛选；需 `ci.deploy-logs`） |
| `/api/admin/api_access_logs` | GET | API Token 调用审计列表（只返回 token 展示名等审计字段，绝无 token 原文/hash/body/query；支持 `?result=&method=&token_name=&route=&date_from=&date_to=&page=&per_page=` 筛选；需 `ci.api-logs`） |
| `/api/admin/users` | GET | 用户列表（admin 可见全部；非 admin 看不到 admin 用户） |
| `/api/admin/users` | POST | 创建用户（body: `username`、`password`、`role`、`systems`） |
| `/api/admin/users/{username}` | PUT | 更新用户（body: `password` 和/或 `role`） |
| `/api/admin/users/{username}` | DELETE | 删除用户（不可删除根账号和自己） |
| `/api/admin/users/{username}/password` | PUT | 重置他人密码（body: `new_password`，最短 8 位；仅 super_admin） |
| `/api/admin/users/{username}/status` | PUT | 启用 / 停用用户（body: `enabled: true|false`，兼容 `status: 1|0`；停用即时踢下线，root 不可停用；需 `ci.users.manage_admin`） |
| `/api/admin/roles` | GET | 角色列表 |
| `/api/admin/roles` | POST | 创建自定义角色（需 `ci.users.manage_admin`） |
| `/api/admin/roles/{id}` | PUT | 更新角色权限（全量替换，需 `ci.users.manage_admin`） |
| `/api/admin/roles/{id}` | DELETE | 删除自定义角色（需 `ci.users.manage_admin`） |
| `/api/admin/permissions` | GET | 权限列表（含 `is_builtin` / `created_at`）+ 隐含规则（`implied` / `builtin_implied`）（需 `ci.permissions.list`） |
| `/api/admin/permissions` | POST | 注册权限（body: `perm_key`、`description`、`parent_key?`；已存在则更新并保留首次注册时间；需 `ci.permissions.register`） |
| `/api/admin/permissions/{perm_key}` | DELETE | 删除权限（内置权限不可删，注册权限可删；级联清理 role_permissions/implied_rules；需 `ci.permissions.register`） |
| `/api/admin/implied_rules` | POST | 创建隐含规则（body: `source_key`、`target_key`；需 `ci.permissions.rules`） |
| `/api/admin/implied_rules` | DELETE | 删除隐含规则（`?source_key=&target_key=`；内置隐含规则不可删，用户新增的可删；需 `ci.permissions.rules`） |
| `/api/admin/me/permissions` | GET | 获取当前用户权限（super_admin 返回通配符 `"*"`） |

### 权限校验规则

- 创建/修改/删除 **管理员** 用户需要 `ci.users.manage_admin` 权限
- 创建/修改自定义角色需要 `ci.users.manage_admin` 权限
- 权限管理操作需要对应的 `ci.permissions.*` 权限

---

## RBAC 模块 (`/api/rbac`)

> **CD 服务账号专用**：供 Devops-Glue CD 等可信服务管理 CD 用户与角色，不面向浏览器后台。鉴权仅接受 **API Token**（需包含 `rbac.user.write` scope，创建方式见下文「API Token 管理」）；使用登录态 Token 调用一律返回 403。与 `/api/admin/users` 交互式后台的差异：本模块创建 / 更新的用户 `systems` 恒为 `cd`，禁止创建或删除 `super_admin`，密码最短 8 位。
>
> **v2.8.1 起的安全边界**：更新 / 删除接口只允许操作 `systems` 含 `cd` 的账号（非 CD 账号返回 403）。原 `verify-password` 校验密码接口已移除——CD 侧登录改为本地校验自有账号密码，无需再经 CI 验密。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/rbac/users` | POST | 创建 CD 账号（body: `username`、`password`、`role`） |
| `/api/rbac/users` | GET | CD 账号列表 |
| `/api/rbac/users/{username}` | GET | 查询单个账号 |
| `/api/rbac/users/{username}` | PUT | 更新账号（body: `password` 和/或 `role`） |
| `/api/rbac/users/{username}` | DELETE | 删除账号（root 与 super_admin 受保护） |
| `/api/rbac/roles` | GET | 可用角色列表 |
| `/api/rbac/roles/{name}/permissions` | GET | 角色权限清单（供 CD 做会话权限收敛与映射；角色不存在返回 404，`super_admin` 返回 `["*"]` 通配） |

---

## OAuth2 / OIDC 模块 (`/oauth`、`/.well-known`)

> 供外部系统用 Glue 账号登录（OAuth2 授权码流程），并为 Jenkins oic-auth / Harbor OIDC / GitLab OmniAuth 提供自动发现。完整配置与流程说明见[单点登录](单点登录.md)。

| 接口 | 方法 | 说明 |
|---|---|---|
| `/oauth/authorize` | GET | 授权码流程：展示授权确认页（浏览器跳转） |
| `/oauth/authorize` | POST | 提交授权（账号密码登录 + 允许/拒绝；与登录接口共用 5 次/15 分钟失败限流） |
| `/oauth/token` | POST | 授权码换取 access token |
| `/oauth/userinfo` | GET/POST | 用户信息（access token 鉴权；GET/POST 均注册，兼容以 POST 取用户信息的客户端） |
| `/oauth/userinfo/emails` | GET | GitHub 风格邮箱子端点（兼容按 GitHub emails 流程取邮箱的客户端） |
| `/.well-known/openid-configuration` | GET | OIDC 发现文档（公开） |
| `/.well-known/jwks.json` | GET | OIDC 签名公钥（仅公钥，不泄露私钥） |

---

## API Token 管理（服务账号 / 第三方调用）

> 供 CD 系统服务账号（Jenkins / GitLab CI 脚本）或第三方系统调用。API Token **独立于 RBAC 权限体系**，直接携带接口权限清单（scope），不关联任何用户或角色。
>
> 仅 **super_admin** 可通过管理 UI（「API 管理」菜单）或下方接口创建 / 撤销 / 删除 Token。

### 核心概念

- Token 明文格式为 `dg_` 前缀 + 64 位十六进制，例如 `dg_8f3a…`。
- 服务端只存 `sha256(明文)` 摘要，**明文仅在创建时返回一次**，请立即保存。
- Token 可设置过期时间（`expires_at`，Unix 秒），留空表示永不过期。
- Token 通过标准 `Authorization: Bearer <token>` 头使用，与管理员登录 Token 共用同一鉴权入口。

### Scope 目录（创建 Token 时勾选）

| Scope key | 说明 | 覆盖接口 |
|---|---|---|
| `main` | MAIN（只读） | `/api/main/*` |
| `git` | GIT（只读：分支 / Tag / refs） | `/api/git/*` |
| `registry.read` | 镜像仓库 查询 | `/api/harbor/*`（除扫描触发外）、`/api/registry/*`（两种仓库类型通用） |
| `harbor.scan` | Harbor 扫描触发 | `POST /api/harbor/{project}/repositories/{repo}/tags/{tag}/scan` |
| `build.read` | 构建查询 + 集成服务契约 | `/api/build/*`（除下方写/回写接口外）、`/api/integration/*` |
| `build.write` | 构建执行 | `trigger` / `retry` / `cancel` |
| `build.report` | 构建回写 | `scan-sync` / `registry-sync` / `commit-status` / `report`（CI 脚本回调） |
| `rbac.user.write` | CD 服务账号管理 | `/api/rbac/*` 用户与角色的建/读/改/删 |

> **说明：**
> - `/api/health` 无需 scope，任意有效 Token 即可访问；`/api/health/static` 对 API Token 同样禁止（fail-closed，403）。
> - `/api/admin/*`（含管理接口）对 API Token **一律禁止**（fail-closed），即使持有任意 scope 也返回 403。
> - 未知路径同样 fail-closed，返回 403。
> - 每个 Token 可同时勾选多个 scope；scope 之间无包含关系。

### 管理接口（仅 super_admin）

| 接口 | 方法 | 说明 |
|---|---|---|
| `/api/admin/api_tokens/scopes` | GET | 返回可选 scope 目录（`{"scopes":[{"key":"main","label":"MAIN（只读）"},…]}`） |
| `/api/admin/api_tokens` | GET | Token 列表（**不含明文**，返回 `{"tokens":[…]}`） |
| `/api/admin/api_tokens` | POST | 创建 Token，返回一次性明文 |
| `/api/admin/api_tokens/{id}/scopes` | PUT | **编辑 Scope**：只改元数据（明文与 sha256 摘要不变，**无需更换 Token**，下一个请求即按新 Scope 校验）；scope 须全部来自目录且至少一个（否则 400），Token 不存在 404 |
| `/api/admin/api_tokens/{id}/revoke` | POST | 撤销（**停用**，保留记录与摘要；它是**可恢复**的：集成服务的配套凭据在「该服务重新启用」时被恢复成同一枚 —— 原明文继续可用），返回 `{"ok":true}` |
| `/api/admin/api_tokens/{id}` | DELETE | 删除（**硬删除**记录，摘要一并消失，不可恢复 → 集成服务再次启用时只能补发新凭据），返回 `{"ok":true}` |
| `/api/admin/integrated_services/{service}/enable` | POST | 启用集成服务：事务内 upsert 契约行（provision 默认按当前 `DB_AUTO_MIGRATE` 取 auto/manual，可 body 显式覆盖），并按状态走三条路径 —— ① 首次启用：签发限定 scope（build.read / build.write / rbac.user.write）服务 Token，响应一次性返回明文 `token`；② **冻结恢复**：契约行未启用、凭据只是被停用（撤销）且未过期 → **恢复同一枚凭据**（原明文重新生效，CD 无需改配置），响应 `reused=true` 且 `token` 为 null；③ **作废补发**：凭据被撤销（契约行仍启用）/ 删除 / 过期 → 同事务作废旧凭据 + 签发新凭据并重绑，响应 `reissued=true` + 新明文。仅当「已启用且凭据有效」时 409；仅 super_admin |
| `/api/admin/integrated_services/{service}/disable` | POST | 禁用（冻结）：事务内置 enabled=0 并**停用**（**非**作废）配套 Token —— 凭据行与绑定关系都保留，再次启用即恢复同一枚（CD 无需改配置，要作废/换发请先撤销或删除那枚 Token）；不删表不删数据；未启用 409；仅 super_admin |

#### 创建 Token

请求体：

```json
{
  "name": "jenkins-scan-bot",
  "scopes": ["main", "registry.read", "harbor.scan", "build.report"],
  "expires_at": 1786579200,
  "note": "Jenkins 扫描回写脚本使用"
}
```

- `name` 必填；`scopes` 数组（非法 key 会被忽略）；`expires_at` 可选（Unix 秒，留空永不过期）；`note` 可选。

响应（**明文仅此一次**）：

```json
{
  "id": 1,
  "token": "dg_8f3a4b1c…（64位十六进制）"
}
```

#### 编辑 Token 的 Scope

请求体：

```json
{
  "scopes": ["main", "build.read"]
}
```

- 只改 `scopes` 列（**元数据**）：明文 Token 与 sha256 摘要都不变，调用方**无需更换** Token，**下一个请求**即按新 Scope 校验——用于给已发出的 Token 补权限或收回权限（`build.report` 这类回写权限尤其适合按需临时补授）。
- `scopes` 必须**全部**来自目录且**至少一个**：含目录外的 key 返回 400（「包含未知的 Scope，请重新选择」）、空集合返回 400（「请至少勾选一个接口权限」）。这与创建时「静默丢弃非法 key」刻意不同：编辑是**权限收放**动作，勾错了必须报错，不能静默掉权或增权。要彻底失效请用「撤销」。
- Token 不存在返回 404（「Token 不存在或已被删除」）。仅 `super_admin`；每次编辑写入操作日志 `update_api_token_scopes`（含保存后的 scope 清单）。

响应：

```json
{
  "ok": true,
  "id": 1,
  "scopes": ["main", "build.read"]
}
```

### 使用示例

```bash
# ① super_admin 登录（管理接口用）
ADMIN_TOKEN=$(curl -s -X POST "http://URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"your_password"}' | jq -r '.data.token')

# ② 创建 API Token（返回一次性明文）
NEW_TOKEN=$(curl -s -X POST "http://URL/api/admin/api_tokens" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"name":"jenkins-scan-bot","scopes":["main","registry.read","harbor.scan","build.report"]}' \
  | jq -r '.token')

# ③ 用 API Token 调用业务接口（与管理员登录 Token 用法一致）
curl "http://URL/api/main/map/list" \
  -H "Authorization: Bearer $NEW_TOKEN"

# 触发 Harbor 扫描（需 harbor.scan scope）
curl -X POST "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan" \
  -H "Authorization: Bearer $NEW_TOKEN"

# 扫描结果回写（需 build.report scope，CI 脚本中调用）
# ci_status 必填：success=artifact+回写，failed/running=仅回写（不写 artifact）
curl -X POST "http://URL/api/build/static/scan-sync" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $NEW_TOKEN" \
  -d '{
    "ci_status": "success",
    "tag": "v1.0.0",
    "pipeline_iid": 42,
    "sha": "abc123def"
  }'

# 任意镜像仓库的基础回写（不读取扫描结果；registry:2 场景使用）
curl -X POST "http://URL/api/build/static/registry-sync" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $NEW_TOKEN" \
  -d '{
    "ci_status": "success",
    "tag": "v1.0.0",
    "pipeline_iid": 42,
    "sha": "abc123def"
  }'

# 自定义推送式 CI 结果上报（需 build.report scope，custom_push 专用）
curl -X POST "http://URL/api/build/php/myapp/report" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $NEW_TOKEN" \
  -d '{"pipeline_iid":123,"status":"success","finished_at":"2026-08-18T10:05:12+08:00","exit_code":0,"log_url":"https://ci.example.com/build/123/log","tag":"v1.0.0","env":"prod"}'

# ④ 编辑 Token 的 Scope（只改元数据：Token 明文无需更换，下一个请求即生效）
curl -X PUT "http://URL/api/admin/api_tokens/1/scopes" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"scopes":["main","build.read"]}'

# ⑤ 撤销 Token（禁用，保留记录）
curl -X POST "http://URL/api/admin/api_tokens/1/revoke" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# ⑥ 删除 Token（硬删除记录）
curl -X DELETE "http://URL/api/admin/api_tokens/1" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

> **注意：** 若 Token 未持有某接口所需的 scope，接口返回 `403`（`{"code":403,"message":"API Token 无权访问此接口"}`）。管理接口对 API Token 一律返回 403。

---

## 快速测试命令

```bash
# 登录获取 Token
TOKEN=$(curl -s -X POST "http://URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"your_password"}' | jq -r '.data.token')

# 健康检查（需要认证；登录 Token 或任意有效 API Token 均可）
curl "http://URL/api/health" \
  -H "Authorization: Bearer $TOKEN"

# 快速健康信息（不探测外部平台；仅接受管理员登录 Token）
curl "http://URL/api/health/static" \
  -H "Authorization: Bearer $TOKEN"

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

# 任意镜像仓库（Harbor / registry:2）的项目 / 仓库 / 标签列表（需要认证，scope=registry.read）
curl "http://URL/api/registry/projects" -H "Authorization: Bearer $TOKEN"
curl "http://URL/api/registry/mycode/repositories" -H "Authorization: Bearer $TOKEN"
curl "http://URL/api/registry/mycode/repositories/diagnosis-runtime/tags" -H "Authorization: Bearer $TOKEN"

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
