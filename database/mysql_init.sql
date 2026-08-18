-- =============================================================
-- Devops-Glue MySQL 建表脚本
-- 适用: MySQL 8.0+
-- Using to inspect structure; no modification
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
    `status`            VARCHAR(255) DEFAULT '',
    `created_at`        DATETIME DEFAULT (NOW()),
    PRIMARY KEY (`project`, `pipeline_iid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. ci_custom_builds（自定义推送式 CI 的构建记录）──
CREATE TABLE IF NOT EXISTS `ci_custom_builds` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `job_name`       VARCHAR(255) NOT NULL,
    `pipeline_iid`   INT NOT NULL,
    `ref`            TEXT,
    `sha`            TEXT,
    `variables_json` TEXT,
    `status`         VARCHAR(255) DEFAULT 'pending',
    `exit_code`      INT,
    `log_url`        TEXT,
    `web_url`        TEXT,
    `triggered_at`   DATETIME,
    `started_at`     DATETIME,
    `finished_at`    DATETIME,
    UNIQUE KEY `uniq_job_pipeline` (`job_name`, `pipeline_iid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. cache（通用缓存）──
CREATE TABLE IF NOT EXISTS `cache` (
    `cache_key`  VARCHAR(255) PRIMARY KEY,
    `value`      MEDIUMTEXT NOT NULL,
    `expires_at` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. ci_app_settings（应用运行时配置）──
CREATE TABLE IF NOT EXISTS `ci_app_settings` (
    `setting_key` VARCHAR(255) PRIMARY KEY,
    `value`       MEDIUMTEXT NOT NULL,
    `updated_at`  DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. admin_users（管理员账号）──
CREATE TABLE IF NOT EXISTS `admin_users` (
    `username`      VARCHAR(255) PRIMARY KEY,
    `password_hash` TEXT NOT NULL,
    `role`          VARCHAR(32) NOT NULL DEFAULT 'admin',
    `systems`       VARCHAR(128) NOT NULL DEFAULT 'ci,cd',
    `updated_at`    DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 8. ci_security_checks（安全扫描审计）──
CREATE TABLE IF NOT EXISTS `ci_security_checks` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `project`           VARCHAR(255) NOT NULL,
    `sha`               VARCHAR(255) NOT NULL,
    `check_type`        VARCHAR(255) NOT NULL,
    `state`             VARCHAR(255) NOT NULL,
    `context`           VARCHAR(255) NOT NULL,
    `description`       TEXT,
    `tag`               VARCHAR(255) DEFAULT '',
    `writeback_status`  VARCHAR(255) DEFAULT '',
    `writeback_message` TEXT,
    `created_at`        DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 9. roles（角色）──
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(255) NOT NULL UNIQUE,
    `description` TEXT,
    `is_system`   TINYINT NOT NULL DEFAULT 0,
    `created_at`  DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 10. permissions（权限定义；parent_key 用于二级菜单 eg. cd.deploy.single → cd.deploy-manage）──
CREATE TABLE IF NOT EXISTS `permissions` (
    `perm_key`    VARCHAR(128) PRIMARY KEY,
    `description` TEXT,
    `parent_key`  VARCHAR(128) DEFAULT NULL,
    `created_at`  DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 11. role_permissions（角色↔权限）──
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`  INTEGER NOT NULL,
    `perm_key` VARCHAR(128) NOT NULL,
    PRIMARY KEY (`role_id`, `perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 12. implied_rules（权限隐含关系；数据驱动，CD 可通过 API 注册）──
CREATE TABLE IF NOT EXISTS `implied_rules` (
    `source_key` VARCHAR(128) NOT NULL,
    `target_key` VARCHAR(128) NOT NULL,
    PRIMARY KEY (`source_key`, `target_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 13. api_tokens（服务账号 / 第三方调用的 API token，独立于 RBAC）──
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(255) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `scopes`     TEXT,
    `enabled`    TINYINT NOT NULL DEFAULT 1,
    `expires_at` INT,
    `created_by` VARCHAR(255),
    `note`       TEXT,
    `created_at` DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 索引 ──
-- 新库一次性建全（无存量数据，不保留迁移逻辑），直接建索引
CREATE INDEX `idx_pipeline_tags_project`    ON `ci_pipeline_tags` (`project`);
CREATE INDEX `idx_pipeline_tags_created`    ON `ci_pipeline_tags` (`created_at`);
CREATE INDEX `idx_job_git_map_current_path` ON `ci_job_git_map` (`current_path`(255));
CREATE INDEX `idx_security_checks_project`  ON `ci_security_checks` (`project`(64), `check_type`(64));
CREATE INDEX `idx_security_checks_sha`      ON `ci_security_checks` (`sha`);
CREATE INDEX `idx_custom_builds_job`        ON `ci_custom_builds` (`job_name`);
CREATE INDEX `idx_custom_builds_status`     ON `ci_custom_builds` (`status`);
