# Changelog

## v2.4.2 (2026-07-31)
- **权限管理重构（数据驱动 RBAC）** — 权限 key、父级层级、隐含关系全部存 DB，后台 UI 可直接注册/删除。
- **RBAC 角色/权限编辑 UI** — 角色编辑支持可视化权限分组。
- **安全加固** — 权限注册 API、隐含规则 API 增加统一权限校验；
- **首页 UI 调整** — 增加管理界面的系统监测
- **错误提示完善** — OpenAPI 文档补全 5 个 RBAC 接口
- **补全历史文档** — README（中/英）、技术文档（中/英）、FAQ（中/英）、API.md


## v2.4.0 (2026-07-28)
- **国际化 (i18n)** — 新增 `symfony/translation` 依赖，支持中文/英文双语界面
- **超级管理员** — 引入 `super_admin` 角色，数据库存储，权限优于普通 `admin`
- **用户权限分级** —  4级RBAC权限，同级管理账户不可互相修改、删除。
- **根管理员保护** — 内置 root 账号不可删除、不可修改
- **管理后台增强** — 用户列表角色区分显示，顶部栏显示当前登录用户

## v2.3.2 (2026-07-25)
- Multi-environment config support (`APP_ENV` with `.env.{ENV}` overrides)
- Build mode runtime switching with backend validation
- Security scan commit status write-back + `ci_security_checks` audit table
- Bug fixes: security scan icons, `ci_pipeline_tags` COUNT query, Git pagination

## v2.3.1 (2026-07-15)
- Tag validation and storage rules
- MySQL / SQLite dual driver; `DB_DRIVER` required
- Docker optimized with MySQL 8.4 integration

## v2.3.0 (2026-07-10)
- GitLab CI dual-channel + Build unified module
- SQLite persistence + Admin web UI

## v2.2.0 (2026-05-06)
- ProviderRegistry pattern for Git platforms
- Custom platform extension support
- Gitea adapter added

## v2.1.2 (2026-05-04)
- Homepage health check dashboard
- GitHub platform integration
- `/api/health` endpoint
- Swagger UI + structured file logging
- Docker deployment support

## v2.1.1 (2026-03-05)
- Slim4 refactor
- Main module (platform integration, multi-party mapping)
- Output format switching (raw/json/xml)
- Harbor scan integration and report retrieval

## v1.1.0 (2021-11-01)
- Harbor query features added

## v1.0.0 (2018-09-28)
- Initial release: Jenkins, Git, and Rundeck integration
