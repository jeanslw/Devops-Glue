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
│  ci_custom_builds ← Custom_Push write│
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

## CI Build Modes: Pull-based vs Push-based

Devops-Glue supports two orthogonal CI modes that can be enabled simultaneously:

### Pull-based CI (Traditional)

- **Switch**: `build_mode` (`jenkins` / `gitlab_ci` / `both`)
- **Direction**: Devops-Glue actively calls CI APIs to trigger builds, poll status, and fetch image tags
- **Providers**: `JenkinsBuildProvider`, `GitLabCIBuildProvider`
- **Flow**: Devops-Glue → CI API → Build → Harbor → scan-sync → `ci_pipeline_tags`

### Push-based CI (Custom_Push)

- **Switch**: `custom_push_enabled` (independent boolean, orthogonal to `build_mode`)
- **Direction**: User's own CI scripts push build status, log URL, and image tags to Devops-Glue
- **Provider**: `CustomPushBuildProvider` (implements `BuildProviderInterface`)
- **Flow**: User CI → Devops-Glue API → `ci_custom_builds` (metadata) + `ci_pipeline_tags` (image tags)

### Orthogonal Design

`build_mode` and `custom_push_enabled` are independent and can be combined freely:

| build_mode | custom_push_enabled | Effect |
|---|---|---|
| jenkins | false | Jenkins pull-based only |
| gitlab_ci | false | GitLab CI pull-based only |
| both | false | Jenkins + GitLab CI pull-based |
| jenkins | true | Jenkins + Custom_Push simultaneously |
| gitlab_ci | true | GitLab CI + Custom_Push simultaneously |
| both | true | All three working simultaneously |

### Custom_Push Key Design

- **Metadata only**: Devops-Glue stores `status`, `log_url`, `tag` in the `ci_custom_builds` table and reuses `ci_pipeline_tags` for image tags
- **Log proxy**: `log_url` is a pointer only; Devops-Glue proxies log content and **does not store logs**. Since Devops-Glue cannot verify build authenticity, logs as evidence must be held by the executor (user CI)
- **API endpoints**:
  - `POST /api/build/{path}/report` — report terminal build result and image tag in one call
- **Config-driven**: `CustomPushBuildProvider` is registered via the `build.custom_providers` array in `settings.php`, ready out of the box
- **Auto-discovery**: When `custom_push` is enabled, auto-discovery scans Git platforms for projects configured with custom_push
- **pipeline_iid constraint**: To align with `ci_pipeline_tags` constraints, `pipeline_iid` must be an integer; duplicate reports overwrite (UPDATE) the existing record

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
- **Orthogonal CI Design**: `build_mode` (pull-based) and `custom_push_enabled` (push-based) are independent switches that can be combined freely and enabled simultaneously

## Problem Statement

Fragmented DevOps toolchain for SMBs:

- Git platforms (GitLab/Gitee/GitHub/Gitea) → Unified integration
- CI engines (Jenkins/GitLab CI) → Dual-channel unification
- Image registry (Harbor) → Scan & sync
- Deploy targets (SSH/Docker/K8s) → Unified execution
- Notifications (DingTalk/WeCom) → Auto-push

**One sentence: glue scattered DevOps tools into a single layer.**