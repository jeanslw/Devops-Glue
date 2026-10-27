<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AutoDiscover;
use App\Service\MappingManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台项目自动发现与 CI/镜像映射管理。
 * 方法体从 AdminController 原样搬迁。
 */
class MappingController extends BaseAdminController
{
    private MappingManager $mapping;
    private ?AutoDiscover $autoDiscover;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        MappingManager $mapping,
        ?AutoDiscover $autoDiscover = null
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->mapping       = $mapping;
        $this->autoDiscover  = $autoDiscover;
    }

    /** POST /api/admin/discover — 自动扫描并保存未入库的项目 */
    public function discover(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_DISCOVER)) {
            return $resp;
        }
        if (!$this->autoDiscover) {
            return $this->jsonError($response, 'map.discover_disabled', 503);
        }
        try {
            $result = $this->autoDiscover->discover();
            $found = $result['found'];
            $errors  = $result['errors'];
            $sources = $result['sources'];
            $saved = $this->autoDiscover->saveDiscovered($found);
            if ($saved > 0) {
                $this->invalidateTopologyCache();
            }
            $this->opLog()->record($this->currentUser, 'auto_discover', '', ['found' => count($found), 'saved' => $saved], $this->clientIp($request), 'success');
            return $this->output($response, [
                'found' => count($found),
                'saved' => $saved,
                'errors' => $errors,
                'sources' => $sources,
                'items' => array_map(function ($i) {
                    $entry = is_array($i['entry'] ?? null) ? $i['entry'] : [];
                    return $entry['job_name'] ?? null;
                }, $found),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.scan_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/job_git_map — 列出所有映射（支持搜索/筛选/分页）
     */
    public function jobGitMapList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $params = array_merge(
            $request->getQueryParams(),
            $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? []
        );
        $search  = trim($params['search'] ?? '');
        $platform = trim($params['platform'] ?? '');
        $provider = trim($params['provider'] ?? '');
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        $allMaps = $this->mapping->allMaps();
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $platformNames = array_map(fn($p) => $p['name'], $gitPlatforms);

        $filtered = $allMaps;
        if ($search !== '') {
            $s = mb_strtolower($search);
            $filtered = array_filter($filtered, function ($m) use ($s) {
                foreach (['job_name','git_remote','current_path','registry_repository'] as $f) {
                    if (mb_strpos(mb_strtolower($m[$f] ?? ''), $s) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }
        if ($platform !== '') {
            $filtered = array_filter($filtered, fn($m) => ($m['git_platform'] ?? '') === $platform);
        }
        if ($provider !== '') {
            $filtered = array_filter($filtered, fn($m) => (string) ($m['build_provider'] ?? '') === $provider);
        }

        $filtered = array_values($filtered);
        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);

        $offset = ($page - 1) * $perPage;
        $pagedMaps = array_slice($filtered, $offset, $perPage);

        return $this->output($response, [
            'maps'        => $pagedMaps,
            'platforms'   => $platformNames,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ], $request);
    }

    /**
     * POST /api/admin/job_git_map — 新增一条映射
     */
    public function jobGitMapSave(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $jobName = trim($body['job_name'] ?? '');
        if ($jobName === '') {
            return $this->jsonError($response, 'map.job_name_required', 400);
        }

        if (trim((string) ($body['build_provider'] ?? '')) === '') {
            return $this->jsonError($response, 'map.build_provider_required', 400);
        }

        foreach ($this->mapping->allMaps() as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                return $this->jsonError($response, $this->__('map.already_exists', ['{name}' => $jobName]), 409);
            }
        }

        $entry = $this->buildEntry($body);
        $this->mapping->saveMap($entry);
        $this->invalidateTopologyCache();

        $this->opLog()->record($this->currentUser, 'create_mapping', $jobName, [], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true, 'entry' => $entry], $request);
    }

    /**
     * PUT /api/admin/job_git_map — 更新一条映射（按 job_name 匹配）
     */
    public function jobGitMapUpdate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $oldName = trim($body['_original_job_name'] ?? '');
        if ($oldName === '') {
            return $this->jsonError($response, 'map.original_name_required', 400);
        }

        $found  = false;
        $oldRow = [];
        foreach ($this->mapping->allMaps() as $item) {
            if (($item['job_name'] ?? '') === $oldName) {
                $found  = true;
                $oldRow = $item;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $oldName]), 404);
        }

        $updatedEntry = $this->buildEntry($body);
        $newName      = trim((string) ($updatedEntry['job_name'] ?? ''));
        if ($newName === '') {
            return $this->jsonError($response, 'map.job_name_required', 400);
        }
        $merged = array_merge($oldRow, $updatedEntry);
        $this->mapping->saveMap($merged);
        if ($newName !== $oldName) {
            $this->mapping->deleteMap($oldName);
        }
        $this->invalidateTopologyCache();
        $this->opLog()->record($this->currentUser, 'update_mapping', $oldName, ['job_name' => $merged['job_name'] ?? $oldName], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true, 'entry' => $merged], $request);
    }

    /**
     * DELETE /api/admin/job_git_map?job_name=xxx — 删除一条映射
     */
    public function jobGitMapDelete(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $jobName = trim($request->getQueryParams()['job_name'] ?? '');
        if ($jobName === '') {
            return $this->jsonError($response, 'map.job_name_required_param', 400);
        }

        $maps = $this->mapping->allMaps();
        $found = false;
        foreach ($maps as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $jobName]), 404);
        }

        $this->mapping->deleteMap($jobName);
        $this->invalidateTopologyCache();
        $this->opLog()->record($this->currentUser, 'delete_mapping', $jobName, [], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true], $request);
    }
}
