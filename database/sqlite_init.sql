-- =============================================================
-- Devops-Glue SQLite 建表脚本
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
    created_at        TEXT DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (project, pipeline_iid)
);

-- ── 4. cache（通用缓存）──
CREATE TABLE IF NOT EXISTS cache (
    cache_key  TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    expires_at INTEGER
);

-- ── 5. admin_users（管理员账号）──
CREATE TABLE IF NOT EXISTS admin_users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    updated_at    TEXT DEFAULT (datetime('now','localtime'))
);

-- ── 索引 ──
CREATE INDEX IF NOT EXISTS idx_pipeline_tags_project   ON ci_pipeline_tags(project);
CREATE INDEX IF NOT EXISTS idx_pipeline_tags_created    ON ci_pipeline_tags(created_at);
CREATE INDEX IF NOT EXISTS idx_job_git_map_current_path ON ci_job_git_map(current_path);
