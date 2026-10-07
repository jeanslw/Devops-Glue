<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;

/**
 * ci_app_settings 键值仓储：应用运行时开关/配置（DB 为唯一真相来源）。
 *
 * 承载从 AppConfig 迁出的 DB 读写职责：
 *   - build_mode（启用的拉取式 provider 集合，含旧格式自愈映射）
 *   - custom_push_enabled / stale_tag_cleanup_enabled / backfill_tag_enabled
 *   - tag_log_keyword（推送成功关键字）
 *
 * settings.php / env 的纯配置读取见 {@see Settings}。
 */
class AppSettingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    private function getString(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = ?"
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : (string)$row['value'];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function setString(string $key, string $value): void
    {
        $sql = Database::sqlUpsert(
            AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([$key, $value]);
    }

    private function getBoolFlag(string $key, bool $default): bool
    {
        $value = $this->getString($key);
        if ($value === null) {
            return $default;
        }
        return $value === '1';
    }

    // ─── 构建系统模式（数据库为唯一来源） ───

    /**
     * 获取启用的构建 provider 集合（主 API）。
     * 逻辑：DB build_mode → 解析为集合返回。若 DB 无记录（首次运行），
     * 从 app.env BUILD_MODE 取种子值写入 DB 后返回。此后 DB 为唯一真相来源。
     *
     * 旧格式（jenkins / gitlab_ci / both 单值）惰性映射到新格式并自愈回写。
     *
     * @return string[] 已启用的拉取式 provider（jenkins/gitlab_ci/gitea_ci），可为空数组
     */
    public function getBuildModes(): array
    {
        try {
            $stored = $this->getString('build_mode');
            if ($stored !== null) {
                $modes = self::parseBuildModes($stored);
                if ($stored !== implode(',', $modes)) {
                    $this->setBuildModes($modes);
                }
                return $modes;
            }
            // DB 无记录 → 首次运行，以 app.env 为种子写入 DB
            $modes = self::parseBuildModes($_ENV['BUILD_MODE'] ?? AppConfig::BUILD_MODE_BOTH);
            try {
                $this->setBuildModes($modes);
            } catch (\Exception $e) {
                // 写失败（DB 只读等）不阻塞读取
            }
            return $modes;
        } catch (\Exception $e) {
            // DB 彻底不可用时的最后兜底
            return self::parseBuildModes($_ENV['BUILD_MODE'] ?? AppConfig::BUILD_MODE_BOTH);
        }
    }

    /**
     * 解析 build_mode 值为规范化 provider 集合（旧格式 both/jenkins/gitlab_ci 兼容映射）。
     *
     * @return string[]
     */
    public static function parseBuildModes(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        if ($value === AppConfig::BUILD_MODE_BOTH) {
            return [AppConfig::PROVIDER_JENKINS, AppConfig::PROVIDER_GITLAB_CI];
        }
        if (in_array($value, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
            return [$value];
        }
        // 新格式：逗号分隔集合，只保留内置拉取式 provider，未知值丢弃。
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn($s) => $s !== ''));
        return array_values(array_intersect(AppConfig::BUILTIN_PULL_PROVIDERS, $parts));
    }

    /**
     * @param list<string> $modes
     */
    public function setBuildModes(array $modes): void
    {
        // array_intersect 以 BUILTIN_PULL_PROVIDERS 的顺序返回，天然去重，保持规范顺序
        $modes = array_values(array_intersect(AppConfig::BUILTIN_PULL_PROVIDERS, array_map('trim', $modes)));
        $this->setString('build_mode', implode(',', $modes));
    }

    /**
     * 兼容字符串：返回 join 出的规范串（供缓存 key / 汇总 / configMode 复用）。
     */
    public function getBuildMode(): string
    {
        return implode(',', $this->getBuildModes());
    }

    /**
     * 兼容桥接：单值/旧格式字符串 → 集合。
     */
    public function setBuildMode(string $mode): void
    {
        $this->setBuildModes(self::parseBuildModes($mode));
    }

    /**
     * 当前构建模式的来源：'database' | 'env'。
     */
    public function getBuildModeSource(): string
    {
        try {
            if ($this->getString('build_mode') !== null) {
                return 'database';
            }
        } catch (\Exception $e) {
            Log::exception($e);
        }
        return 'env';
    }

    // ─── 开关类设置 ───

    /**
     * custom_push 是否启用（独立开关，可与任何 build_mode 组合）。默认关闭。
     */
    public function getCustomPushEnabled(): bool
    {
        return $this->getBoolFlag('custom_push_enabled', false);
    }

    public function setCustomPushEnabled(bool $enabled): void
    {
        $this->setString('custom_push_enabled', $enabled ? '1' : '0');
    }

    /**
     * 过期 tag 清理开关。默认关闭：删除不可逆，需后台显式开启。
     */
    public function getStaleTagCleanupEnabled(): bool
    {
        return $this->getBoolFlag('stale_tag_cleanup_enabled', false);
    }

    public function setStaleTagCleanupEnabled(bool $enabled): void
    {
        $this->setString('stale_tag_cleanup_enabled', $enabled ? '1' : '0');
    }

    /**
     * 镜像 Tag 日志回填开关。默认关闭：回填属写操作，需后台显式开启。
     */
    public function getBackfillTagEnabled(): bool
    {
        return $this->getBoolFlag(AppConfig::SETTING_BACKFILL_TAG_ENABLED, false);
    }

    public function setBackfillTagEnabled(bool $enabled): void
    {
        $this->setString(AppConfig::SETTING_BACKFILL_TAG_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * 拉取式记录「镜像 Tag」日志兜底的推送成功关键字。空串回退默认 'digest'。
     */
    public function getTagLogKeyword(): string
    {
        $value = $this->getString(AppConfig::SETTING_TAG_LOG_KEYWORD);
        if ($value !== null) {
            $kw = trim($value);
            if ($kw !== '') {
                return $kw;
            }
        }
        return AppConfig::DEFAULT_TAG_LOG_KEYWORD;
    }

    public function setTagLogKeyword(string $keyword): void
    {
        $this->setString(AppConfig::SETTING_TAG_LOG_KEYWORD, trim($keyword));
    }

    /**
     * API 调用审计写入级别（all/warning/error/off）。非法存量值回退默认 'all'。
     */
    public function getApiAccessLogLevel(): string
    {
        $value = $this->getString(AppConfig::SETTING_API_ACCESS_LOG_LEVEL);
        $value = $value === null ? '' : strtolower(trim($value));
        return in_array($value, AppConfig::API_ACCESS_LOG_LEVELS, true)
            ? $value
            : AppConfig::DEFAULT_API_ACCESS_LOG_LEVEL;
    }

    public function setApiAccessLogLevel(string $level): void
    {
        $level = strtolower(trim($level));
        if (!in_array($level, AppConfig::API_ACCESS_LOG_LEVELS, true)) {
            throw new \InvalidArgumentException('非法的 API 日志写入级别: ' . $level);
        }
        $this->setString(AppConfig::SETTING_API_ACCESS_LOG_LEVEL, $level);
    }

    /**
     * 审计日志保留天数。非法存量值（非正整数/超上限）回退默认 90 天。
     */
    public function getApiAccessLogRetainDays(): int
    {
        $value = $this->getString(AppConfig::SETTING_API_ACCESS_LOG_RETAIN_DAYS);
        $days = $value === null ? 0 : (int)trim($value);
        if ($days < 1 || $days > AppConfig::MAX_API_ACCESS_LOG_RETAIN_DAYS) {
            return AppConfig::DEFAULT_API_ACCESS_LOG_RETAIN_DAYS;
        }
        return $days;
    }

    /** 保留天数是否已在 DB 显式设置（CLI 据此决定 DB 与 env 的优先级） */
    public function hasApiAccessLogRetainDays(): bool
    {
        return $this->getString(AppConfig::SETTING_API_ACCESS_LOG_RETAIN_DAYS) !== null;
    }

    public function setApiAccessLogRetainDays(int $days): void
    {
        if ($days < 1 || $days > AppConfig::MAX_API_ACCESS_LOG_RETAIN_DAYS) {
            throw new \InvalidArgumentException(
                '非法的 API 日志保留天数: ' . $days . '（允许 1-' . AppConfig::MAX_API_ACCESS_LOG_RETAIN_DAYS . '）'
            );
        }
        $this->setString(AppConfig::SETTING_API_ACCESS_LOG_RETAIN_DAYS, (string)$days);
    }

    /**
     * 审计日志定时清理开关。默认开启（保持既有每日清理行为），显式存 '0' 才关闭。
     */
    public function getApiAccessLogCleanupEnabled(): bool
    {
        return $this->getBoolFlag(AppConfig::SETTING_API_ACCESS_LOG_CLEANUP_ENABLED, true);
    }

    public function setApiAccessLogCleanupEnabled(bool $enabled): void
    {
        $this->setString(AppConfig::SETTING_API_ACCESS_LOG_CLEANUP_ENABLED, $enabled ? '1' : '0');
    }
}
