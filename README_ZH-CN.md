# Devops-Glue API v2.4.0

> **不是大厂遥控器——是小团队的瑞士军刀。**

Devops-Glue 是基于 Slim4 为小团队打造的 DevOps 工具链集成平台。统一 API 管理 Jenkins + GitLab CI 双通道构建、GitLab / Gitee / GitHub / Gitea 多平台代码、以及 Harbor 镜像仓库——覆盖从 CI 到 CD 的完整流程。双语界面，角色权限分级。

![系统概览](system_info.png)
![运行状态](system_running.png)

## 功能特性

- **国际化** — 中/英文双语界面，`?lang=` 即时切换，基于 `symfony/translation`
- **双构建通道** — Jenkins + GitLab CI，随意切换或并存，统一 API
- **多平台 Git** — GitLab · GitHub · Gitee · Gitea，自托管或 SaaS 均可
- **全链路映射** — Job ↔ Git 仓库 ↔ Harbor 镜像，构建→代码→产物自动关联
- **安全扫描审计** — SAST、密钥扫描、依赖漏洞检测，通过 Commit Status 回写
- **角色权限分级** — `super_admin` > `admin` > `deployer` > `viewer`，四级 RBAC
- **管理面板** — 服务监控、映射配置、安全扫描、用户管理
- **零配置启动** — 默认 SQLite，一行配置切换 MySQL / MariaDB

## 快速开始

```bash
# 1. 克隆
git clone https://github.com/jeanslw/Devops-Glue.git
cd Devops-Glue

# 2. 安装依赖
composer install

# 3. 配置环境
cp config/.env.example config/.env
# 编辑 config/.env 填入实际凭证

# 4. 启动（PHP 内置服务器或 Docker）
php -S 0.0.0.0:8080 -t public/
# 或
cd docker-compose && docker compose up -d --build

# 5. 验证
curl http://localhost:8080/api/health
```

访问 `http://localhost:8080/admin` 进入管理面板（账号密码见 `.env`）。

访问 `http://localhost:8080/api/docs` 查看交互式 API 文档（Swagger UI）。

## 运行环境

| 组件 | 版本要求 |
|---|---|
| PHP | 8.0+ |
| 数据库 | SQLite（默认）/ MySQL 8.0+ / MariaDB 10.4+ |
| Jenkins | v2.60+ |
| GitLab | v9.0+（API v4） |
| Harbor | v1.10.1 / v2.x |

完整环境变量参考和 CORS 配置请见 [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md)。

## 文档

| 文档 | 语言 | 说明 |
|---|---|---|
| [docs/API.md](docs/API.md) | EN | API 参考 — 接口端点、请求/响应格式、快速测试 |
| [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) | EN | 管理员手册 — 环境变量、映射配置、自定义 Git 平台 |
| [docs/用户说明.md](docs/用户说明.md) | 中文 | 用户指南 — 最终用户操作说明 |
| [docs/技术文档.md](docs/技术文档.md) | 中文 | 技术文档 — 架构设计、数据库设计、数据流程、故障排查 |
| [docs/technical-guide.md](docs/technical-guide.md) | EN | Technical Guide — architecture, DB design, data flows, troubleshooting |
| [docs/常见问题.md](docs/常见问题.md) | 中文 | 常见问题 — 部署、配置、构建、鉴权、Harbor 等 |
| [docs/FAQ.md](docs/FAQ.md) | EN | FAQ — deployment, config, builds, auth, Harbor, etc. |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | — | 更新日志 |

## 相关项目

- **Devops-CD** — 持续部署系统（ArgoCD / FluxCD / Helm / Kubectl）：  
  https://github.com/jeanslw/Devops_CD

## 项目结构

```
config/         # 服务端配置（.env、DI 容器、路由、设置）
database/       # MySQL 和 SQLite 初始化脚本
docker-compose/ # Docker Compose（PHP + MySQL 8.4）
public/         # Web 根目录（index.php、静态资源）
src/            # 控制器与服务层
templates/      # HTML 模板（admin、swagger、openapi）
docs/           # 文档
```

## 许可证

MIT

## 联系方式

- 问题与 PR：[GitHub Issues](https://github.com/jeanslw/Devops-Glue/issues)
- 邮箱：jeanslw@qq.com
