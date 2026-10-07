<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * 只读运行时配置：封装 settings.php / env 合并后的配置数组。
 *
 * 从 AppConfig 迁出。职责边界：
 *   - 本类只读 $config 数组（连接信息、开关、LDAP、CORS 等纯配置）；
 *   - DB 读写（build_mode、custom_push、平台版本覆盖、job 映射）分别归
 *     {@see AppSettingRepository} / {@see PlatformVersionRepository} / {@see MappingManager}；
 *   - AppConfig 仅保留常量与目录数据。
 *
 * 平台 API 版本的「DB 覆盖 + settings.php 最高优先级」合并保留在本类
 * （getGitPlatformsConfig/getHarborApiInfo 需要消费合并结果），DB 行的读写仍在 PlatformVersionRepository。
 */
class Settings
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private array $config,
        private ?PlatformVersionRepository $platformVersions = null
    ) {
    }

    // Jenkins
    /** @return array<string,string> */
    public function getJenkinsConfig(): array
    {
        return [
            'url'   => $this->config['jenkins']['url'] ?? 'http://localhost:8083',
            'user'  => $this->config['jenkins']['user'] ?? '',
            'token' => $this->config['jenkins']['token'] ?? '',
        ];
    }

    // GitLab 配置
    /** @return array<string,mixed> */
    public function getGitlabConfig(): array
    {
        return $this->config['git']['gitlab'] ?? [];
    }

    // Gitee 配置
    /** @return array<string,mixed> */
    public function getGiteeConfig(): array
    {
        return $this->config['git']['gitee'] ?? [];
    }

    // GitHub 配置
    /** @return array<string,mixed> */
    public function getGithubConfig(): array
    {
        return $this->config['git']['github'] ?? [];
    }

    // Gitea 配置
    /** @return array<string,mixed> */
    public function getGiteaConfig(): array
    {
        return $this->config['git']['gitea'] ?? [];
    }

    // 应用环境
    public function getAppEnv(): string
    {
        return $this->config['app']['env'] ?? 'production';
    }

    // 日志路径
    public function getLogPath(): string
    {
        return $this->config['app']['log_path'] ?? '';
    }

    // 是否调试模式（对应 APP_DEBUG）。true 时输出全量日志（含关键操作成功/失败），false 只留 error
    public function isDebug(): bool
    {
        return !empty($this->config['app']['debug']);
    }

    // API 外部访问地址（用于 Swagger UI / OpenAPI，不设返回空字符串由调用方自动推导）
    public function getApiBaseUrl(): string
    {
        return $this->config['app']['api_base_url'] ?? '';
    }

    // 当前实例系统类型：ci / cd / both
    public function getSystemType(): string
    {
        $type = $this->config['app']['system_type'] ?? AppConfig::SYSTEM_CI;
        return in_array($type, [AppConfig::SYSTEM_CI, AppConfig::SYSTEM_CD, AppConfig::SYSTEM_BOTH]) ? $type : AppConfig::SYSTEM_CI;
    }

    // 可信反向代理跳数（0=直连不信任 XFF；反代后置 1）
    public function getTrustedProxyHops(): int
    {
        return max(0, (int) ($this->config['app']['trusted_proxy_hops'] ?? 0));
    }

    // CORS 配置
    /** @return array{allowed_origins?: list<string>, allowed_methods?: list<string>, allowed_headers?: list<string>} */
    public function getCorsConfig(): array
    {
        return $this->config['cors'] ?? ['allowed_origins' => ['*']];
    }

    // Harbor
    /** @return array<string,mixed> */
    public function getHarborConfig(): array
    {
        return $this->config['harbor'] ?? [];
    }

    /**
     * 判断当前配置的 Harbor 账号是否为机器人账户（用户名含 'robot$' 前缀）。
     * Harbor v2.2.0 之前机器人账户是 JWT，无法调用 REST API，仅 Docker/Helm CLI 可用。
     */
    public function isHarborRobotAccount(): bool
    {
        $username = $this->config['harbor']['username'] ?? '';
        return str_contains($username, 'robot$');
    }

    /**
     * 获取用户自定义 Git Provider 列表
     * @return list<array{class?: string, config?: array<string,mixed>}> 每个元素包含 class (完整类名) 和 config (构造参数数组)
     */
    public function getCustomGitProviders(): array
    {
        return $this->config['git']['custom_providers'] ?? [];
    }

    /**
     * 获取用户自定义 Build Provider 列表（custom_push 等推送式 CI）
     * 与 Git 自定义平台解耦：独立配置项 build.custom_providers，不放在 git 下。
     * @return list<array{name: string, class: string, config: array<string,mixed>}> 每个元素包含 name (注册名), class (完整类名) 和 config (构造参数数组)
     */
    public function getCustomBuildProviders(): array
    {
        return $this->config['build']['custom_providers'] ?? [];
    }

    // getGitPlatformsConfig 方法
    /** @return list<array<string,mixed>> */
    public function getGitPlatformsConfig(): array
    {
        $platforms = [];
        $gitConfig = $this->config['git'] ?? [];
        $versions  = $this->getPlatformApiVersions();

        // 内置平台
        foreach (['gitlab', 'gitee', 'github', 'gitea'] as $name) {
            $cfg = $gitConfig[$name] ?? [];
            // 有 base_url 或 api_base_url 任一非空即认为已配置
            if (!empty($cfg['base_url']) || !empty($cfg['api_base_url'])) {
                $baseUrl = $cfg['api_base_url'] ?? $cfg['base_url'];
                $version = $cfg['api_version'] ?? ($versions[$name] ?? $this->getDefaultApiVersion($name));

                // 拼接 API 版本路径（GitHub 除外：版本通过 HTTP header 传递）
                if ($name !== 'github') {
                    $expectedPath = '/api/' . $version;
                    if (strpos($baseUrl, $expectedPath) === false) {
                        $baseUrl = rtrim($baseUrl, '/') . $expectedPath;
                    }
                }

                $platforms[] = [
                    'name'         => $name,
                    'api_base_url' => $baseUrl,
                    'api_version'  => $version,
                ];
            }
        }

        // 自定义平台
        foreach ($this->getCustomGitProviders() as $provider) {
            $class = $provider['class'] ?? '';
            $cfg   = $provider['config'] ?? [];
            if (empty($class)) {
                continue;
            }

            $tail    = strrchr($class, '\\');
            $name    = $cfg['name'] ?? strtolower($tail === false ? $class : substr($tail, 1));
            $baseUrl = $cfg['api_base_url'] ?? $cfg['base_url'] ?? '';
            $version = $cfg['api_version'] ?? 'custom';

            $platforms[] = [
                'name'         => $name,
                'api_base_url' => $baseUrl,
                'api_version'  => $version,
            ];
        }

        return $platforms;
    }

    // 获取 Harbor 的 API 配置
    /** @return array{api_base_url: string, api_version: string} */
    public function getHarborApiInfo(): array
    {
        $harbor = $this->config['harbor'] ?? [];
        $baseUrl = rtrim($harbor['url'] ?? '', '/');
        $version = $harbor['api_version'] ?? ($this->getPlatformApiVersions()['harbor'] ?? 'v2.0');
        $expectedPath = '/api/' . $version;
        if (strpos($baseUrl, $expectedPath) === false) {
            $baseUrl .= $expectedPath;
        }
        return [
            'api_base_url' => $baseUrl,
            'api_version'  => $version,
        ];
    }

    // 按名称获取单个 Git 平台配置
    /** @return array<string,mixed> */
    public function getGitPlatformConfig(string $name): array
    {
        return $this->config['git'][$name] ?? [];
    }

    /**
     * URL 无法匹配时使用的默认平台名
     */
    public function getDefaultGitPlatform(): string
    {
        return $this->config['git']['default_platform'] ?? 'gitlab';
    }

    // 判断某个平台是否已在配置中（用于 discovery 对比）
    public function isPlatformConfigured(string $platformName): bool
    {
        $cfg = $this->config['git'][$platformName] ?? null;
        if (!$cfg) {
            return false;
        }
        return !empty($cfg['base_url']) || !empty($cfg['api_base_url']);
    }

    /**
     * 根管理员用户名（从 app.env ADMIN_USER 读取，默认 'admin'）
     * 这是唯一的根账号标识，所有权限判断都从这里取，不散落写死
     */
    public function getRootAdminUser(): string
    {
        // 统一小写：与登录输入、建号、seedAdminFromEnv 的规范化保持一致，
        // 否则根账号保护比对（===）在大小写不一致时会被绕过
        return strtolower($this->config['admin']['user'] ?? 'admin');
    }

    /**
     * 管理后台登录凭证（从 app.env 读取）
     *
     * @return array{user: string, password: string}
     */
    public function getAdminCredentials(): array
    {
        return [
            'user'     => $this->getRootAdminUser(),
            'password' => $this->config['admin']['password'] ?? '',
        ];
    }

    /**
     * LDAP 身份源配置。
     * 仅在 settings.php 的 ldap.enabled=true 时启用；密码源、DN 模板、过滤等均从 app.env 读取。
     *
     * @return array{enabled: bool, host?: string, port?: int, use_tls?: bool, use_ldaps?: bool,
     *               base_dn?: string, bind_dn?: string, bind_password?: string,
     *               user_filter?: string, user_dn_pattern?: string,
     *               attrs?: list<string>, network_timeout?: int}
     */
    public function getLdapConfig(): array
    {
        $cfg = $this->config['ldap'] ?? [];
        $enabled = (bool)($cfg['enabled'] ?? false);
        if (!$enabled) {
            return ['enabled' => false];
        }
        $rawAttrs = $cfg['attrs'] ?? null;
        return [
            'enabled'         => true,
            'host'            => (string)($cfg['host'] ?? ''),
            'port'            => (int)($cfg['port'] ?? 389),
            'use_tls'         => (bool)($cfg['use_tls'] ?? false),  // LDAP_CONNECT 之后 STARTTLS
            'use_ldaps'       => (bool)($cfg['use_ldaps'] ?? false), // ldap_connect 时直接用 ldaps://
            'base_dn'         => (string)($cfg['base_dn'] ?? ''),
            'bind_dn'         => (string)($cfg['bind_dn'] ?? ''),   // 先以管理员绑定搜索用户 DN，再切回用户密码校验
            'bind_password'   => (string)($cfg['bind_password'] ?? ''),
            'user_filter'     => (string)($cfg['user_filter'] ?? '(uid=%s)'), // %s → 用户名
            'user_dn_pattern' => (string)($cfg['user_dn_pattern'] ?? ''),     // 若已固定 DN 模板（如 uid=%s,ou=users,dc=x），跳过管理员搜索
            'attrs'           => is_array($rawAttrs)
                ? array_values(array_map('strval', $rawAttrs))
                : ['uid', 'cn', 'mail', 'dn'],
            'network_timeout' => (int)($cfg['network_timeout'] ?? 5),
        ];
    }

    // ──────────────────── 平台 API 版本 ────────────────────

    /**
     * 获取所有平台的 API 版本（settings.php 显式配置 > DB 覆盖 > 默认值）
     *
     * @return array<string,string>
     */
    public function getPlatformApiVersions(): array
    {
        $enriched = $this->getPlatformApiVersionsWithSource();
        $result = [];
        foreach ($enriched as $name => $info) {
            $result[$name] = $info['value'];
        }
        return $result;
    }

    /**
     * 获取版本号 + 来源标识（供管理界面展示）
     * source: 'config'   = settings.php 显式配置（最高优先级，UI 只读）
     *         'json'     = DB 覆盖（管理界面可改）
     *         'default'  = 系统硬编码默认值（管理界面可覆盖）
     *
     * @return array<string, array{value:string, source:string}>
     */
    public function getPlatformApiVersionsWithSource(): array
    {
        $result = $this->platformVersions !== null
            ? $this->platformVersions->allWithSource()
            : $this->defaultVersionsWithSource();

        // settings.php 显式配置优先级最高
        foreach (['gitlab', 'gitee', 'github', 'gitea'] as $name) {
            $cfg = $this->config['git'][$name] ?? [];
            if (!empty($cfg['api_version'])) {
                $result[$name] = ['value' => $cfg['api_version'], 'source' => 'config'];
            }
        }
        if (!empty($this->config['harbor']['api_version'])) {
            $result['harbor'] = ['value' => $this->config['harbor']['api_version'], 'source' => 'config'];
        }

        return $result;
    }

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return PlatformVersionRepository::DEFAULT_API_VERSIONS[$platform] ?? 'unknown';
    }

    /** @return array<string, array{value:string, source:string}> */
    private function defaultVersionsWithSource(): array
    {
        $result = [];
        foreach (PlatformVersionRepository::DEFAULT_API_VERSIONS as $name => $default) {
            $result[$name] = ['value' => $default, 'source' => 'default'];
        }
        return $result;
    }
}
