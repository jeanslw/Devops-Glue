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
    `status`            VARCHAR(255) DEFAULT '',
    `created_at`        DATETIME DEFAULT (NOW()),
    PRIMARY KEY (`project`, `pipeline_iid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. cache（通用缓存）──
CREATE TABLE IF NOT EXISTS `cache` (
    `cache_key`  VARCHAR(255) PRIMARY KEY,
    `value`      MEDIUMTEXT NOT NULL,
    `expires_at` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. ci_app_settings（应用运行时配置）──
CREATE TABLE IF NOT EXISTS `ci_app_settings` (
    `setting_key` VARCHAR(255) PRIMARY KEY,
    `value`       MEDIUMTEXT NOT NULL,
    `updated_at`  DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. admin_users（管理员账号）──
CREATE TABLE IF NOT EXISTS `admin_users` (
    `username`      VARCHAR(255) PRIMARY KEY,
    `password_hash` TEXT NOT NULL,
    `role`          VARCHAR(32) NOT NULL DEFAULT 'admin',
    `systems`       VARCHAR(128) NOT NULL DEFAULT 'ci,cd',
    `updated_at`    DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. ci_security_checks（安全扫描审计）──
CREATE TABLE IF NOT EXISTS `ci_security_checks` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `project`       VARCHAR(255) NOT NULL,
    `sha`           VARCHAR(255) NOT NULL,
    `check_type`    VARCHAR(255) NOT NULL,
    `state`         VARCHAR(255) NOT NULL,
    `context`       VARCHAR(255) NOT NULL,
    `description`   TEXT,
    `tag`           VARCHAR(255) DEFAULT '',
    `created_at`    DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 8. roles（角色）──
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(255) NOT NULL UNIQUE,
    `description` TEXT,
    `is_system`   TINYINT NOT NULL DEFAULT 0,
    `created_at`  DATETIME DEFAULT (NOW())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 9. permissions（权限定义；parent_key 用于二级菜单 eg. cd.deploy.single → cd.deploy-manage）──
CREATE TABLE IF NOT EXISTS `permissions` (
    `perm_key`    VARCHAR(128) PRIMARY KEY,
    `description` TEXT,
    `parent_key`  VARCHAR(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 10. role_permissions（角色↔权限）──
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`  INTEGER NOT NULL,
    `perm_key` VARCHAR(128) NOT NULL,
    PRIMARY KEY (`role_id`, `perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 11. implied_rules（权限隐含关系；数据驱动，CD 可通过 API 注册）──
CREATE TABLE IF NOT EXISTS `implied_rules` (
    `source_key` VARCHAR(128) NOT NULL,
    `target_key` VARCHAR(128) NOT NULL,
    PRIMARY KEY (`source_key`, `target_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 索引 ──
-- 注：首次建库和迁移都通过下方 _add_index 过程处理（兼容 DATETIME / TEXT 两种列类型）
-- 这里不放裸 CREATE INDEX，避免重复执行时报 1061（重复键名）

-- =============================================================
-- 迁移（已有数据库增量更新，重复执行安全）
-- =============================================================
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `_add_column`(
    IN `tbl` VARCHAR(64), IN `col` VARCHAR(64), IN `def` TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = `tbl` AND COLUMN_NAME = `col`
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', `tbl`, '` ADD COLUMN `', `col`, '` ', `def`);
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END//

CREATE PROCEDURE IF NOT EXISTS `_add_index`(
    IN `tbl` VARCHAR(64), IN `idx` VARCHAR(64), IN `def` TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = `tbl` AND INDEX_NAME = `idx`
    ) THEN
        SET @s = CONCAT('CREATE INDEX `', `idx`, '` ON `', `tbl`, '` ', `def`);
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

CALL `_add_column`('ci_pipeline_tags', 'harbor_repository', 'TEXT');
CALL `_add_column`('ci_pipeline_tags', 'status',            'VARCHAR(255) DEFAULT \'\'');

CALL `_add_index`('ci_pipeline_tags',   'idx_pipeline_tags_project',     '(`project`)');
CALL `_add_index`('ci_pipeline_tags',   'idx_pipeline_tags_created',     '(`created_at`)');
CALL `_add_index`('ci_job_git_map',     'idx_job_git_map_current_path',  '(`current_path`(255))');
CALL `_add_index`('ci_security_checks', 'idx_security_checks_project',  '(`project`(64), `check_type`(64))');
CALL `_add_index`('ci_security_checks', 'idx_security_checks_sha',       '(`sha`)');

DROP PROCEDURE IF EXISTS `_add_column`;
DROP PROCEDURE IF EXISTS `_add_index`;
