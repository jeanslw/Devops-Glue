# 项目需求文档

## 环境信息
- **Slim**: 4
- **Harbor**: v1.10.1
- **GitLab**: v17.0
- **Jenkins**: v2.555.3
- **PHP**: v8.3

---
## 配置文件
- `config/setting.php`
- `.env`
## 目录结构
##├── .env
##├── .gitignore
##├── .laragon
##├── composer.json
##├── composer.lock
##├── debug_class.php
##├── readme.md
##├── .github
##│ └── workflows
##│ └── security.yml
##├── bin
##│ ├── pre-deploy-check.php
##│ └── syntax-check.php
##├── config
##│ ├── container.php
##│ ├── EnvLoader.php
##│ ├── routes.php
##│ └── settings.php
##├── Exceptions
##│ └── ApiException.php
##├── public
##│ ├── .htaccess
##│ ├── debug.log
##│ └── index.php
##├── src
##│ ├── Controller
##│ │ ├── BaseController.php
##│ │ ├── HarborController.php
##│ │ └── JenkinsController.php
##│ └── Service
##│ ├── GitlabService.php
##│ ├── HarborService.php
##│ └── JenkinsService.php
##└── vendor
---

## 严格要求

1. 所有输出均为**字符型数组格式**：`["xxx","yyy"]`。
   - **例外**：第二节的 8、9、10 条，返回 JSON 和 `text/html` 格式。
2. `config/routes.php` 重要说明：
   - Jenkins 启用文件夹，文件夹 `{group}`，Job 名 `{project}`。
   - 也存在不包含文件夹的 Job。

> **提示**：完成开发后需牢记所有要点，避免遗漏。确保交付质量，不应反复出现相同错误。

---

## 一、路由统一要求

- 不区分一级和多级项目请求，需兼容两种输入方式：
  - **A 类**：输入接口为 2 级或 1 级（兼容 1 级和 2 级）
  - **B 类**：输入接口统一为 1 级（只接受 Job 名称）
- **倾向 B 类**，但开发者需确保所有功能兼容。

### 多级与一级判断逻辑

- **多级（超过两级）**：取最后一段为 `job`，其余为 `folder`。
  - 示例：`AA/BB/CC` → `job = CC`，`folder = AA/BB`
  - 校验逻辑：
    - 检查 `folder` 是否存在且类型为 `folder`
    - 检查 `job` 是否存在且类型为 `job`（Pipeline 或 Multibranch）
    - 若 `folder` 或 `job` 不匹配，返回对应错误信息。
- **一级**：判断请求参数是否为 Job 类型，若是则继续，否则提示非 Job 类型。

### 开发原则

- **A. 统一全局函数**：避免重复定义功能相似的函数，通过兼容逻辑复用同一函数。
- **B. 复用优先**：尽量复用已有方法和数据源。
  - 例如，第 1 条中的构建参数可从第 8 条获取，分支列表可从第 7 条获取。
- **C. 严格避免硬编码**。

---

## 二、`/api/jenkins` 模块接口列表

> 支持 GET 和 POST 方法，统一向 Jenkins API 和 Git 仓库查询。

| 功能 | 请求方法 | 接口地址 | 说明 |
|------|----------|----------|------|
| **1. 构建请求** | GET/POST | `/api/jenkins/{group}/{project}/{branches}/{zone}/build_trigger` | 向 Jenkins 发送带参数构建请求。<br>需校验 `branches` 和 `zone` 是否在 Jenkins 参数列表和 Git 分支列表中。<br>校验失败返回错误参数列表，成功返回 `["构建触发成功"]`。 |
| **2. 所有 Job 列表** | GET/POST | `/api/jenkins/jobs/list` | 返回所有 Job 名称（包括文件夹下的 Job 和独立 Job），如 `["myapp","static"]`。 |
| **3. 查询成功构建状态** | GET/POST | `/api/jenkins/{group}/{project}/{build_id}/status` | 返回构建状态，如 `["SUCCESS"]`、`["FAILURE"]` 等。 |
| **4. 查询成功构建 ID 列表** | GET/POST | `/api/jenkins/{group}/{project}/build_id/list` | 返回构建 ID 列表，如 `["12","11","10"]`。 |
| **5. 查询带时间日期的构建列表** | GET/POST | `/api/jenkins/{group}/{project}/build_time/list` | 返回带时间日期的构建列表，如 `["#20 [2026-06-24 18:56:44]","#18 [2026-06-21 17:27:06]"]`。 |
| **6. 查询带 '#' 的构建列表** | GET/POST | `/api/jenkins/{group}/{project}/build/list` | 返回带 '#' 的构建列表，如 `["#14","#13","#12"]`（可与 4、5 复用方法）。 |
| **7. 查询 Job 对应的分支列表** | GET/POST | `/api/jenkins/{group}/{project}/branches/list` | 从 Jenkins 获取 Job 的 Git 地址，再向 GitLab API 查询分支列表，如 `["master","devops","test"]`。 |
| **8. 查询 Job 构建参数列表** | GET/POST | `/api/jenkins/{group}/{project}/{build_id}/parameters/list` | 返回构建参数，如 `{"branches": "master", "zone": "dev"}`（JSON 格式）。 |
| **9. 查询所有 Job 对应的 Git 仓库** | GET/POST | `/api/jenkins/job/git/list` | 返回 Job 与 Git 仓库对应关系，支持 Gitee/GitHub/私有 Git，如 `{"job_name": "myapp","git_repository": {"name": "frontend","url": "https://gitee.com/example/frontend-app.git"}}`（JSON 格式）。 |
| **10. 控制台构建日志获取** | GET/POST | `/api/jenkins/{group}/{project}/{build_id}/console` | 返回 `text/html` 格式的日志，逐行显示与 Console Output 一致。 |

---

## 三、`/api/harbor` 模块接口列表

> 支持 GET 和 POST 方法，统一向 Harbor API 查询。

| 功能 | 请求方法 | 接口地址 | 说明 |
|------|----------|----------|------|
| **获取项目列表** | GET/POST | `/api/harbor/{projects}/list` | 返回项目列表，如 `["library","mycode","toolkit"]`。 |
| **获取仓库列表** | GET/POST | `/api/harbor/{projects}/{repository}/list` | 返回仓库列表，如 `["library","mycode","toolkit"]`。 |
| **获取 Tags 列表** | GET/POST | `/api/harbor/{projects}/{repository}/tags/list` | 返回标签列表，如 `["v1.10.1","v1.10.0","v1.9.0"]`。 |

---

## 四、快速测试命令示例（curl）

```bash
# 构建请求
# project: myapp, branch: master, zone: prod
curl -X POST "{BASE_URL}/api/jenkins/my-app/master/prod/build_trigger"
# 返回示例: ["build success"]

# 查询构建状态
# project: myapp, build_id: 128
curl "{BASE_URL}/api/jenkins/my-app/128/status"
# 返回示例: ["SUCCESS"]

# 获取 Harbor Tag 列表
# project: toolkit, repository: goharbor/chartmuseum-photon
curl "{BASE_URL}/api/harbor/toolkit/goharbor/chartmuseum-photon/tags/list"