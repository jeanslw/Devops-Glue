-- =============================================================
-- Devops-Glue SQLite 建表脚本
-- Using to inspect structure; no modification
-- 使用: sqlite3 config/data/data.db < database/sqlite_init.sql
-- =============================================================

PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

-- ── 1. ci_job_git_map（Job ↔ Git 映射）──
CREATE TABLE IF NOT EXISTS ci_job_git_map (
    job_name          TEXT PRIMARY KEY,
    git_platform      TEXT,
    build_provider    TEXT DEFAULT 'jenkins',
    git_remote        TEXT,
    project_id        INTEGER,
    web_url           TEXT,
    current_path      TEXT,
    harbor_repository TEXT,
    api_version       TEXT,
    status            TEXT DEFAULT 'active'
);

-- ── 2. ci_platform_versions（平台 API 版本）──
CREATE TABLE IF NOT EXISTS ci_platform_versions (
    platform  TEXT PRIMARY KEY,
    version   TEXT NOT NULL
);

-- ── 3. ci_pipeline_tags（Pipeline ↔ Tag 映射）──
CREATE TABLE IF NOT EXISTS ci_pipeline_tags (
    project           TEXT NOT NULL,
    pipeline_iid      INTEGER NOT NULL,
    tag               TEXT NOT NULL,
    harbor_repository TEXT,
    status            TEXT DEFAULT '',
    created_at        TEXT DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (project, pipeline_iid)
);

-- ── 4. ci_custom_builds（自定义推送式 CI 的构建记录）──
CREATE TABLE IF NOT EXISTS ci_custom_builds (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    job_name       TEXT NOT NULL,
    pipeline_iid   INTEGER NOT NULL,
    ref            TEXT,
    sha            TEXT,
    variables_json TEXT,
    status         TEXT DEFAULT 'pending',
    exit_code      INTEGER,
    log_url        TEXT,
    web_url        TEXT,
    triggered_at   TEXT,
    started_at     TEXT,
    finished_at    TEXT,
    UNIQUE (job_name, pipeline_iid)
);

-- ── 5. cache（通用缓存）──
CREATE TABLE IF NOT EXISTS cache (
    cache_key  TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    expires_at INTEGER
);

-- ── 6. ci_app_settings（应用运行时配置）──
CREATE TABLE IF NOT EXISTS ci_app_settings (
    setting_key TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 7. admin_users（管理员账号）──
CREATE TABLE IF NOT EXISTS admin_users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'admin',
    systems       TEXT NOT NULL DEFAULT 'ci,cd',
    email         TEXT NOT NULL DEFAULT '',
    updated_at    TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 8. ci_security_checks（安全扫描审计）──
CREATE TABLE IF NOT EXISTS ci_security_checks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    project           TEXT NOT NULL,
    sha               TEXT NOT NULL,
    check_type        TEXT NOT NULL,
    state             TEXT NOT NULL,
    context           TEXT NOT NULL,
    description       TEXT,
    tag               TEXT DEFAULT '',
    writeback_status  TEXT DEFAULT '',
    writeback_message TEXT,
    created_at        TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 9. roles（角色）──
CREATE TABLE IF NOT EXISTS roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    description TEXT,
    is_system   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 10. permissions（权限定义；parent_key 用于二级菜单 eg. cd.deploy.single → cd.deploy-manage）──
CREATE TABLE IF NOT EXISTS permissions (
    perm_key    TEXT PRIMARY KEY,
    description TEXT,
    parent_key  TEXT DEFAULT NULL,
    created_at  TEXT DEFAULT NULL
);

-- ── 11. role_permissions（角色↔权限）──
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id  INTEGER NOT NULL,
    perm_key TEXT NOT NULL,
    PRIMARY KEY (role_id, perm_key)
);

-- ── 12. implied_rules（权限隐含关系；数据驱动，CD 可通过 API 注册）──
CREATE TABLE IF NOT EXISTS implied_rules (
    source_key TEXT NOT NULL,
    target_key TEXT NOT NULL,
    PRIMARY KEY (source_key, target_key)
);

-- ── 13. api_tokens（服务账号 / 第三方调用的 API token，独立于 RBAC）──
CREATE TABLE IF NOT EXISTS api_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    scopes     TEXT,
    enabled    INTEGER NOT NULL DEFAULT 1,
    expires_at INTEGER,
    created_by TEXT,
    note       TEXT,
    created_at TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 索引 ──
CREATE INDEX IF NOT EXISTS idx_pipeline_tags_project     ON ci_pipeline_tags(project);
CREATE INDEX IF NOT EXISTS idx_pipeline_tags_created      ON ci_pipeline_tags(created_at);
CREATE INDEX IF NOT EXISTS idx_job_git_map_current_path   ON ci_job_git_map(current_path);
CREATE INDEX IF NOT EXISTS idx_security_checks_project    ON ci_security_checks(project, check_type);
CREATE INDEX IF NOT EXISTS idx_security_checks_sha        ON ci_security_checks(sha);
CREATE INDEX IF NOT EXISTS idx_custom_builds_job          ON ci_custom_builds(job_name);
CREATE INDEX IF NOT EXISTS idx_custom_builds_status       ON ci_custom_builds(status);

-- =============================================================
-- 迁移说明
-- =============================================================
-- 已有数据库的增量字段由应用启动时 Database::ensureTables()
-- 幂等补列（columnExists 检测后再 ALTER），此处不再放裸 ALTER，
-- 避免重复执行时报 "duplicate column name" 刷屏。
-- 新建库直接使用上方 CREATE TABLE 的完整结构即可。
