<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Git\HostingType;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\PlatformConnectionRepository;
use App\Service\Settings;
use App\Service\TransactionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台各平台连接配置（CI 平台连接 / OIDC 客户端）。
 * 事务边界复用 TransactionManager，连接读写复用 PlatformConnectionRepository。
 */
class ConnectionController extends BaseAdminController
{
    private PlatformConnectionRepository $platformConnections;
    private MappingManager $mapping;
    private TransactionManager $tx;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        AppSettingRepository $appSettings,
        OperationLogRepository $operationLog,
        PlatformConnectionRepository $platformConnections,
        MappingManager $mapping,
        TransactionManager $tx
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->platformConnections = $platformConnections;
        $this->mapping             = $mapping;
        $this->tx                  = $tx;
    }

    /**
     * 平台连接配置的归一化负载（GET 与 PUT 返回复用同一份）。
     * 明文 token/password 永不下发，只给 *_set 布尔；url/username 可下发（非机密）。
     *
     * @return array<string,mixed>
     */
    private function platformConnectionsPayload(): array
    {
        $resolved = $this->platformConnections->resolveConnections();
        $rows = [];
        foreach ($this->platformConnections->list() as $row) {
            $p = trim((string)($row['platform'] ?? ''));
            if ($p !== '') {
                $rows[$p] = $row;
            }
        }

        $gitCiPlatforms = $this->appSettings->getGitCiEnabledPlatforms();
        $jenkinsEnabled = $this->appSettings->getJenkinsEnabled();

        $platforms = [];
        foreach (['jenkins', 'gitlab', 'github', 'gitee', 'gitea', 'harbor'] as $p) {
            $cfg = $resolved[$p] ?? [];
            $row = $rows[$p] ?? null;

            if ($p === 'harbor') {
                $url         = (string)($cfg['url'] ?? '');
                $tokenSet    = false;
                $username    = (string)($cfg['username'] ?? '');
                $passwordSet = trim((string)($row['password'] ?? '')) !== '';
                $configured  = $url !== '' && ($this->appSettings->getRegistryType() === 'registry' || $passwordSet);
            } elseif ($p === 'jenkins') {
                $url         = (string)($cfg['url'] ?? '');
                $tokenSet    = trim((string)($row['token'] ?? '')) !== '';
                $username    = (string)($cfg['user'] ?? '');
                $passwordSet = false;
                $configured  = $url !== '';
            } else {
                $url         = (string)($cfg['base_url'] ?? '');
                $tokenSet    = trim((string)($row['token'] ?? '')) !== '';
                $username    = '';
                $passwordSet = false;
                $configured  = $url !== '' && $tokenSet;
            }

            $platforms[] = [
                'platform'     => $p,
                'url'          => $url,
                'token_set'    => $tokenSet,
                'username'     => $username,
                'password_set' => $passwordSet,
                'description'  => (string)($row['description'] ?? ''),
                'registry_type' => $p === 'harbor' ? $this->appSettings->getRegistryType() : null,
                'configured'   => $configured,
                'hosting'      => in_array($p, AppConfig::GIT_PLATFORMS, true) ? HostingType::classify($url) : null,
                'ci_capable'   => $p === 'jenkins' || isset(AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$p]),
                'ci_enabled'   => $p === 'jenkins' ? $jenkinsEnabled : in_array($p, $gitCiPlatforms, true),
            ];
        }

        return ['platforms' => $platforms];
    }

    /**
     * GET /api/admin/platform_connections — 各平台连接配置（url/username 明文，token/password 只回 *_set）
     * 权限：ci.platform-config。
     */
    public function platformConnections(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->platformConnectionsPayload(), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/platform_connections — 更新各平台连接配置（DB 唯一权威，无 env 兜底）
     * 权限：ci.platform-config。只处理请求体显式携带的键；token/password 留空即保持原值。
     * delete:true 删除行（回到未配置）；url 与凭证均空也删除行；有凭证但无 URL 拒绝（400）。
     */
    public function updatePlatformConnections(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $valid = ['jenkins', 'gitlab', 'github', 'gitee', 'gitea', 'harbor'];
        $gitCiPlatforms = $this->appSettings->getGitCiEnabledPlatforms();
        $gitCiTouched = false;
        $jenkinsEnabled = $this->appSettings->getJenkinsEnabled();
        $jenkinsTouched = false;
        $removedProviders = [];
        $this->tx->begin();
        try {
            if (isset($body['platforms']) && is_array($body['platforms'])) {
                foreach ($body['platforms'] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $platform = trim((string)($p['platform'] ?? ''));
                    if (!in_array($platform, $valid, true)) {
                        continue;
                    }

                    $switchedToRegistry = false;
                    if ($platform === 'harbor' && array_key_exists('registry_type', $p)) {
                        $newType = (string)($p['registry_type'] ?? 'harbor');
                        if ($newType === 'registry' && $this->appSettings->getRegistryType() !== 'registry') {
                            $switchedToRegistry = true;
                        }
                        $this->appSettings->setRegistryType($newType);
                    }

                    if ($platform === AppConfig::PROVIDER_JENKINS) {
                        if (!empty($p['delete'])) {
                            if ($jenkinsEnabled) {
                                $jenkinsEnabled = false;
                                $jenkinsTouched = true;
                                $removedProviders[] = AppConfig::PROVIDER_JENKINS;
                            }
                        } elseif (array_key_exists('ci_enabled', $p)) {
                            $ciOn = !empty($p['ci_enabled']);
                            if ($ciOn !== $jenkinsEnabled) {
                                $jenkinsEnabled = $ciOn;
                                $jenkinsTouched = true;
                                if (!$ciOn) {
                                    $removedProviders[] = AppConfig::PROVIDER_JENKINS;
                                }
                            }
                        }
                    }

                    if (isset(AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform])) {
                        $ciProvider = AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform];
                        if (!empty($p['delete'])) {
                            if (in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms = array_values(array_diff($gitCiPlatforms, [$platform]));
                                $gitCiTouched = true;
                                $removedProviders[] = $ciProvider;
                            }
                        } elseif (array_key_exists('ci_enabled', $p)) {
                            $ciOn = !empty($p['ci_enabled']);
                            if ($ciOn && !in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms[] = $platform;
                                $gitCiTouched = true;
                            } elseif (!$ciOn && in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms = array_values(array_diff($gitCiPlatforms, [$platform]));
                                $gitCiTouched = true;
                                $removedProviders[] = $ciProvider;
                            }
                        }
                    }
                    if (!empty($p['delete'])) {
                        $this->platformConnections->delete($platform);
                        continue;
                    }
                    $existing = $this->platformConnections->find($platform);
                    $url      = trim((string)($p['url'] ?? ''));
                    $username = trim((string)($p['username'] ?? ''));
                    $token    = (string)($existing['token'] ?? '');
                    $password = (string)($existing['password'] ?? '');
                    if (array_key_exists('token', $p)) {
                        $t = trim((string)($p['token'] ?? ''));
                        if ($t !== '') {
                            $token = $t;
                        }
                    }
                    if (array_key_exists('password', $p)) {
                        $pw = (string)($p['password'] ?? '');
                        if (trim($pw) !== '') {
                            $password = $pw;
                        }
                    }

                    // 不再因切换 registry_type 而清空凭据：harbor 行在两种模式下复用同一连接，
                    // 切换类型只改变访问协议，不应丢弃凭据，否则切回时会因凭据丢失而 401。
                    if ($url === '' && $token === '' && $password === '') {
                        $this->platformConnections->delete($platform);
                        continue;
                    }
                    if ($url === '') {
                        throw new \InvalidArgumentException('平台连接 [' . $platform . '] 填写了凭证但 URL 为空');
                    }
                    $description = (string)($existing['description'] ?? '');
                    if (array_key_exists('description', $p)) {
                        $description = trim((string)($p['description'] ?? ''));
                    }
                    $this->platformConnections->save($platform, [
                        'url'         => $url,
                        'token'       => $token,
                        'username'    => $username,
                        'password'    => $password,
                        'description' => $description,
                    ]);
                }
            }
            if ($gitCiTouched) {
                $this->appSettings->setGitCiEnabledPlatforms($gitCiPlatforms);
            }
            if ($jenkinsTouched) {
                $this->appSettings->setJenkinsEnabled($jenkinsEnabled);
            }


            if ($removedProviders !== []) {
                $removedProviders = array_values(array_unique($removedProviders));
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    $bp = (string) ($m['build_provider'] ?? '');
                    if ($bp === '') {
                        continue;
                    }
                    if (in_array($bp, $removedProviders, true) && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->mapping->invalidateMapListCache();
                }
            }
            $this->opLog()->record($this->currentUser, 'platform_connections_update', '', [], $this->clientIp($request), 'success');
            $this->tx->commit();
        } catch (\InvalidArgumentException $e) {
            if ($this->tx->inTransaction()) {
                $this->tx->rollBack();
            }
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            if ($this->tx->inTransaction()) {
                $this->tx->rollBack();
            }
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, $this->platformConnectionsPayload(), $request);
    }
}
