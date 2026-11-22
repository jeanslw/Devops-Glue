<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use App\Service\WritebackQueueRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台：异步回写队列监控与死信重投。
 */
final class WritebackQueueController extends BaseAdminController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        AppSettingRepository $appSettings,
        OperationLogRepository $operationLog,
        private WritebackQueueRepository $queue
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
    }

    /** 队列列表 + 状态统计。 */
    public function list(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_SYSTEM)) {
            return $resp;
        }
        $params = array_merge(
            $request->getQueryParams(),
            is_array($request->getParsedBody()) ? $request->getParsedBody() : []
        );
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));
        $filters = [
            'status' => (string)($params['status'] ?? ''),
            'git_platform' => (string)($params['git_platform'] ?? ''),
            'q' => (string)($params['q'] ?? ''),
        ];
        try {
            return $this->output($response, $this->queue->search($filters, $page, $perPage), $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * 重投一条死信任务。
     *
     * @param array<string,string> $args
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_SYSTEM)) {
            return $resp;
        }
        $id = (string)($args['id'] ?? '');
        if (!ctype_digit($id)) {
            return $this->jsonError($response, $this->__('build.writeback_retry_invalid_id'), 400);
        }
        try {
            $ok = $this->queue->requeueDead($id);
            $this->opLog()->record(
                $this->currentUser,
                'writeback_retry',
                $id,
                ['ok' => $ok],
                $this->clientIp($request),
                $ok ? 'success' : 'failure'
            );
            if (!$ok) {
                return $this->jsonError($response, $this->__('build.writeback_retry_not_dead'), 409);
            }
            return $this->output($response, ['success' => true, 'id' => $id], $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, $this->__('build.writeback_retry_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
