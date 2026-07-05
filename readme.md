Devops-Glue API 文档 v2.2
概述
Devops-Glue 是一套为小企业提供的 DevOps 工具链集成接口，实现 Jenkins、GitLab、Gitee、GitHub、Harbor 等工具的一键集成与互通。基于 Slim 4 框架，PHP 8+。

环境要求
框架: Slim 4

PHP 版本: 8.0+

PHP 扩展: php-cli, php-mbstring, php-xml, php-curl, php-zip

支持服务:

Jenkins v2.555.3+

GitLab v17.0

Gitee API v5

GitHub API v3（公有云 + 企业版）

Harbor v1.10.1 / v2.x

依赖管理: Composer

快速部署
1. 克隆仓库

git clone https://github.com/your-org/Devops-Glue.git
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
curl http://localhost:8080/api/health
浏览器打开 http://localhost:8080/api/docs 查看 API 文档。

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
日志写入 LOG_PATH 指定的目录（默认 /data/logs/ci-platform/），JSON 格式，按天滚动。

日志级别：production → info，其他环境 → debug。

零、健康检查
URL: /api/health

方法: GET

输出: JSON 对象

{
  "status": "ok",
  "checks": {
    "jenkins": true,
    "harbor": true
  },
  "app_env": "production",
  "time": "2026-07-05 12:00:00"
}

status: ok（正常）/ degraded（部分服务不可用）

HTTP 状态码: 200（ok）/ 503（degraded）

一、Main 模块 (/api/main)
1.1 获取所有 Job 列表
URL: /api/main/jobs/list

方法: GET / POST

输出: 字符串数组


["java/registry", "php/myapp", "static"]
1.2 Job / Git / Harbor 三方映射（按项目分组）
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
1.3 已接入的 Git 平台列表（静态配置）
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
1.4 平台接入检测（动态扫描）
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
二、Jenkins 模块 (/api/jenkins)
2.1 触发构建
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

2.2 查询构建参数
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

2.3 查询构建状态
URL: /api/jenkins/{group}/{project}/{build_id}/status

方法: GET / POST

输出: ["SUCCESS"], ["FAILURE"], ["ABORTED"], ["UNSTABLE"]

2.4 构建 ID 列表
URL: /api/jenkins/{group}/{project}/build_id

方法: GET / POST

输出: ["12","11","10"]

2.5 成功构建列表（带时间）
URL: /api/jenkins/{group}/{project}/build_time

方法: GET / POST

输出: ["#20 [2026-06-24 18:56:44]","#18 [2026-06-21 17:27:06]"]

2.6 成功构建列表（带 # 号）
URL: /api/jenkins/{group}/{project}/build

方法: GET / POST

输出: ["#14","#13","#12"]

2.7 控制台日志
URL: /api/jenkins/{group}/{project}/{build_id}/console

方法: GET / POST

输出: text/plain

2.8 Git 分支查询（Jenkins 路径）
URL: /api/jenkins/{group}/{project}/branches

方法: GET / POST

说明: 等同于 /api/git/{job}/branches

三、Git 模块 (/api/git)
3.1 查询 Job 对应的 Git 分支列表
URL: /api/git/{group}/{project}/branches

方法: GET / POST

输出: ["master","devops","main"]

说明: 支持 GitLab、Gitee、GitHub 三种平台，自动根据 Job 在映射配置中关联的 Git 仓库查询。

四、Harbor 模块 (/api/harbor)
4.1 获取项目列表
URL: /api/harbor/projects

方法: GET / POST

输出: ["library","mycode","toolkit"]

4.2 获取仓库列表
URL: /api/harbor/{project}/repositories

方法: GET / POST

输出: ["diagnosis-runtime","nginx"]

4.3 获取 Tag 列表
URL: /api/harbor/{project}/repositories/{repository}/tags

方法: GET / POST

注意: 仓库名如含 / 需双重编码（%2F）

输出: ["v1.0.0","v1.0.1"]

4.4 触发镜像扫描
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: POST

输出: {"status":"ok"} 或 {"code":503,"message":"镜像扫描功能未启用，请联系管理员"}

说明: 支持 Harbor v1（1.10.x）和 v2（2.x）两种 API 版本，自动检测。

4.5 获取扫描报告
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: GET

输出: 漏洞报告 JSON 数组，或空数组（无漏洞）

说明: Harbor v2 返回 application/vnd.security.vulnerability.report 格式的漏洞数据。

五、API 文档（Swagger UI）
URL: /api/docs

方法: GET

说明: 浏览器打开即可浏览全部接口，支持在线 Try it out 交互式调试。

六、错误处理
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

七、配置说明
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

# Harbor
HARBOR_BASE_URL=http://URL
HARBOR_USER=admin
HARBOR_PASSWORD=your_password

# App
APP_ENV=production
APP_DEBUG=false
BUILD_TIMEOUT=300
LOG_PATH=/data/logs/ci-platform/
手动映射配置 (config/settings.php)
php
'job_git_map' => [
    [
        'job_name'          => 'java/registry',
        'project_id'        => 2,
        'web_url'           => 'http://your-gitlab/group/project',
        'current_path'      => 'tools/registry',
        'harbor_repository' => 'mycode/code-runtime',
    ],
    // 支持任意自定义字段，自动输出到映射接口
],
CORS 配置 (config/settings.php)
php
'cors' => [
    'allowed_origins' => ['*'],   // 允许的域名，* 表示全部
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
],
八、快速测试命令
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

九、测试
测试脚本位于 test/ 目录：

bash
# 完整测试（全部接口 + 三种格式 + CORS + 健康检查）
php test/test_api_html.php

# 快速测试（核心接口）
php test/test_api_html_simp.php
生成 HTML 报告，可浏览器打开查看。

十、项目结构
├── config/                 # 服务端配置
│   ├── .env                # 环境变量（密钥，不入库）
│   ├── .env.example        # 环境变量模板
│   ├── settings.php        # 主配置 + 映射表
│   ├── AppConfig.php       # 配置访问器
│   ├── container.php       # DI 容器定义
│   └── routes.php          # 路由定义
├── public/                 # Web 根目录
│   ├── index.php           # 应用入口
│   └── .htaccess           # URL 重写
├── src/
│   ├── Controller/         # 控制器
│   ├── Service/            # 业务逻辑 + 外部 API 客户端
│   │   └── Git/            # Git 平台适配器（GitLab/Gitee/GitHub）
│   ├── Middleware/          # PSR-15 中间件（CORS）
│   └── Exceptions/         # 异常类
├── templates/              # 静态页面
│   ├── index.html          # 首页
│   ├── 404.html            # 404 页面
│   ├── swagger.html        # Swagger UI
│   └── openapi.json        # OpenAPI 3.0 规范
├── test/                   # 测试脚本
├── Dockerfile              # Docker 镜像
├── docker-compose.yml      # Docker 编排
└── .dockerignore           # Docker 排除文件

十一、更新日志
版本	日期	变更内容
v2.2	2026-07-05	新增 GitHub 平台完整接入；Harbor v2 镜像扫描；健康检查端点 /api/health；Swagger UI 文档 /api/docs；CORS 跨域支持；结构化文件日志；Docker 部署支持；ApiException 异常类
v2.1	2026-03-05	Slim 4 重构。新增 Main 模块（平台接入、多方映射）；触发构建支持单/双参数动态适配；输出格式切换（raw/json/xml）；Harbor 扫描集成（Trivy 离线）
v1.1	2021-11-01	增加 Harbor 查询功能
v1.0	2018-09-28	初始版本，Jenkins、Git 与 Rundeck 三方集成
十二、如有建议可在 GitHub 仓库提 issue ，或者EMAIL:jeanslw@qq.com
