-- =============================================================
-- Devops-Glue MySQL 建表脚本
-- 适用: MySQL 8.0+
-- 使用: mysql -u root -p < database/mysql_init.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS `devops_glue` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `devops_glue`;

-- ── 1. ci_job_git_map（Job ↔ Git 映射）──
CREATE TABLE IF NOT EXISTS `ci_job_git_map` (
    `job_name`          VARCHAR(255) PRIMARY KEY,
    `git_platform`      TEXT,
    `build_provider`    VARCHAR(255) DEFAULT 'jenkins',
    `git_remote`        TEXT,
    `project_id`        INT,
    `web_url`           TEXT,
    `current_path`      TEXT,
    `harbor_repository` TEXT,
    `api_version`       TEXT,
    `status`            VARCHAR(255) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. ci_platform_versions（平台 API 版本）──
CREATE TABLE IF NOT EXISTS `ci_platform_versions` (
    `platform`  VARCHAR(255) PRIMARY KEY,
    `version`   TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. ci_pipeline_tags（Pipeline ↔ Tag 映射）──
CREATE TABLE IF NOT EXISTS `ci_pipeline_tags` (
    `project`           VARCHAR(255) NOT NULL,
    `pipeline_iid`      INT NOT NULL,
    `tag`               VARCHAR(255) NOT NULL,
    `harbor_repository` TEXT,
    `created_at`        DATETIME DEFAULT (NOW()),
    PRIMARY KEY (`project`, `pipeline_iid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. cache（通用缓存）──
CREATE TABLE IF NOT EXISTS `cache` (
    `cache_key`  VARCHAR(255) PRIMARY KEY,
    `value`      MEDIUMTEXT NOT NULL,
    `expires_at` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. admin_users（管理员账号）──
CREATE TABLE IF NOT EXISTS `admin_users` (
    `username`      VARCHAR(255) PRIMARY KEY,
    `password_hash` TEXT NOT NULL,
    `updated_at`    DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 索引 ──
CREATE INDEX IF NOT EXISTS `idx_pipeline_tags_project`   ON `ci_pipeline_tags`(`project`);
CREATE INDEX IF NOT EXISTS `idx_pipeline_tags_created`    ON `ci_pipeline_tags`(`created_at`);
CREATE INDEX IF NOT EXISTS `idx_job_git_map_current_path` ON `ci_job_git_map`(`current_path`);
