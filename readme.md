Devops-Glue API 文档 v2.3.0
概述

Devops-Glue 是一套为小企业提供的 DevOps 工具链集成接口，基于 Slim 4 框架实现 Jenkins + Gitlab CI双通道、GitLab/Gitee/GitHub/Gitea、Harbor 等工具的一键集成与数据互通。

环境要求
框架: Slim 4

PHP 版本: 8.0+

PHP 扩展: pdo_sqlite, php-cli, php-mbstring, php-xml, php-curl, php-zip

支持服务:

Jenkins v2.60+（文档声明 v2.555.3为验证版本，API 从 2.0 即稳定可用）

GitLab v9.0+（API v4，文档声明 v17.0为验证版本）

Gitee API v5

GitHub API v3（通过 HTTP header 传递版本，2028年前有效）

Gitea API v1（自建 Git 平台，v2.2.0 新增）

Harbor v1.10.1 / v2.x（自动探测 API 版本）

自定义 Git 平台：实现 GitProviderInterface + 在 settings.php 中注册即可接入

依赖管理: Composer

快速部署
1. 克隆仓库

git clone https://github.com/jeanslw/Devops-Glue.git
cd Devops-Glue
2. 安装依赖

composer install
3. 配置环境变量

cp .env.example .env
编辑 .env 文件，填入实际服务地址和凭证。

4. 配置 Web 服务器
将 public/ 目录设为 Web 根目录，配置 URL 重写。


5. 验证部署
curl http://your-domain.com/api/main/jobs/list

Docker 部署

docker compose up -d

# 访问
curl http://localhost/api/health
浏览器打开 http://localhost/api/docs 查看 API 文档。

注意事项：.env 中的 Jenkins / GitLab / Harbor 地址需使用容器可访问的 IP（如 host.docker.internal 或宿主机 IP），不能写 127.0.0.1。

公共约定
HTTP 方法
所有接口均支持 GET 和 POST 方法（除 build_trigger 仅 POST，扫描触发仅 POST）

输出格式
大部分接口返回字符型数组（如 ["item1","item2"]）

映射/平台接口（/api/main/map/list、/api/main/git/platforms、/api/main/git/discovery）返回 JSON 对象

构建触发（/api/jenkins/.../build_trigger）返回 JSON 对象

控制台日志（/api/jenkins/.../console）返回 text/plain

健康检查（/api/health）返回 JSON 对象

格式切换
所有接口支持通过 ?format= 参数切换输出格式：

参数	输出示例		Content-Type
不带参数（默认 raw）	["java/registry","static"]
?format=json			{"data":["java/registry","static"]}
?format=xml				<?xml...><root><item>java/registry</item></root><application/xml

统一错误格式：
{
  "code": 400,
  "message": "错误描述"
}

CORS 支持
所有接口默认允许跨域访问（Access-Control-Allow-Origin: *），浏览器可直接调用。

如需限制来源，在 config/settings.php 的 cors 段中配置：

'cors' => [
    'allowed_origins' => ['https://your-frontend.com'],
],

日志
日志写入 LOG_PATH 指定的目录（默认 /applogs/），JSON 格式，按天滚动。

日志级别：production → info，其他环境 → debug。

一、健康检查
URL: /api/health

方法: GET

输出: JSON 对象

{
  "status": "ok",
  "checks": {
    "jenkins": true,
    "git": [{"name": "gitlab", "reachable": true}],
    "harbor": true
  },
  "app_env": "production",
  "time": "2026-07-05 12:00:00"
}

status: ok（正常）/ degraded（部分服务不可用）

HTTP 状态码: 200（ok）/ 503（degraded）

二、Main 模块 (/api/main)
2.1 获取所有 Job 列表
URL: /api/main/jobs/list

方法: GET / POST

输出: 字符串数组


["java/registry", "php/myapp", "static"]
2.2 Job / Git / Harbor 三方映射（按项目分组）
URL: /api/main/map/list

方法: GET / POST

输出: JSON 对象（键为 Git 仓库路径）


{
  "tools/registry": {
    "git_platform": "gitlab",
    "git_remote": "http://URL/tools/registry.git",
    "project_id": 2,
    "web_url": "http://your-gitlab/group/project",
    "current_path": "tools/registry",
    "harbor_repository": "mycode/code-runtime",
    "jobs": ["java/registry"]
  },
  "tools/myapp": {
    "git_platform": "gitlab",
    "git_remote": "http://URL/tools/myapp.git",
    "project_id": 5,
    "web_url": "http://your-gitlab/group/myapp",
    "current_path": "tools/myapp",
    "harbor_repository": "mycode/myapp",
    "jobs": ["php/myapp"]
  }
}
2.3 已接入的 Git 平台列表（静态配置）
URL: /api/main/git/platforms

方法: GET / POST

输出: JSON 对象

json
{
  "git_platforms": [
    {
      "name": "gitlab",
      "api_base_url": "http://URL/api/v4",
      "api_version": "v4"
    },
    {
      "name": "github",
      "api_base_url": "https://api.github.com",
      "api_version": "v3"
    },
    {
      "name": "gitee",
      "api_base_url": "https://gitee.com/api/v5",
      "api_version": "v5"
    }
  ],
  "harbor": {
    "api_base_url": "http://URL/api/v2.0",
    "api_version": "v2.0"
  }
}
2.4 平台接入检测（动态扫描）
URL: /api/main/git/discovery

方法: GET / POST

输出: JSON 对象

json
{
  "configured": [
    {"name": "gitlab", "api_base_url": "http://URL/api/v4"},
    {"name": "gitee", "api_base_url": "https://gitee.com/api/v5"}
  ],
  "unconfigured": []
}
三、Jenkins 模块 (/api/jenkins)
3.1 触发构建
URL:

单参数 Job: /api/jenkins/{job}/{branch_value}/build_trigger

双参数 Job: /api/jenkins/{job}/{branch_value}/{zone_value}/build_trigger

方法: POST

输出: JSON 对象

json
{
  "message": "构建触发成功",
  "job": "php/myapp",
  "triggered_params": {"branches": "main", "zone": "test"},
  "queue_id": "114",
  "queue_url": "http://URL/queue/item/114/"
}

校验规则:

参数通过 Query String 传递

Job 必须有恰好 1 或 2 个参数，否则拒绝

参数值必须在对应Git parameter参数选项中（若选项为空则从 Git 平台实时获取）

错误信息提示值不合法。

3.2 查询构建参数
URL: /api/jenkins/{group}/{project}/parameters 或 /api/jenkins/{group}/{project}/{build_id}/parameters

方法: GET / POST

说明:

无 build_id: 返回所有参数及其选项

build_id=0: 返回最新构建的参数名列表

build_id>0: 返回指定历史构建的参数名列表

输出:

默认: {"zone":["prd","test"],"branches":["main","master"]}

0: ["zone","branches"]

>0: ["zone","branches"]

3.3 查询构建状态
URL: /api/jenkins/{group}/{project}/{build_id}/status

方法: GET / POST

输出: ["SUCCESS"], ["FAILURE"], ["ABORTED"], ["UNSTABLE"]

3.4 构建 ID 列表
URL: /api/jenkins/{group}/{project}/build_id

方法: GET / POST

输出: ["12","11","10"]

3.5 成功构建列表（带时间）
URL: /api/jenkins/{group}/{project}/build_time

方法: GET / POST

输出: ["#20 [2026-06-24 18:56:44]","#18 [2026-06-21 17:27:06]"]

3.6 成功构建列表（带 # 号）
URL: /api/jenkins/{group}/{project}/build

方法: GET / POST

输出: ["#14","#13","#12"]

3.7 控制台日志
URL: /api/jenkins/{group}/{project}/{build_id}/console

方法: GET / POST

输出: text/plain

3.8 Git 分支查询（Jenkins 路径）
URL: /api/jenkins/{group}/{project}/branches

方法: GET / POST

说明: 等同于 /api/git/{job}/branches

四、Git 模块 (/api/git)
4.1 查询 Job 对应的 Git 分支列表
URL: /api/git/{group}/{project}/branches

方法: GET / POST

输出: ["master","devops","main"]

说明: 支持 GitLab、Gitee、GitHub 三种平台，自动根据 Job 在映射配置中关联的 Git 仓库查询。

五、Build 模块 (/api/build) — v2.3.0 新增
统一 Jenkins 和 GitLab CI 的构建/Pipeline 入口。

5.1 Pipeline 列表（完整）
URL: /api/build/{project}/pipelines
输出: {"build_provider":"gitlab_ci","project_id":"3","pipelines":[{id,iid,status,ref,sha,web_url,...}]}

5.2 Pipeline 列表（简洁，Jenkins 风格）
URL: /api/build/{project}/pipelines?list=id
输出: ["#10","#9","#8"]

5.3 Pipeline 详情 + Jobs
URL: /api/build/{project}/pipelines/{id}
输出: {"build_provider":"...","project_id":"3","pipeline_id":4,"jobs":[{id,name,stage,status,runner,duration}]}

5.4 Job 日志
URL: /api/build/{project}/jobs/{id}/trace
输出: text/plain 原始日志

5.5 触发构建
URL: /api/build/{project}/trigger  (POST)
参数: {"ref":"main","variables":{"ENV":"test"}}
输出: {"build_provider":"...","project_id":"...","success":true,"pipeline_id":...,"web_url":"..."}

5.6 重试失败的 Pipeline（仅 GitLab CI）
URL: /api/build/{project}/pipelines/{id}/retry (POST)
Jenkins 调用返回: {"success":false,"message":"Jenkins 不支持 retry，请使用 trigger 重新触发构建"}

5.7 取消运行中的 Pipeline（仅 GitLab CI）
URL: /api/build/{project}/pipelines/{id}/cancel (POST)

5.8 构建参数/CI 变量
URL: /api/build/{project}/variables
输出: {"build_provider":"...","project_id":"...","variables":[{key,value:"***",options,...}]}

5.9 Harbor 扫描同步
URL: /api/build/{project}/scan-sync (POST)
参数: {"tag":"v3.0.0"}  不传则取 Harbor 最新 tag
功能: 查 Harbor 扫描报告 → 回写 GitLab commit status + 记录 pipeline→tag 映射

5.10 查询 Pipeline → Tag 映射
URL: /api/build/{project}/tag?pipeline=10
输出: {"build_provider":"...","project_id":"...","tag":"v3.0.0"}
URL: /api/build/{project}/tag  查全部映射

job_git_map 配置:
"build_provider": "jenkins" | "gitlab_ci"  （不填默认 jenkins）

六、Harbor 模块 (/api/harbor)
5.1 获取项目列表
URL: /api/harbor/projects

方法: GET / POST

输出: ["library","mycode","toolkit"]

5.2 获取仓库列表
URL: /api/harbor/{project}/repositories

方法: GET / POST

输出: ["diagnosis-runtime","nginx"]

5.3 获取 Tag 列表
URL: /api/harbor/{project}/repositories/{repository}/tags

方法: GET / POST

注意: 仓库名如含 / 需双重编码（%2F）

输出: ["v1.0.0","v1.0.1"]

5.4 触发镜像扫描
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: POST

输出: {"status":"ok"} 或 {"code":503,"message":"镜像扫描功能未启用，请联系管理员"}

说明: 支持 Harbor v1（1.10.x）和 v2（2.x）两种 API 版本，自动检测。

5.5 获取扫描报告
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: GET

输出: 漏洞报告 JSON 数组，或空数组（无漏洞）

说明: Harbor v2 返回 application/vnd.security.vulnerability.report 格式的漏洞数据。

六、管理后台（v2.3.0 新增）
URL: /admin

说明: Web 管理界面，需登录（账号密码在 .env 中配置 ADMIN_USER / ADMIN_PASSWORD）。

五大功能：
- 服务监测 — Jenkins / Git 平台 / Harbor 连通性 + 版本号实时检测
- 映射管理 — job_git_map 可视化增删改查，数据存储在 SQLite
- 项目拓扑 — Build → Git 仓库 → Harbor 镜像 链路可视化
- 对接版本 — 各平台 API 版本号在线配置
- 修改密码 — 管理员在线修改登录密码

七、API 文档（Swagger UI）
URL: /api/docs

方法: GET

认证: 需登录（与 /admin 共用账号密码）

说明: 浏览器打开后输入管理后台账号登录即可浏览全部接口，支持在线 Try it out 交互式调试。

八、错误处理
所有错误响应统一格式：

json
{
  "code": 400,
  "message": "错误描述"
}
状态码	说明
200	成功
204	CORS 预检成功
400	参数错误（值不合法、个数不匹配等）
404	资源不存在（Job 未找到、路由不存在）
500	服务端错误（Jenkins/Harbor 连接失败等）
503	服务不可用（Harbor 扫描器未启用等）

九、配置说明
环境变量 (.env)
ini
# Jenkins
JENKINS_BASE_URL=http://URL
JENKINS_USER=admin
JENKINS_TOKEN=your_token

# GitLab
GITLAB_BASE_URL=http://URL
GITLAB_TOKEN=your_token

# GitHub
GITHUB_BASE_URL=https://api.github.com
GITHUB_TOKEN=your_token

# Gitee
GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_token

# Gitea
GITEA_BASE_URL=http://your-gitea
GITEA_TOKEN=your_token

# Git 默认平台（URL 无法识别时回退，自建 GitLab/Gitea 必填）
DEFAULT_GIT_PLATFORM=gitlab

# Harbor
HARBOR_BASE_URL=http://URL
HARBOR_USER=admin
HARBOR_PASSWORD=your_password

# 管理后台登录
ADMIN_USER=admin
ADMIN_PASSWORD=

# App
APP_ENV=production
APP_DEBUG=false
BUILD_TIMEOUT=300
LOG_PATH=/applogs/
手动映射配置 (config/settings.php)

每个 Job 可配置以下字段。仅 job_name 必填，其他均可省略由系统自动推导。

| 字段 | 必填 | 说明 |
|------|:--:|------|
| `job_name` | ✅ | Jenkins Job 或 GitLab CI 项目完整路径，如 `"java/registry"` |
| `build_provider` | | CI 系统选择：`jenkins`（默认） / `gitlab_ci`。不填默认 jenkins |
| `git_platform` | | 自建实例**强烈建议**填写。不填则系统自动检测 URL 关键词；但自建 GitLab/Gitea 的域名通常不含平台关键词，检测会失败并回退到 `DEFAULT_GIT_PLATFORM`。可选值：`gitlab` `gitee` `github` `gitea` 或自定义平台名 |
| `git_remote` | | 不填则从 Jenkins Job 的 SCM 配置自动获取 |
| `project_id` | | GitLab：不填自动通过 API 查询；GitHub/Gitee：如已知可填写 |
| `web_url` | | 项目主页链接，仅用于映射展示 |
| `current_path` | | 项目路径，不填从 `git_remote` 自动推导 |
| `harbor_repository` | | 关联的 Harbor 仓库，格式 `"project/repository"`，仅用于映射展示 |
| `api_version` | | **纯元数据**，不影响 API 路由（路由由各 Service 内部硬编码），仅用于映射输出展示 |

php
'job_git_map' => [
    // 自建 GitLab（URL 不含平台关键词 → 必须指定 git_platform）
    [
        'job_name'          => 'java/registry',
        'git_platform'      => 'gitlab',   // ← 自建实例必须
        'git_remote'        => 'http://git.mycompany.com/tools/registry.git',
        'project_id'        => 2,
        'harbor_repository' => 'mycode/code-runtime',
    ],
    // SaaS Gitee（URL 含 gitee.com → 自动检测，可不填 git_platform）
    [
        'job_name'          => 'static',
        'harbor_repository' => 'mycode/static-app',
    ],
],
CORS 配置 (config/settings.php)
php
'cors' => [
    'allowed_origins' => ['*'],   // 允许的域名，* 表示全部
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
],
十、快速测试命令
bash
# 健康检查
curl "http://URL/api/health"

# 触发构建（单参数）
curl -X POST "http://URL/api/jenkins/static/master/build_trigger"
说明：如果使用jenkins中Git Parameter参数化构建，一定要在Job配置的Git仓库配置项 Branch Specifier:origin/(.*)或者Git Parameter配置选项 Default Value:origin/(.*)

# 触发构建（双参数）
curl -X POST "http://URL/api/jenkins/php/myapp/main/test/build_trigger"

# 查询三方映射
curl "http://URL/api/main/map/list"

# 查询已接入的 Git 平台
curl "http://URL/api/main/git/platforms"

# 查询平台接入检测
curl "http://URL/api/main/git/discovery"

# 查询 Job 参数
curl "http://URL/api/jenkins/java/registry/parameters"

# Harbor 项目列表
curl "http://URL/api/harbor/projects"

# Harbor Tag 列表
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags"

# 触发 Harbor 扫描
curl -X POST "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"

# 获取 Harbor 扫描报告
curl "http://URL/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"

# CORS 预检测试
curl -X OPTIONS "http://URL/api/main/jobs/list" -H "Origin: http://example.com" -v

十一、测试

测试脚本如上所示：

十二、项目结构

+---config						# 服务端配置
|   |   .env.example			# 环境变量模板
|   |   AppConfig.php			# 配置访问器
|   |   container.php			# DI 容器定义
|   |   routes.php				# 路由定义
|   |   settings.php			# 主配置 + 映射表
|   |
|   \---data					# 数据库目录
|           data.db				# SQLite 数据库（自动创建，.gitignore 排除）

+---public						# Web 根目录
|   |   .htaccess				# URL 重写
|   |   index.php				# 应用入口
|   |
|   \---assets					#静态资源目录
|
+---src
|   +---Controller				# 控制器
|   |       AdminController.php
|   |       BaseController.php
|   |       BuildController.php
|   |       GitController.php
|   |       HarborController.php
|   |       JenkinsController.php
|   |       MainController.php
|   |
|   \---Service					# 业务逻辑
|       |   Database.php		
|       |   GitService.php
|       |   HarborService.php
|       |   JenkinsService.php
|       |   Logger.php
|       |   MapService.php
|       |
|       +---Build				#构建相关逻辑（v2.3.0引入）
|       |       BuildProviderInterface.php
|       |       BuildProviderRegistry.php
|       |       GitlabCiBuildProvider.php
|       |       JenkinsBuildProvider.php
|       |
|       \---Git					# Git 平台适配器（GitLab/Gitee/GitHub/Gitea + 自定义）
|               GiteaService.php
|               GiteeService.php
|               GithubService.php
|               GitlabService.php
|               GitProviderFactory.php
|               GitProviderInterface.php
|               ProviderRegistry.php
|
+---templates
|       404.html				# Swagger UI
|       admin.html				# 管理页面
|       index.html				# 首页
|       openapi.json			# OpenAPI 3.0 规范
|       swagger.html			# Swagger UI
|-------------------

十三、自定义 Git 平台接入

系统支持通过配置接入任意 Git 平台，无需修改源码。

1. 编写 Provider 类
在 src/Service/Git/ 目录下创建适配器类，实现 GitProviderInterface 接口：

php
namespace App\Service\Git;

class BitbucketService implements GitProviderInterface
{
    public function getName(): string { return 'bitbucket'; }
    public function matchUrl(string $url): bool { return str_contains($url, 'bitbucket'); }
    public function getApiVersion(): string { return 'v2'; }
    public function getBranches(string $repository): array { /* API 调用逻辑 */ }
}
2. 在 config/settings.php 中注册

php
'git' => [
    'custom_providers' => [
        [
            'class'  => 'App\\Service\\Git\\BitbucketService',
            'config' => [
                'name'         => 'bitbucket',
                'base_url'     => 'https://api.bitbucket.org/2.0',
                'token'        => env('BITBUCKET_TOKEN', ''),
                'api_version'  => 'v2',
                'matcher'      => function (string $url): bool {
                    return str_contains($url, 'bitbucket');
                },
            ],
        ],
    ],
],
3. 无需修改任何系统源码，系统启动时自动发现并注册。

注意：自定义平台不支持 .env 独立环境变量配置（如 BITBUCKET_TOKEN），令牌需写入 settings.php 或自行扩展 AppConfig。

4. 内置平台（GitLab/Gitee/GitHub/Gitea）如需新增，需要修改以下文件：
   - src/Service/Git/XxxService.php（适配器类）
   - config/container.php（ProviderRegistry 注册闭包）
   - config/AppConfig.php（getXxxConfig + getDefaultApiVersion + getGitPlatformsConfig）
   - config/settings.php + settings.example.php（配置段）
   - config/.env.example（环境变量声明）
   - readme.md（文档）

十四、更新日志

版本	日期	变更内容
v2.3.0	2026-07-10	增加 GitLab CI 支持、优化jenkins实现双通道，Build统一构建模块 + SQLite 持久化，增加简易 UI 管理界面
v2.2.0	2026-05-06	架构升级：Git 平台改为 ProviderRegistry 注册表模式，支持自定义平台接入。新增 Gitea 平台适配器。
v2.1.2	2026-05-04	新增首页支持健康检查、 GitHub 平台接入；健康检查端点 /api/health；Swagger UI 文档，结构化文件日志；Docker 部署支持；
v2.1.1	2026-03-05	Slim 4 重构。新增 Main 模块（平台接入、多方映射）,多版本支持；输出格式切换（raw/json/xml）；Harbor 扫描集成（Trivy 离线）和扫描报告获取；
v1.1	2021-11-01	增加 Harbor 查询功能
v1.0	2018-09-28	初始版本，Jenkins、Git 与 Rundeck 三方集成

十五、如有建议可在 GitHub 仓库提 issue ，或联系EMAIL:jeanslw@qq.com
