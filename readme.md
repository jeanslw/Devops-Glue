Devops-Glue API 文档 v2.1
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

Harbor v1.10.1 / 2.x

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

公共约定
HTTP 方法
所有接口均支持 GET 和 POST 方法（除 build_trigger 仅 POST，扫描触发仅 POST）

输出格式
大部分接口返回字符型数组（如 ["item1","item2"]）

映射/平台接口（/api/main/map/list、/api/main/git/platforms、/api/main/git/discovery）返回 JSON 对象

构建触发（/api/jenkins/.../build_trigger）返回 JSON 对象

控制台日志（/api/jenkins/.../console）返回 text/plain

格式切换
所有接口支持通过 ?format= 参数切换输出格式：

参数	输出示例		Content-Type
不带参数（默认 raw）	["java/registry","static"]
?format=json			{"data":["java/registry","static"]}
?format=xml				<?xml...><root><item>java/registry</item></root><application/xml

统一格式：
	{
	  "code": 400,
	  "message": "错误描述"
	}
	
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
    "web_url": "http://urs/tools/registry",
    "current_path": "tools/registry",
    "harbor_repository": "mycode/code-runtime",
    "jobs": ["java/registry"]
  },
  "tools/myapp": {
    "git_platform": "gitlab",
    "git_remote": "http://URL/tools/myapp.git",
    "project_id": 5,
    "web_url": "http://urs/tools/myapp",
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

4.5 获取扫描报告
URL: /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan

方法: GET

输出: 漏洞报告 JSON 数组，或空数组（无漏洞）

五、错误处理
所有错误响应统一格式：

json
{
  "code": 400,
  "message": "错误描述"
}
状态码	说明
200	成功
400	参数错误（值不合法、个数不匹配等）
404	资源不存在（Job 未找到、路由不存在）
500	服务端错误（Jenkins/Harbor 连接失败等）
503	服务不可用（Harbor 扫描器未启用等）

六、配置说明
环境变量 (.env)
ini
# Jenkins
JENKINS_BASE_URL=http://URL
JENKINS_USER=admin
JENKINS_TOKEN=your_token

# GitLab
GITLAB_BASE_URL=http://URL
GITLAB_TOKEN=your_token

# Gitee
GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_token

# Harbor
HARBOR_BASE_URL=http://URL
HARBOR_USER=admin
HARBOR_PASSWORD=your_password
手动映射配置 (config/settings.php)
php
'job_git_map' => [
    [
        'job_name'          => 'java/registry',
        'project_id'        => 2,
        'web_url'           => 'http://urs/tools/registry',
        'current_path'      => 'tools/registry',
        'harbor_repository' => 'mycode/code-runtime',
    ],
    // 支持任意自定义字段，自动输出到映射接口
],

七、快速测试命令
bash
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

八、更新日志
版本	日期	变更内容
v2.1	2026-03-05	Slim 4 重构。新增 Main 模块（平台接入、多方映射）；触发构建支持单/双参数动态适配；输出格式切换（raw/json/xml）；Harbor 扫描集成（Trivy 离线）；
v1.1	2021-11-01	增加 Harbor 查询功能
v1.0	2018-09-28	初始版本，Jenkins、Git 与 Rundeck 三方集成
九、如有建议可发issue，与我联系 mailto:jeanslw@qq.com
