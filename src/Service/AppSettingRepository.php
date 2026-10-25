<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;

/**
 * ci_app_settings 键值仓储：应用运行时开关与配置（以 DB 为准）。
 *
 * 承载从 AppConfig 迁出的 DB 读写职责：
 *   - build_mode（启用的独立拉取式 provider 集合，即 jenkins；含旧格式转换）
 *   - git_ci_enabled（git 平台 CI 能力启用态，与 build_mode 二元拆分）
 *   - custom_push_enabled / stale_tag_cleanup_enabled / backfill_tag_enabled
 *   - tag_log_keyword（推送成功关键字）
 *   - registry_type（镜像仓库类型：harbor 默认 | registry，标准 OCI Distribution）
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

    private function deleteString(string $key): void
    {
        $this->pdo->prepare('DELETE FROM ' . AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key = ?')
            ->execute([$key]);
    }

    private function getBoolFlag(string $key, bool $default): bool
    {
        $value = $this->getString($key);
        if ($value === null) {
            return $default;
        }
        return $value === '1';
    }

    // ─── 镜像仓库类型（全局单一值，DB 唯一来源） ───

    /**
     * 镜像仓库类型：harbor（默认）| registry（标准 OCI Distribution，如 registry:2）。
     * 未设置（历史库）回退 harbor。
     */
    public function getRegistryType(): string
    {
        $value = $this->getString('registry_type');
        return $value === 'registry' ? 'registry' : 'harbor';
    }

    /**
     * 设置镜像仓库类型：仅 harbor|registry，其余值归一为 harbor。
     */
    public function setRegistryType(string $type): void
    {
        $this->setString('registry_type', $type === 'registry' ? 'registry' : 'harbor');
    }

    // ─── 构建系统模式（数据库为唯一来源） ───

    /**
     * 获取启用的构建 provider 集合（主 API）。
     * 逻辑：读取 DB build_mode 并解析为集合返回。若 DB 无记录（首次运行），
     * 从 app.env BUILD_MODE 取种子值写入 DB 后返回。此后以 DB 为准。
     *
     * 旧格式（jenkins / gitlab_ci / both 单值）在读取时转换为新格式并回写。
     *
     * @return list<string> 已启用的拉取式 provider（jenkins/gitlab_ci/gitea_ci），可为空数组
     */
    public function getBuildModes(): array
    {
        try {
            $stored = $this->getString('build_mode');
            if ($stored !== null) {
                $this->migrateLegacyGitCi();
                return $this->mergeBuildModes();
            }

            $modes = self::parseBuildModes($_ENV['BUILD_MODE'] ?? AppConfig::BUILD_MODE_BOTH);
            try {
                $this->setBuildModes($modes);
            } catch (\Exception $e) {
            }
            return $modes;
        } catch (\Exception $e) {
            return self::parseBuildModes($_ENV['BUILD_MODE'] ?? AppConfig::BUILD_MODE_BOTH);
        }
    }

    /**
     * 解析 build_mode 值为规范化 provider 集合（旧格式 both/jenkins/gitlab_ci 兼容映射）。
     *
     * @return list<string>
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

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn($s) => $s !== ''));
        return array_values(array_intersect(AppConfig::BUILTIN_PULL_PROVIDERS, $parts));
    }

    /**
     * @param list<string> $modes 启用的拉取式 provider 集合（jenkins / gitlab_ci / gitea_ci）
     */
    public function setBuildModes(array $modes): void
    {

        $modes = array_values(array_intersect(AppConfig::BUILTIN_PULL_PROVIDERS, array_map('trim', $modes)));

        $standalone = array_values(array_intersect([AppConfig::PROVIDER_JENKINS], $modes));
        $gitCiProviders = array_values(array_intersect(AppConfig::GIT_PLATFORM_CI_CAPABILITIES, $modes));
        $platforms = [];
        foreach ($gitCiProviders as $ci) {
            $platform = self::gitCiPlatformByProvider($ci);
            if ($platform !== null) {
                $platforms[] = $platform;
            }
        }
        $this->setString('build_mode', implode(',', $standalone));
        $this->setGitCiEnabledPlatforms($platforms);
    }

    /**
     * 已启用 CI 能力的 git 平台名列表（见 GIT_PLATFORM_CI_CAPABILITIES 的键）。
     *
     * @return list<string>
     */
    public function getGitCiEnabledPlatforms(): array
    {
        $raw = $this->getString(AppConfig::SETTING_GIT_CI_ENABLED);
        if ($raw === null || $raw === '') {
            return [];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($s) => $s !== ''));
        return array_values(array_intersect(array_keys(AppConfig::GIT_PLATFORM_CI_CAPABILITIES), $parts));
    }

    /**
     * @param list<string> $platforms git 平台名（gitlab / gitea …）
     */
    public function setGitCiEnabledPlatforms(array $platforms): void
    {
        $platforms = array_values(array_intersect(
            array_keys(AppConfig::GIT_PLATFORM_CI_CAPABILITIES),
            array_map('trim', $platforms)
        ));
        $this->setString(AppConfig::SETTING_GIT_CI_ENABLED, implode(',', $platforms));
    }

    /**
     * 独立 CI（Jenkins）是否启用（build_mode 只存 jenkins）。
     */
    public function getJenkinsEnabled(): bool
    {
        return in_array(AppConfig::PROVIDER_JENKINS, $this->getBuildModes(), true);
    }

    public function setJenkinsEnabled(bool $enabled): void
    {
        $this->setString('build_mode', $enabled ? AppConfig::PROVIDER_JENKINS : '');
    }

    /**
     * CI provider → git 平台名（GIT_PLATFORM_CI_CAPABILITIES 是 平台→CI，这里反向查）。
     */
    private static function gitCiPlatformByProvider(string $provider): ?string
    {
        $flip = array_flip(AppConfig::GIT_PLATFORM_CI_CAPABILITIES);
        return $flip[$provider] ?? null;
    }

    /**
     * 一次性迁移：旧格式把 git 平台 CI 混在 build_mode 里（如 jenkins,gitlab_ci），
     * 且 git_ci_enabled 键尚未建立时，把 git CI 拆分到 git_ci_enabled，build_mode 只留独立 CI。
     */
    private function migrateLegacyGitCi(): void
    {
        if ($this->getString(AppConfig::SETTING_GIT_CI_ENABLED) !== null) {
            return;
        }
        $standalone = self::parseBuildModes($this->getString('build_mode') ?? '');
        $gitCi = array_values(array_intersect(AppConfig::GIT_PLATFORM_CI_CAPABILITIES, $standalone));
        $platforms = [];
        foreach ($gitCi as $ci) {
            $platform = self::gitCiPlatformByProvider($ci);
            if ($platform !== null) {
                $platforms[] = $platform;
            }
        }
        $this->setGitCiEnabledPlatforms($platforms);
        $this->setString('build_mode', implode(',', array_values(array_diff($standalone, $gitCi))));
    }

    /**
     * 合并独立 CI（build_mode）与 git 平台 CI（git_ci_enabled）为统一 provider 集合（规范顺序）。
     *
     * @return list<string>
     */
    private function mergeBuildModes(): array
    {
        $standalone = self::parseBuildModes($this->getString('build_mode') ?? '');
        $gitCi = [];
        foreach ($this->getGitCiEnabledPlatforms() as $platform) {
            if (isset(AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform])) {
                $gitCi[] = AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform];
            }
        }
        return array_values(array_intersect(AppConfig::BUILTIN_PULL_PROVIDERS, array_merge($standalone, $gitCi)));
    }

    /**
     * 兼容字符串：返回 join 出的规范串（供缓存 key / 汇总 / configMode 复用）。
     */
    public function getBuildMode(): string
    {
        return implode(',', $this->getBuildModes());
    }

    /**
     * 兼容旧格式：把单值或旧格式字符串解析为集合。
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
     * 拉取式记录镜像 Tag 日志时使用的推送成功关键字。空串回退默认值 'digest'。
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

    // ─── OIDC provider 配置（ci_app_settings 键；DB 唯一权威，不读 env、不自动种子化）──

    /**
     * OIDC issuer（null = DB 未设置，由控制器/服务从请求推导）。
     * 统一 rtrim('/')，与 OidcService::issuer() 一致，避免尾斜杠造成相等性漂移。
     */
    public function getOidcIssuer(): ?string
    {
        $value = $this->getString(AppConfig::SETTING_OIDC_ISSUER);
        if ($value === null) {
            return null;
        }
        $issuer = rtrim(trim($value), '/');
        return $issuer === '' ? null : $issuer;
    }

    /**
     * 设置 OIDC issuer；空串清除 DB 行（回退请求推导）。
     */
    public function setOidcIssuer(string $issuer): void
    {
        $issuer = rtrim(trim($issuer), '/');
        if ($issuer === '') {
            $this->deleteString(AppConfig::SETTING_OIDC_ISSUER);
            return;
        }
        $this->setString(AppConfig::SETTING_OIDC_ISSUER, $issuer);
    }

    /**
     * OIDC id_token 有效期（秒）。null = 未设置或非法（回退代码默认 DEFAULT_OIDC_ID_TOKEN_TTL）。
     */
    public function getOidcIdTokenTtl(): ?int
    {
        $value = $this->getString(AppConfig::SETTING_OIDC_ID_TOKEN_TTL);
        if ($value === null) {
            return null;
        }
        $ttl = (int)trim($value);
        return $ttl > 0 ? $ttl : null;
    }

    public function setOidcIdTokenTtl(int $ttl): void
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('非法的 OIDC id_token 有效期: ' . $ttl);
        }
        $this->setString(AppConfig::SETTING_OIDC_ID_TOKEN_TTL, (string)$ttl);
    }
}
