<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Controller\BaseController;
use App\Exceptions\ApiException;
use App\Helper\ClientIp;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 所有 Admin 子控制器的共同骨架。
 *
 * 提供跨控制器复用的 5 个私有辅助方法（见 AdminController 原实现），
 * 以及 4 个构造依赖（I18nService 继承自 BaseController，Settings / PDO / AppSettingRepository
 * 由本类注入，子类构造时按需声明额外依赖）。
 *
 * 构造参数语义（子类必须从构造函数传入，不在 container.php 直接 new）：
 *   - I18nService         继承自 BaseController
 *   - Settings            所有 Admin 控制器都可能读 trusted_proxy_hops / build_mode 等
 *   - PDO                 opLog 懒加载 + invalidateTopologyCache
 *   - AppSettingRepository buildEntry 读 getCustomPushEnabled
 */
abstract class BaseAdminController extends BaseController
{
    protected Settings $config;
    protected \PDO $pdo;
    protected AppSettingRepository $appSettings;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        \PDO $pdo,
        AppSettingRepository $appSettings
    ) {
        parent::__construct($i18n);
        $this->config      = $config;
        $this->pdo         = $pdo;
        $this->appSettings = $appSettings;
    }

    /** 操作日志仓储（懒加载，写路径不阻塞主流程） */
    protected function opLog(): OperationLogRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new OperationLogRepository($this->pdo);
        }
        return $repo;
    }

    /** 取客户端真实 IP：REMOTE_ADDR 或 X-Forwarded-For 右端 */
    protected function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }

    /** 仅 super_admin 可访问（API Token 管理专属，独立于 RBAC 权限体系） */
    protected function requireSuperAdmin(Response $response): ?Response
    {
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'api_token.super_admin_only', 403);
        }
        return null;
    }

    /**
     * 构造 ci_job_git_map 行 payload
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    protected function buildEntry(array $body): array
    {
        $entry = [];
        $fields = ['job_name', 'git_platform', 'build_provider', 'git_remote', 'project_id', 'web_url', 'current_path', 'registry_repository', 'api_version', 'status'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $val = $body[$f];
                if ($f === 'build_provider' && trim((string) $val) === '') {
                    throw new ApiException('build_provider（构建源）不能为空，请指定该映射使用的 CI', 400);
                }
                if ($f === 'project_id' && ($val === '' || $val === null)) {
                    $entry[$f] = null;
                } elseif ($f === 'project_id' && is_numeric($val)) {
                    $entry[$f] = (int) $val;
                } else {
                    $entry[$f] = $val;
                }
            }
        }
        if (!isset($entry['job_name'])) {
            $entry['job_name'] = '';
        }

        foreach (['job_name', 'current_path', 'git_remote', 'registry_repository', 'web_url'] as $trimKey) {
            if (isset($entry[$trimKey]) && is_string($entry[$trimKey])) {
                $entry[$trimKey] = trim($entry[$trimKey]);
            }
        }

        if (
            ($entry['build_provider'] ?? '') === AppConfig::PROVIDER_CUSTOM_PUSH
            && !$this->appSettings->getCustomPushEnabled()
            && ($entry['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE
        ) {
            $entry['status'] = AppConfig::STATUS_PENDING;
        }
        return $entry;
    }

    /** 清除拓扑图缓存 */
    protected function invalidateTopologyCache(): void
    {
        try {
            $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key LIKE ?")
                ->execute([AppConfig::CACHE_KEY_MAP_LIST_PREFIX . '%']);
        } catch (\Exception $e) {
        }
    }
}
