CI Platform API 文档 v2.1
概述
CI Platform 是一套为小企业提供的 DevOps 工具链集成接口，实现 Jenkins、GitLab、Gitee、GitHub、Harbor 等工具的一键集成与互通。基于 Slim 4 框架，PHP 8+。

环境要求
框架: Slim 4

PHP 版本: 8.0+

PHP 扩展: php-cli, php-mbstring, php-xml, php-curl, php-zip

支持服务:

Jenkins v2.555.3+

GitLab v17.0

Gitee API v5

Harbor v1.10.1 / 2.x

依赖管理: Composer

快速部署
1. 克隆仓库
bash
git clone https://github.com/your-name/ci-platform.git
cd ci-platform
2. 安装依赖
bash
composer install
3. 配置环境变量
复制配置文件模板并修改：

bash
cp .env.example .env
编辑 .env 文件，填入你的服务地址和凭证：

ini
JENKINS_BASE_URL=http://192.168.137.5:8083
JENKINS_USER=admin
JENKINS_TOKEN=your_jenkins_token

GITLAB_BASE_URL=http://192.168.137.5:8082
GITLAB_TOKEN=your_gitlab_token

GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_gitee_token

HARBOR_BASE_URL=http://192.168.137.5
HARBOR_USER=admin
HARBOR_PASSWORD=your_harbor_password
4. 配置 Web 服务器
将 public/ 目录设置为 Web 根目录，并配置 URL 重写。

Nginx 配置示例：

nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/ci-platform/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
Apache 配置： public/.htaccess 已包含 URL 重写规则。

5. 验证部署
bash
curl http://your-domain.com/api/main/jobs/list
公共约定
所有接口均支持 GET 和 POST 方法，除特殊说明外（build_trigger 仅 POST，扫描触发仅 POST）

大部分接口返回字符型数组（如 ["item1","item2"]）

映射类接口（/api/main/map/list、/api/main/git/platforms、/api/main/git/discovery）返回 JSON 对象

构建触发接口（/api/jenkins/.../build_trigger）返回 JSON 对象

控制台日志（/api/jenkins/.../console）返回 text/plain

错误格式: 统一为 {"code": HTTP状态码, "message": "错误描述"}

一、Main 模块 (/api/main)
1.1 获取所有 Job 列表
URL: /api/main/jobs/list

方法: GET / POST

说明: 包括文件夹下的 Job 和独立 Job，如 java/myapp、static

输出: 字符串数组

json
["java/registry", "php/myapp", "static"]
1.2 Job / Git / Harbor 三方映射（按项目分组）
URL: /api/main/map/list

方法: GET / POST

说明: 以 Git 仓库路径为键，展示该仓库下所有关联的 Job、Git 平台信息、Harbor 仓库等

输出: JSON 对象

json
{
  "tools/registry": {
    "git_platform": "gitlab",
    "git_remote": "http://192.168.137.5:8082/tools/registry.git",
    "project_id": 2,
    "web_url": "http://urs/tools/registry",
    "current_path": "tools/registry",
    "harbor_repository": "mycode/code-runtime",
    "jobs": ["java/registry"]
  },
  "tools/myapp": {
    "git_platform": "gitlab",
    "git_remote": "http://192.168.137.5:8082/tools/myapp.git",
    "project_id": 5,
    "web_url": "http://urs/tools/myapp",
    "current_path": "tools/myapp",
    "harbor_repository": "mycode/myapp",
    "jobs": ["php/myapp"]
  }
}
字段说明:

project_id: Git 平台项目 ID（GitLab 可自动获取并缓存，Gitee 需手动填写）

jobs: 使用该仓库的所有 Jenkins Job 名称列表

支持手动配置中任意新增字段，自动出现在结果中

1.3 已接入的 Git 平台列表（静态配置）
URL: /api/main/git/platforms

方法: GET / POST

说明: 返回在 settings.php 中已配置的 Git 平台及 Harbor 的 API 版本和地址

输出: JSON 对象

json
{
  "git_platforms": [
    {
      "name": "gitlab",
      "api_base_url": "http://192.168.137.5:8082/api/v4",
      "api_version": "v4"
    },
    {
      "name": "gitee",
      "api_base_url": "https://gitee.com/api/v5",
      "api_version": "v5"
    }
  ],
  "harbor": {
    "api_base_url": "http://192.168.137.5/api/v2.0",
    "api_version": "v2.0"
  }
}
1.4 平台接入检测（动态扫描）
URL: /api/main/git/discovery

方法: GET / POST

说明: 扫描所有 Job 实际使用的 Git 平台，与静态配置对比，展示已配置和未配置的平台列表

输出: JSON 对象

json
{
  "configured": [
    {"name": "gitlab", "api_base_url": "http://192.168.137.5:8082/api/v4"},
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

说明: 根据 Job 实际参数个数自动匹配路由。参数名动态识别，校验失败返回具体值不合法，成功返回队列 URL

输出: JSON 对象

json
{
  "code": 200,
  "message": "构建触发成功",
  "job": "php/myapp",
  "parameters": {"branches": "main", "zone": "test"},
  "queue_id": "114",
  "queue_url": "http://192.168.137.5:8083/queue/item/114/"
}
校验规则:

Job 必须有恰好 1 或 2 个参数，否则拒绝

参数值必须在对应参数选项中（若选项为空则从 Git 平台实时获取）

错误信息仅提示值不合法，不泄露完整可用列表

2.2 查询构建参数
URL: /api/jenkins/{group}/{project}/parameters 或 /api/jenkins/{group}/{project}/{build_id}/parameters

方法: GET / POST

说明:

无 build_id: 返回所有参数及其选项

build_id=0: 返回最新构建的参数名列表

build_id>0: 返回指定历史构建的参数名列表

输出:

默认: {"zone":["prd","test"],"branches":["main","master"]}

build_id=0: ["zone","branches"]

build_id>0: ["zone","branches"]

2.3 查询构建状态
URL: /api/jenkins/{group}/{project}/{build_id}/status

方法: GET / POST

输出: ["SUCCESS"], ["FAILURE"], ["ABORTED"], ["UNSTABLE"] 等

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

输出: text/plain 文本流

2.8 Git 分支查询（Jenkins 路径）
URL: /api/jenkins/{group}/{project}/branches

方法: GET / POST

说明: 等同于 /api/git/{job}/branches

三、Git 模块 (/api/git)
3.1 查询 Job 对应的 Git 分支列表
URL: /api/git/{group}/{project}/branches

方法: GET / POST

说明: 自动识别 Git 平台，调用对应 API 获取实时分支列表

输出: ["master","devops","main"]

四、Harbor 模块 (/api/harbor)
4.1 获取项目列表
URL: /api/harbor/projects

方法: GET / POST

输出: ["library","mycode","toolkit"]

4.2 获取仓库列表
URL: /api/harbor/{project}/repositories

方法: GET / POST

注意: 项目名如含 / 需 URL 编码

输出: ["diagnosis-runtime","nginx"]

4.3 获取 Tag 列表
URL: /api/harbor/{project}/repositories/{repository}/tags

方法: GET / POST

注意: 仓库名如含 / 需双重编码（%2F）

输出: ["v1.0.0","v1.0.1"]

4.4 触发镜像扫描
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: POST

说明: 若 Harbor 未启用扫描器，返回 503 {"code":503,"message":"镜像扫描功能未启用，请联系管理员"}

4.5 获取扫描报告
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: GET

说明: 同扫描触发，未启用时返回 503

五、设计原则
动态参数识别
Jenkins Job 的参数名由系统自动从定义中识别，不硬编码 zone/branches。无论你在 Jenkins 中如何命名参数，系统都能自动适配。

严格门禁
构建触发必须提供与 Job 参数个数完全匹配的参数值，且值必须存在于参数选项中。错误提示仅提示具体值不合法，不泄露完整可用列表。

数据一致性
参数选项以 Jenkins 定义为权威源；Git Parameter 等动态参数取不到选项时，从对应 Git 平台实时补齐。

平台接入控制
只有在 settings.php 中配置了 base_url 和 token 的平台才被视为"已接入"，接口才能获取该平台的 Git 信息。

字段动态扩展
在 settings.php 的 job_git_map 中添加任意字段，映射接口将自动输出，无需修改代码。

六、错误处理
所有错误响应统一为以下格式：

json
{
  "code": 400,
  "message": "错误描述信息"
}
常见错误码
状态码	说明
200	请求成功
400	参数错误（参数值不合法、参数个数不匹配等）
404	资源不存在（Job 未找到、API 路由不存在）
500	服务端错误（Jenkins/Harbor 连接失败等）
503	服务不可用（Harbor 扫描器未启用等）
七、项目结构
text
ci-platform/
├── .env                    # 环境变量配置（不纳入版本控制）
├── .env.example            # 配置模板
├── composer.json           # PHP 依赖管理
├── config/
│   ├── container.php       # PHP-DI 容器定义
│   ├── routes.php          # 路由注册
│   └── settings.php        # 业务配置（含 job_git_map）
├── public/
│   ├── index.php           # 入口文件
│   └── .htaccess           # Apache URL 重写
├── src/
│   ├── Config/
│   │   └── AppConfig.php   # 全局配置访问层
│   ├── Controller/
│   │   ├── GitController.php
│   │   ├── HarborController.php
│   │   ├── JenkinsController.php
│   │   └── MainController.php
│   └── Service/
│       ├── GitService.php
│       ├── HarborService.php
│       ├── JenkinsService.php
│       ├── MapService.php  # Job/Git/Harbor 映射与缓存
│       └── Git/
│           ├── GiteeService.php
│           ├── GitlabService.php
│           ├── GitProviderFactory.php
│           └── GitProviderInterface.php
└── templates/
    ├── 404.html
    └── index.html
八、手动映射配置
在 settings.php 的 job_git_map 数组中，可为特定 Job 配置详细信息：

php
'job_git_map' => [
    [
        'job_name'          => 'java/registry',
        'project_id'        => 2,
        'web_url'           => 'http://urs/tools/registry',
        'current_path'      => 'tools/registry',
        'harbor_repository' => 'mycode/code-runtime',
    ],
    [
        'job_name'          => 'static',
        'project_id'        => null,
        'web_url'           => 'https://gitee.com/projects/git_onee_app',
        'current_path'      => 'projects/git_one_app',
        'harbor_repository' => 'mycode/static-app',
    ],
],
支持任意自定义字段（如 group_id、owner、namespace），配置后自动出现在映射接口返回值中，无需修改代码。

九、快速测试命令（curl）
bash
# 触发构建（单参数）
curl -X POST "http://your-domain.com/api/jenkins/static/master/build_trigger"

# 触发构建（双参数）
curl -X POST "http://your-domain.com/api/jenkins/php/myapp/main/test/build_trigger"

# 查询构建状态
curl "http://your-domain.com/api/jenkins/my-app/128/status"

# 查询 Git 分支
curl "http://your-domain.com/api/git/my-app/branches"

# 查询三方映射
curl "http://your-domain.com/api/main/map/list"

# 查询已接入的 Git 平台
curl "http://your-domain.com/api/main/git/platforms"

# 查询平台接入检测
curl "http://your-domain.com/api/main/git/discovery"

# 查询 Job 参数
curl "http://your-domain.com/api/jenkins/java/registry/parameters"

# Harbor 项目列表
curl "http://your-domain.com/api/harbor/projects"

# Harbor 仓库列表
curl "http://your-domain.com/api/harbor/mycode/repositories"

# Harbor Tag 列表
curl "http://your-domain.com/api/harbor/mycode/repositories/diagnosis-runtime/tags"

# 触发 Harbor 扫描
curl -X POST "http://your-domain.com/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"

# 获取 Harbor 扫描报告
curl "http://your-domain.com/api/harbor/mycode/repositories/diagnosis-runtime/tags/v1.0.0/scan"
十、更新日志
版本	日期	变更内容
v2.1	2026-07-01	Slim 4 重构的多平台版本。新增 Main 模块：多平台接入查询（git/platforms、git/discovery）、三方映射按项目分组（map/list）；Jenkins 触发构建支持单/双参数动态适配，参数名完全动态识别；Git 项目 ID 字段统一为 project_id；Harbor 扫描接口错误友好化（503）；新增 AppConfig 全局配置层，支持映射字段动态扩展
v1.1	2021-11-01	增加 Harbor 查询功能（项目/仓库/Tag），主要应用于 Webhook 和 Rundeck 的表单集成
v1.0	2018-09-28	初始版本，实现 Jenkins、Git 与 Rundeck 的三方集成 与 Webhook 触发功能
十一、联系方式
如有问题或建议，欢迎提交 Issue 或 Pull Request。

如需私有化部署、定制开发或技术咨询，请联系：jeanslw@126.com