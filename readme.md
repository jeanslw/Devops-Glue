# 说明文档 v1

本程序仅为小企业提供工具链的集成接口，实现工具互通，一键集成服务。
**支持**: jenkins / gitlab / gitee / github / rundeck

---

## 环境信息

- **框架**: slim 4 
- **依赖服务**: php v8+
- **扩展包**: php-cli / php-mbstring / php-xml / php-curl / php-zip / php8.1-mysql
- **支持服务依赖**: harbor version: v1.10.1 / gitlab version: v17.0 / jenkins version: v2.555.3
- **管理工具**: Composer，harbor v1.10 and 2.x ，gitlab v17版，giteeApi v5

---

## 目录结构

```text
|
|   .env
|   .gitignore
|   composer.json
|   readme.md
|
+---config
|       container.php
|       routes.php
|       settings.php
|
+---Exceptions
|       ApiException.php
|
+---public
|       .htaccess
|       debug.log
|       index.php
|
+---src
|   +---Controller
|   |       BaseController.php
|   |       GitController.php
|   |       HarborController.php
|   |       JenkinsController.php
|   |
|   \---Service
|       |   GitService.php
|       |   HarborService.php
|       |   JenkinsService.php
|       |
|       \---Git
|               GiteeService.php
|               GithubService.php
|               GitlabService.php
|               GitProviderFactory.php
|               GitProviderInterface.php
|
+---templates
|       404.html
|       index.html
|     
\---vendor
```

---

## 配置文件

`config/settings.php` 和 `.env`

---

# 一、 说明 
## **输出格式**：严格要求输出为**字符型数组格式** (第二节的8、9、10条例外，返回 json 和 text/html 格式)！
## **路由说明**：`config/routes.php` 重要说明：jenkins 启用文件夹，最多支持两级 `{group}/{project}` 和一级 `/{project}`。
## 1.兼容一级和多级项目的各种请求，要分两种情况：
## 		**A**: 输入接口2级或者1级（兼容1级和2级）
## 		**B**: 输入接口统一为1级（统一为1级，只接受JOB名称）
##
##
## 2.含【重要】的接口为基础，其他接口依赖与它，依赖关系：
##  
##  所有JOB列表接口：/api/main/jobs/list
##		返回结果：["myapp","php/web", "static"]
##		说明：依赖它的接口有：build_trigger
##
##  构建列表接口：/api/jenkins/{group}/{project}/{build_id}/parameters
##		返回结果：{build_id}=null：默认：{"zone":["b1","b2","test1","test2","dev"],"branches":["main","master"]},{build_id}>0:返回历史ID构建列表，{build_id}=0：最新的配置参数列表
##  	说明：依赖它的接口有：build_trigger
##
##	JOB和GIT对应关系：/api/main/map/list
##   	返回结果 [{"job_name":"java/registry","gitlab_id":2,"status":"synced","message":"","debug":{"step1_try_jobname":"java/registry","step1_result":"SUCCESS"},"web_url":"http://urs/tools/registry","current_path":"tools/registry","habbor_repository":"mycode/code-runtime"}]
##  	说明：依赖它的接口 ：build_trigger，目前用来JOB地址反查仓库的projectId（防止Git仓库改名、名称不一致、gruopID对不上的问题），从而为GIT查询分支列表提供服务。以后用于多GIT平台的和项目的映射关系
##
##  /api/jenkins/{branches}/{zone}/build_trigger，
##  	说明：如/api/jenkins/master/prod/build_trigger,触发构建需要用到校验jobName(job名称)、参数配置列表、参数配置值
##  	jobName--依赖于-->["myapp","php/web", "static"]，判断joName是否合法。
##       zone-->,{"zone":["b1","b2","test1","test2","dev"]}，判断zone是否合法。
##       brancher->{"branches":["main","master"]}，判断brancher是否合法
##
## 3.关于输入的多级和一级的判断
##
##  **多级**：
##  超过两级取最后一段，如：`AA/BB/CC`，job取 `CC`，folder取：`AA/BB`。
##  例如输入：`folder/BB`，在 jenkins 中是否存在，type 是否 = folder，job 是否 = job (无论 pipeline，Multibranch)
##  - `folder = folder`，`BB ≠ job` 返回：job 错误
##  - `folder ≠ folder`，`BB = job`，返回：folder 错误
## - `folder = folder`，`BB = job`，继续...
##  **一级**：
##  如请求参数：`folder`，查看是否为 job 类型，true，则继续；false，则提示非 job 类型。


# 二、 `/api/main` 模块接口列表（POST/GET）


##  1. **查询所有 Job 列表**： `GET/POST /api/main/jobs/list` 
   - **说明**：包括 jenkins 文件夹下的 job 和独立的 job, 如 `java/myapp`，`static`。
   - **输出格式**：`["myapp", "static"]`

##  2. **查询所有的 job 对应的git仓库对应关系**： `GET/POST /api/main/map/list` 【重要】
   - **说明**：如 Job 与 Git 仓库、harbor 的对应关系。
   - **输出格式**（JSON 格式）：[{"job_name":"java/registry","gitlab_id":2,"status":"synced","message":"","debug":{"step1_try_jobname":"java/registry","step1_result":"SUCCESS"},"web_url":"http://urs/tools/registry","current_path":"tools/registry","habbor_repository":"mycode/code-runtime"}]
 
# 三、 `/api/jenkins` 模块接口列表（POST/GET )

##  3. **查询成功构建状态**： `GET/POST /api/jenkins/{group}/{project}/{build_id}/status` 
   - **重要说明**：如参数 `{project}/1/status`。
   - **输出格式**：`["SUCCESS"]`、`["FAILURE"]`、`["ABORTED"]`、`["UNSTABLE"]` 等状态。

##  4. **查询成功构建列表 (ID)**： `GET/POST /api/jenkins/{group}/{project}/build_id` 
   - **重要说明**：如参数 `java/myapp` (注意 `/`)。
   - **输出格式 (ID)**：`["12","11","10"]`

##  5. **查询带 '时间日期' 的成功构建列表 (time)**： `GET/POST /api/jenkins/{group}/{project}/build_time` 
   - **重要说明**：如参数 `java/myapp`。
   - **输出格式 (带 '时间日期' 的列表)**：`["#20 [2026-06-24 18:56:44]","#18 [2026-06-21 17:27:06]"]`

##  6. **查询带 '#' 的构建成功构建列表**： `GET/POST /api/jenkins/{group}/{project}/build` 
   - **重要说明**：如参数 `java/myapp`。
   - **输出格式 (带 '#' 号的列表)**：`["#14","#13","#12"]`（与第4/5条调用同种方法）

##  7. **查询 job 项目构建参数列表**： `GET/POST /api/jenkins/{group}/{project}/{build_id}/parameters` 【重要】
   - `{build_id}`：可选参数
   - **说明**：
     - 默认（例如参数 `java/myapp`）：输出格式 `{"zone":["b1","b2","test1","test2","dev"],"branches":["main","master"]}`
     - 如果 `{build_id}=0`，请求样式：`/api/jenkins/{group}/{project}/0/parameters`。获取 job 最新的构建参数列表，输出格式：`["zone","brancher"]`
     - 如果 `{build_id}>0`：`/api/jenkins/{group}/{project}/{build_id}/parameters`。获取 job 构建历史参数列表，参数可能改变，输出格式：`["branch","zone"]`

##  8. **控制台构建日志获取**： `GET/POST /api/jenkins/{group}/{project}/{build_id}/console` 
   - **重要说明**：如参数 `myapp`，`build_id: 11`。
   - **输出格式**：`text/html` (逐行显示，与 console output 一致)

## 四	`/api/git` 模块接口列表（支持 GET 和 POST)

1.**查询 job 所对应的分支列表**： `GET/POST /api/jenkins/{group}/{project}/branches` 【重要】 
   - **重要说明**：如参数 `java/myapp`。首先向 jenkins 获取 job 地址 [兼容 ssh、http]，然后向 gitlab api 查询所在的分支列表。
   - **输出格式**：`["master","devops","test"]`

## 五、 `/api/harbor` 模块接口列表（支持 GET 和 POST)

##  Harbor 功能模块列表:

## 1. **获取项目列表**： `GET/POST /api/harbor/projects`
   - **重要说明**: 输出格式 `["library","mycode","toolkit"]`
## 2. **获取仓库列表**： `GET/POST /api/harbor/{projects}/{repositories}`
   - **重要说明**: 输出格式 `["library","mycode","toolkit"]`
## 3. **获取 Tags 列表**： `GET/POST /api/harbor/{projects}/{repositories}/tags` 
   - **重要说明**: 输出格式 `["v1.10.1","v1.10.0","v1.9.0"]`

---

## 六、快速测试命令示例（curl）

```bash
# 触发构建
# 如：project:myapp ，branches=master, zone=prod
curl -X POST "{BASE_URL}/api/jenkins/my-app/master/prod/build_trigger" 
# 说明: "php/my-app" 是 php 文件下的 my-app
# result: { "code": 200, "message": "构建触发成功", "job": "registry", "triggered_params": { "branches": "main", "zone": "dev", "jobname": "java/registry" } }

# 查询[build_id]的构建状态
# 如：project:myapp
curl "{BASE_URL}/api/jenkins/my-app/128/status"
# result: ["SUCCESS"]

# 查询job所对应git项目的分支列表：/api/git/{group}/{project}/branches
curl "{BASE_URL}/api/git/my-app/branches"
# result: ["master","devops","main"]

# 查询 git Repositories 、 job 、镜像repositories 对应关系
curl "{BASE_URL}/api/main/map/list"
# result: [{"job_name":"java/registry","gitlab_id":2,"status":"synced","message":"","debug":{"step1_try_jobname":"java/registry","step1_result":"SUCCESS"},"web_url":"http://urs/tools/registry","current_path":"tools/registry","habbor_repository":"mycode/code-runtime"}]

#查询 job 项目构建参数列表**： `GET/POST /api/jenkins/{group}/{project}/{build_id}/parameters`

# 查询job项目构建参数列表 /api/jenkins/{group}/{project}/{build_id}/parameters
	# 如: myfolder/myapp, [{build_id} 可选参数
curl "{BASE_URL}/api/jenkins/myfolder/myapp/parameters"
# result：{"zone":["b1","b2","test1","test2","dev"],"branches":["main","master"]}（默认）

	# 如果 {build_id}=0 ，请求样式：/api/jenkins/{group}/{project}/0/parameters
	# 获取 job 最新的构建参数列表，输出格式：["zone","brancher"]

	# 如果 {build_id}>0 : /api/jenkins/{group}/{project}/1/parameters 
	# 获取 job 构建历史参数列表，参数可能改变，输出格式：["branch","zone"]

# 获取 Harbor Tag 列表 /api/harbor/{projects}/{repositories}/tags
curl "{BASE_URL}/api/harbor/{projects}/{repositories}/tags" 
# result：["v1.0.0","v1.0.1"] 
# 说明：'aa/bb' 是'项目/仓库'名称。仓库可能包含'/' (注意 URL 编码)