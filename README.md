# Devops-Glue API v2.4.0

[中文版](README_ZH-CN.md)

> **Not a big-company remote control — a Swiss Army knife for small teams.**

Devops-Glue is a DevOps toolchain integration platform built on Slim4 for small teams. A unified API to manage Jenkins + GitLab CI dual-channel builds, GitLab / Gitee / GitHub / Gitea multi-platform code, and Harbor image registry — covering the full CI-to-CD workflow. Bilingual interface, role-based access control.

![System Overview](system_info.png)
![System Status](system_running.png)

## Features

- **i18n** — Chinese / English bilingual interface, `?lang=` instant switching via `symfony/translation`
- **Dual Build Pipeline** — Jenkins + GitLab CI, switch or coexist, unified API
- **Multi-Platform Git** — GitLab · GitHub · Gitee · Gitea, self-hosted or SaaS
- **Full-Chain Mapping** — Job ↔ Git repo ↔ Harbor image, build→code→artifact auto-association
- **Security Scan Audit** — SAST, secret scanning, dependency vulns written back via Commit Status
- **Role-Based Access** — `super_admin` > `admin` > `deployer` > `viewer`, 4-tier RBAC
- **Admin Dashboard** — service monitoring, mapping config, security scan, user management
- **Zero-Config Startup** — SQLite by default, MySQL / MariaDB with one-click switch

## Quick Start

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

## Requirements

| Component | Version |
|---|---|
| PHP | 8.0+ |
| Database | SQLite (default) / MySQL 8.0+ / MariaDB 10.4+ |
| Jenkins | v2.60+ |
| GitLab | v9.0+ (API v4) |
| Harbor | v1.10.1 / v2.x |

See [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) for full environment variable reference and CORS configuration.

## Documentation

| Document | Language | Description |
|---|---|---|
| [docs/API.md](docs/API.md) | EN | API Reference — endpoints, request/response formats, quick tests |
| [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) | EN | Admin Manual — env vars, mapping config, custom Git platform |
| [docs/用户说明.md](docs/用户说明.md) | 中文 | User Guide — end-user instructions |
| [docs/技术文档.md](docs/技术文档.md) | 中文 | Technical Guide — architecture, DB design, data flows, troubleshooting |
| [docs/technical-guide.md](docs/technical-guide.md) | EN | Technical Guide — architecture, DB design, data flows, troubleshooting |
| [docs/常见问题.md](docs/常见问题.md) | 中文 | FAQ — deployment, config, builds, auth, Harbor, etc. |
| [docs/FAQ.md](docs/FAQ.md) | EN | FAQ — deployment, config, builds, auth, Harbor, etc. |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | — | Release notes |

## Related Projects

- **Devops-CD** — Continuous Deployment system (ArgoCD / FluxCD / Helm / Kubectl):  
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
