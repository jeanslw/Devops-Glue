# Devops-Glue API v2.4
Devops-Glue API is a DevOps toolchain integration platform built on Slim4 for small teams. A unified API to manage Jenkins + GitLab CI dual-channel builds, GitLab / Gitee / GitHub / Gitea multi-platform code, and Harbor image registry — covering the full CI-to-CD workflow. Bilingual interface, data-driven role-based access control (RBAC).

<p align="center">
  <strong>🔧 Lightweight CI Management Service | Swiss Army Knife for Small Teams</strong><br/>
  Built with PHP + Slim4 — A CI & DevOps data aggregation platform designed for small teams
</p>

<p align="center">
  <a href="https://github.com/jeanslw/Devops_CD"><img src="https://img.shields.io/badge/Paired_CD-Devops__CD-green?logo=python" alt="CD"></a>
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Slim-4-00A650?logo=slim&logoColor=white" alt="Slim">
  <img src="https://img.shields.io/github/license/jeanslw/Devops-Glue" alt="License">
</p>

> 🔗 **This is the CI component** | Full system requires the CD deployment service → [Devops_CD](https://github.com/jeanslw/Devops_CD)


## 🆚 Why Devops-Glue + CD?

| Dimension | Devops-Glue + CD | Jenkins | GitLab CI | GitHub Actions |
|-----------|------------------|---------|-----------|----------------|
| **China Ecosystem** | ✅ Native Gitee/DingTalk/Feishu | ❌ Plugin-dependent | ⚠️ Slow international access | ⚠️ Unstable in China |
| **Real-time Feedback** | ✅ SSE + Web Shell | ⚠️ Polling/Blue Ocean | ⚠️ Basic log streaming | ✅ Native support |
| **PHP Team Onboarding** | ✅ Zero learning curve | ❌ Steep Groovy curve | ⚠️ Moderate YAML | ⚠️ Moderate YAML |
| **Deployment Methods** | SSH/Docker/K8s full coverage | Plugin-dependent | Runner-based | Runner-based |
| **Permission Model** | ✅ Data-driven RBAC | ⚠️ Complex matrix perms | ✅ Inherits Git perms | ✅ Inherits repo perms |
| **Time to First Deploy** | 30 min setup & running | Days of tuning | Hours | Hours |

> 💡 **Core Philosophy**: Not an enterprise remote control — a Swiss Army knife for small teams. Sufficient, usable, affordable.

> **[Chinese](README_ZH-CN.md)**

![System Overview](system_info.png)
![System Status](system_running.png)

## Features

- **i18n** — Chinese / English bilingual interface, `?lang=` instant switching via `symfony/translation`
- **Dual Build Pipeline** — Jenkins + GitLab CI, switch or coexist, unified API
- **Multi-Platform Git** — GitLab · GitHub · Gitee · Gitea, self-hosted or SaaS
- **Full-Chain Mapping** — Job ↔ Git repo ↔ Harbor image, build→code→artifact auto-association
- **Security Scan Audit** — SAST, secret scanning, dependency vulns written back via Commit Status
- **Role-Based Access (Data-Driven RBAC)** — `super_admin` > `admin` > `deployer` > `viewer`. Permission keys and implied rules are stored in DB and fully managed via the admin UI; no code change required when new menus/modules are added.
- **Admin Dashboard** — service monitoring, mapping config, security scan, user management
- **Zero-Config Startup** — SQLite by default, MySQL / MariaDB with one-click switch

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      CODE PUSH                              │
│  GitLab / Gitee / GitHub / Gitea  →  Webhook Trigger        │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                     CI LAYER: Devops-Glue API (PHP)         │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐   ┌──────────────┐      │
│  │   Jenkins    │  │  GitLab CI   │   │   Custom CI  │      │
│  │ BuildProvider│  │ BuildProvider│   │ BuildProvider│      │
│  └──────┬───────┘  └──────┬───────┘   └──────┬───────┘      │
│         └─────────────────┼──────────────────┘              │
│                           ↓                                 │
│              Build → Docker Image → Harbor Registry         │
│                           ↓                                 │
│              scan-sync → ci_pipeline_tags                   │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                     CD LAYER: cd_service (Python)           │
│                                                             │
│   Select Project + Tag  ──→  Deploy Execution               │
│                                                             │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│   │  SSH Script  │  │Docker Compose│  │  Kubernetes  │      │
│   │  Ansible     │  │  SFTP + up   │  │ kubectl/Helm │      │
│   │              │  │              │  │ ArgoCD/FluxCD│      │
│   └──────────────┘  └──────────────┘  └──────────────┘      │
│                           ↓                                 │
│              cd_deploy_logs (Deployment Records)            │
│                           ↓                                 │
│              DingTalk / WeCom Webhook Notifications         │
└─────────────────────────────────────────────────────────────┘
```
<details>
## <summary>Quick Start</summary>

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

Visit `http://localhost:8080/admin` for the admin panel (credentials in `.env`).

Visit `http://localhost:8080/api/docs` for interactive API docs (Swagger UI).
</details>
## Requirements

| Component | Version |
|---|---|
| PHP | 8.1+ |
| Database | SQLite (default) / MySQL 8.0+ / MariaDB 10.4+ |
| Jenkins | v2.60+ |
| GitLab | v9.0+ (API v4) |
| Harbor | v1.10.1 / v2.x |

See [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) for full environment variable reference and CORS configuration.

## Documentation

| Document | Language | Description |
|----------|----------|-------------|
| [API Reference](docs/API.md) | EN | API endpoints, request/response formats, quick tests |
| [ARCHITECTURE](docs/ARCHITECTURE.md) | EN | 整体数据流、组件关系、部署模式矩阵|
| [Admin Manual](docs/ADMIN_MANUAL.md) | EN | Environment variables, mapping config, custom Git platform |
| [Technical Guide](docs/Technical-Guide.md) | EN | Architecture, DB design, data flows, troubleshooting |
| [FAQ](docs/FAQ.md) | EN | Common issues and troubleshooting |
| [Changelog](docs/CHANGELOG.md) | — | Release notes |

## Related Projects

- **Devops-CD** — Continuous Deployment system that can be used alongside this system:  
  https://github.com/jeanslw/Devops_CD

## Project Structure

```
config/         # Server config (.env, DI container, routes, settings)
database/       # MySQL & SQLite init scripts
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
