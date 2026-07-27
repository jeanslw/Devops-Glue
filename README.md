# Devops-Glue API v2.4.0

> **不是大厂的遥控器，是小团队的瑞士军刀。**

Devops-Glue 是一套为小企业打造的 DevOps 工具链集成 API，基于 Slim4 框架实现。一套接口，统一管理 Jenkins + GitLab CI 双通道、GitLab / Gitee / GitHub / Gitea 多平台、Harbor 镜像仓库，从 CI 构建到 CD 部署全流程覆盖。支持中英双语界面，角色权限分级管理。

![系统概览](system_info.png)
![运行状态](system_running.png)

## 核心特性

- **国际化 (i18n)** — 中文 / English 双语界面，基于 `symfony/translation` 实现，`?lang=` 即时切换
- **双链路构建** — Jenkins + GitLab CI 无缝切换或共存，统一 API 入口
- **多平台统一接入** — GitLab · GitHub · Gitee · Gitea，自建与 SaaS 无差别对接
- **全链路映射** — Pipeline/Job ↔ Git 仓库 ↔ Harbor 镜像，构建→代码→制品自动关联
- **安全扫描审计** — SAST、密钥扫描、依赖漏洞等结果以 Commit Status 回写 Git 平台，可追溯可审计
- **角色权限管理** — `super_admin` > `admin` > `deployer` > `viewer` 四级权限，根管理员不可删除
- **可视化管理后台** — 服务监测、映射配置、安全扫描、用户管理，表单操作零配置上手
- **零配置启动** — SQLite 默认零依赖，MySQL / MariaDB 一键切换

## 快速开始

```bash
# 1. Clone
git clone https://github.com/jeanslw/Devops-Glue.git
cd Devops-Glue

# 2. Install dependencies
composer install

# 3. Configure environment
cp config/.env.example config/.env
# Edit config/.env with your actual credentials

# 4. Start (PHP built-in server or Docker)
php -S 0.0.0.0:8080 -t public/
# OR
cd docker-compose && docker compose up -d --build

# 5. Verify
curl http://localhost:8080/api/health
```

Open `http://localhost:8080/admin` for the admin panel (credentials from `.env`).

Open `http://localhost:8080/api/docs` for interactive API documentation (Swagger UI).

## 环境要求

| Requirement | Version |
|---|---|
| PHP | 8.0+ |
| Database | SQLite (default) / MySQL 8.0+ / MariaDB 10.4+ |
| Jenkins | v2.60+ |
| GitLab | v9.0+ (API v4) |
| Harbor | v1.10.1 / v2.x |

See [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) for full environment variable reference and CORS configuration.

## 文档索引

| Document | Description |
|---|---|
| [docs/API.md](docs/API.md) | **API Reference** — endpoints, request/response formats, quick test commands |
| [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) | **Admin Manual** — environment variables, mapping config, custom Git platform integration |
| [docs/technical-guide.md](docs/technical-guide.md) | **Technical Guide** — architecture, request lifecycle, database design, data flows, troubleshooting |
| [docs/用户说明.md](docs/用户说明.md) | **User Guide** — end-user instructions (Chinese) |
| [CHANGELOG.md](CHANGELOG.md) | Release notes and version history |

## Related Projects

- **Devops-CD** — Continuous Deployment system (ArgoCD / FluxCD / Helm / Kubectl):  
  https://github.com/jeanslw/Devops_CD

## Project Structure

```
config/         # Server-side configuration (.env, DI container, routes, settings)
database/       # MySQL & SQLite initialization scripts
docker-compose/ # Docker Compose (PHP + MySQL 8.4)
public/         # Web root (index.php, static assets)
src/            # Controllers & Services
templates/      # HTML templates (admin, swagger, openapi)
docs/           # Documentation
```

## License

MIT

## Contact

- Issues & PRs: [GitHub Issues](https://github.com/jeanslw/Devops-Glue/issues)
- Email: jeanslw@qq.com
