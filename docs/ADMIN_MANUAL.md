# Devops-Glue API Admin Manual v2.4

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [Manual Mapping Configuration](#manual-mapping-configuration)
3. [CORS Configuration](#cors-configuration)
4. [Multi-Environment Deployment](#multi-environment-deployment)
5. [Custom Git Platform Integration](#custom-git-platform-integration)

---

## Environment Variables

Copy `config/.env.example` to `config/.env` and fill in your actual credentials.

```ini
# ============ CI Systems ============
JENKINS_BASE_URL=http://your-jenkins:8080
JENKINS_USER=admin
JENKINS_TOKEN=your_token

BUILD_MODE=both           # jenkins / gitlab_ci / both (seed value on first boot)
BUILD_TIMEOUT=300         # Build timeout in seconds

# ============ Git Platforms ============
GITLAB_BASE_URL=http://your-gitlab
GITLAB_TOKEN=your_token

GITHUB_BASE_URL=https://api.github.com
GITHUB_TOKEN=your_token

GITEE_BASE_URL=https://gitee.com/api/v5
GITEE_TOKEN=your_token

GITEA_BASE_URL=http://your-gitea
GITEA_TOKEN=your_token

DEFAULT_GIT_PLATFORM=gitlab   # Fallback when URL cannot be auto-detected

# ============ Harbor ============
HARBOR_BASE_URL=http://your-harbor
HARBOR_USER=admin
HARBOR_PASSWORD=your_password

# ============ Admin Panel ============
ADMIN_USER=admin
ADMIN_PASSWORD=               # Created on first boot; DB takes precedence afterwards

# ============ Database ============
DB_DRIVER=mysql               # sqlite or mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=devops_glue
DB_USER=root
DB_PASS=your_password
DB_PATH=config/data/data.db   # SQLite only
DB_AUTO_MIGRATE=true          # Auto-create tables; false = run scripts in database/ manually

# ============ Application ============
APP_ENV=production            # production / staging / development
APP_DEBUG=false
API_BASE_URL=http://127.0.0.1:8080
LOG_PATH=/applogs/
```

> **Note:** Jenkins / GitLab / Harbor URLs must be reachable from the Devops-Glue container.

- `DB_DRIVER=mysql`: Container auto-starts MySQL 8.4 and creates the database. MariaDB 10.4+ is also fully supported.
- `DB_DRIVER=sqlite`: Data file at `config/data/data.db`. Mount a shared volume if CD Service needs to access the same `.db` file. MySQL/MariaDB is recommended for production.

---

## Manual Mapping Configuration

Each job can be configured with the following fields. Only `job_name` is required; others can be omitted and auto-derived by the system.

| Field | Required | Description |
|---|---|---|
| `job_name` | ✅ | Jenkins Job or GitLab CI project full path, e.g. `"java/registry"` |
| `build_provider` | | CI system: `jenkins` (default) / `gitlab_ci` |
| `git_platform` | | **Strongly recommended** for self-hosted instances. Auto-detected from URL keywords if omitted; self-hosted GitLab/Gitea domains often lack platform keywords and will fall back to `DEFAULT_GIT_PLATFORM`. Values: `gitlab` `gitee` `github` `gitea` or custom name |
| `git_remote` | | Auto-fetched from Jenkins Job SCM config if omitted |
| `project_id` | | GitLab: auto-queried via API if omitted; GitHub/Gitee: fill if known |
| `web_url` | | Project homepage link (display only) |
| `current_path` | | Project path; auto-derived from `git_remote` if omitted |
| `harbor_repository` | | Associated Harbor repo, format `"project/repository"` (display only) |
| `api_version` | | **Metadata only**; does not affect actual API routing (routes are hardcoded in each Service) |

Example (`config/settings.php`):

```php
'job_git_map' => [
    // Self-hosted GitLab (domain lacks platform keyword → must specify git_platform)
    [
        'job_name'          => 'java/registry',
        'git_platform'      => 'gitlab',
        'git_remote'        => 'http://git.mycompany.com/tools/registry.git',
        'project_id'        => 2,
        'harbor_repository' => 'mycode/code-runtime',
    ],
    // SaaS Gitee (URL contains gitee.com → auto-detected, git_platform optional)
    [
        'job_name'          => 'static',
        'harbor_repository' => 'mycode/static-app',
    ],
],
```

---

## CORS Configuration

Edit `config/settings.php`:

```php
'cors' => [
    'allowed_origins' => ['*'],   // Allowed domains, * = all
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
]
```

---

## Multi-Environment Deployment

The system supports environment separation via `APP_ENV` (production / staging / development):

- `.env`: Base config (contains real passwords, gitignored)
- `.env.staging`: Staging overrides (e.g. `APP_DEBUG=true`)
- `.env.local`: Local overrides (gitignored, personal tweaks)

Loading priority: `.env.local` > `.env.{APP_ENV}` > `.env`

Default `APP_ENV=production` does not require additional files. See `docs/technical-guide.md` (English) or `docs/技术文档.md` (Chinese) for details.

---

## Custom Git Platform Integration

You can integrate any Git platform without modifying core source code.

### 1. Write a Provider class

Create an adapter in `src/Service/Git/` implementing `GitProviderInterface`:

```php
namespace App\Service\Git;

class BitbucketService implements GitProviderInterface
{
    public function getName(): string { return 'bitbucket'; }
    public function matchUrl(string $url): bool { return str_contains($url, 'bitbucket'); }
    public function getApiVersion(): string { return 'v2'; }
    public function getBranches(string $repository): array { /* API logic */ }
}
```

### 2. Register in `config/settings.php`

```php
'git' => [
    'custom_providers' => [
        [
            'class'  => 'App\\Service\\Git\\BitbucketService',
            'config' => [
                'name'         => 'bitbucket',
                'base_url'     => 'https://api.bitbucket.org/2.0',
                'token'        => env('BITBUCKET_TOKEN', ''),
                'api_version'  => 'v2',
                'matcher'      => function (string $url): bool {
                    return str_contains($url, 'bitbucket');
                },
            ],
        ],
    ],
],
```

### 3. Auto-discovery

No further source changes needed. The system automatically discovers and registers custom providers on startup.

> **Note:** Custom platforms do not support independent `.env` variables (e.g. `BITBUCKET_TOKEN`). Tokens must be written into `settings.php` or extend `AppConfig` yourself.

### 4. Adding Built-in Platforms

To add a built-in platform (GitLab/Gitee/GitHub/Gitea style), modify:

- `src/Service/Git/XxxService.php` (adapter)
- `config/container.php` (ProviderRegistry registration)
- `config/AppConfig.php` (`getXxxConfig` + `getDefaultApiVersion` + `getGitPlatformsConfig`)
- `config/settings.php` + `settings.example.php` (config sections)
- `config/.env.example` (environment variable declarations)
