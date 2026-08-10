# Devops-Glue Architecture Overview

## Overall Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│                        CODE PUSH                            │
│  GitLab / Gitee / GitHub / Gitea  →  Webhook Trigger        │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                  CI LAYER：Devops-Glue API (PHP)            │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐   ┌──────────────┐      │
│  │   Jenkins    │  │  GitLab CI   │   │   Custom CI  │      │
│  │ BuildProvider│  │ BuildProvider│   │ BuildProvider│      │
│  └──────┬───────┘  └──────┬───────┘   └──────┬───────┘      │
│         └─────────────────┼─────────────────┘               │
│                           ↓                                 │
│              Build → Docker Image → Harbor Registry         │
│                           ↓                                 │
│              scan-sync → ci_pipeline_tags                   │
└──────────────────────────┬──────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│                  CD LAYER：cd_service (Python)              │
│                                                             │
│   Select Project + Tag  ──→  Deploy Execution               │
│                                                             │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│   │ SSH Script   │  │Docker Compose│  │  Kubernetes  │      │
│   │  Ansible     │  │  SFTP + up   │  │ kubectl/Helm │      │
│   │              │  │              │  │ ArgoCD/FluxCD│      │
│   └──────────────┘  └──────────────┘  └──────────────┘      │
│                           ↓                                 │
│              cd_deploy_logs (Deployment Records)            │
│                           ↓                                 │
│            DingTalk / WeCom Webhook Notifications           │
└─────────────────────────────────────────────────────────────┘
```

## Component Relationships
```
┌──────────────────────────────────────┐
│Shared Database (SQLite/MySQL/MariaDB)│
│                                      │
│  ci_job_git_map   ← CI read-only     │
│  ci_pipeline_tags ← CI write/CD read │
│  cd_servers       ← CD maintains     │
│  cd_deploy_logs   ← CD writes        │
│  cd_bots          ← CD maintains     │
│  admin_users      ← Shared           │
└──────────────┬───────────────────────┘
			   │
		┌──────┴──────┐
		↓             ↓
	┌────────┐   ┌────────┐
	│ PHP CI │   │PythonCD│
	│:8080   │   │:8081   │
	└────────┘   └────────┘
```

> **Database Selection**: PHP CI and CD Service must use the same database instance.
> - **SQLite**: Zero-configuration, suitable for single-host development/testing. For container deployments, mount the `config/data/` directory as a shared volume so both processes can access the same `.db` file.
> - **MySQL 8.0+ / MariaDB 10.4+**: Recommended for production. Supports concurrent reads and writes, no shared volume required.

## Deployment Mode Matrix

| Deployment Type | Mode | Implementation |
|-----------------|------|----------------|
| SSH (single host) | Custom Command | Shell script with `{image}` `{tag}` `{project}` placeholders |
| SSH (single host) | Ansible Playbook | `ansible-playbook -e image={image} -e tag={tag}` |
| Docker Compose | Remote YAML | `cd {path} && docker compose up -d` |
| Docker Compose | Inline YAML | SFTP upload compose YAML → auto-create dir → startup |
| K8s kubectl | SSH apply | SSH to master → `kubectl apply -f` |
| K8s Helm | SSH kubectl | `helm upgrade --install` + version verification |
| K8s Argo CD | REST API | PATCH image → sync → poll until Healthy |
| K8s Flux CD | SSH kubectl | PATCH resource → wait for ready |

## Design Patterns

- **Strategy Pattern**: `BuildProviderInterface` (PHP CI) / `Deployer` (Python CD) abstract base class + Registry
- **Factory Pattern**: `GitProviderFactory` auto-matches Git platform adapter by URL
- **Dual-driver Database**: SQLite / MySQL / MariaDB unified interface, one codebase for three modes, sharing the same database instance with CD Service

## Problem Statement

Fragmented DevOps toolchain for SMBs:

- Git platforms (GitLab/Gitee/GitHub/Gitea) → Unified integration
- CI engines (Jenkins/GitLab CI) → Dual-channel unification
- Image registry (Harbor) → Scan & sync
- Deploy targets (SSH/Docker/K8s) → Unified execution
- Notifications (DingTalk/WeCom) → Auto-push

**One sentence: glue scattered DevOps tools into a single layer.**