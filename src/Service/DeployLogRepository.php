<?php

namespace App\Service;

use GuzzleHttp\Client;

/**
 * CD 部署记录只读审计仓储（经 CD 的 /api/integration/deploy-logs 只读端点拉取，不直读共享库视图）。
 *
 * 设计原则：
 *  - 只读：仅提供分页列表，不提供写入/删除。
 *  - 审计字段：只取契约字段内的审计列，不含 output/stage_times 等大字段。
 *  - 容错：CD 未配置、网络异常、HTTP 非 200 或响应非法都不抛 500，返回空集且 available=false，
 *    由前端展示占位；但首次失败会记一条 warning 文件日志（含原因与目标 URL），把「CD 不可达」
 *    与「确实没数据」区分开——否则页面只剩一句占位提示，故障原因无从查起。
 *
 * 端点由 Devops-CD 提供并维护（CI 侧只读、从不写 CD 库）。鉴权用独立入站静态 token CD_API_TOKEN
 * （Authorization: Bearer），不复用 ci_api_token——CI 只存 sha256 摘要、运行时拿不到明文。
 */
class DeployLogRepository
{
    /** 契约端点暴露给 CI 的审计列（10 列，与 CD 侧一致；其余大字段一律不带出） */
    private const AUDIT_COLUMNS = [
        'id', 'project', 'tag', 'image', 'deploy_type', 'target', 'status', 'triggered_by', 'deploy_note', 'created_at',
    ];

    /** CD 端点不可用的 warning 是否已记过（每进程一次，避免列表页反复刷新把日志刷爆） */
    private static bool $unavailableLogged = false;

    private string $baseUrl;
    private string $token;
    private Client $http;

    public function __construct(string $baseUrl, string $token, ?Client $http = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->http    = $http ?? new Client([
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json'],
            'timeout'         => 10,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * 分页查询，支持筛选：project（模糊）、status、deploy_type、date_from/date_to（含边界）。
     * 未传日期时由 CD 侧默认近 30 天，避免全量拉取。
     *
     * @param array<string,mixed> $filters
     * @return array{
     *     available: bool,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int,
     *     items: list<array<string,mixed>>
     * }
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if ($this->baseUrl === '' || $this->token === '') {
            return $this->unavailable($page, $perPage, 'CD_BASE_URL/CD_API_TOKEN 未配置');
        }

        $query = array_filter([
            'project'     => $filters['project'] ?? null,
            'status'      => $filters['status'] ?? null,
            'deploy_type' => $filters['deploy_type'] ?? null,
            'date_from'   => $filters['date_from'] ?? null,
            'date_to'     => $filters['date_to'] ?? null,
            'page'        => max(1, $page),
            'per_page'    => max(1, min(100, $perPage)),
        ], static fn ($v): bool => $v !== null && $v !== '');

        try {
            $resp = $this->http->get($this->baseUrl . '/api/integration/deploy-logs', [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
                'query'   => $query,
            ]);

            if ($resp->getStatusCode() !== 200) {
                return $this->unavailable($page, $perPage, 'HTTP ' . $resp->getStatusCode());
            }

            $data = json_decode((string) $resp->getBody(), true);
            if (!is_array($data)) {
                return $this->unavailable($page, $perPage, '响应非 JSON');
            }

            $items = $data['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            // 白名单：只带契约审计列，防 CD 未来多返回 output/stage_times 等大字段时被透传到审计页
            $whitelist = array_flip(self::AUDIT_COLUMNS);
            $items = array_map(
                static function ($row) use ($whitelist) {
                    return is_array($row) ? array_intersect_key($row, $whitelist) : $row;
                },
                $items
            );

            $total       = (int) ($data['total'] ?? 0);
            $respPage    = (int) ($data['page'] ?? $page);
            $respPerPage = (int) ($data['per_page'] ?? $perPage);
            $totalPages  = max(1, (int) ($data['total_pages'] ?? (int) ceil($total / max(1, $respPerPage))));

            return [
                'available'   => true,
                'total'       => $total,
                'page'        => $respPage,
                'per_page'    => $respPerPage,
                'total_pages' => $totalPages,
                'items'       => array_values($items),
            ];
        } catch (\Throwable $e) {
            return $this->unavailable($page, $perPage, $e->getMessage());
        }
    }

    /**
     * 降级为空集（available=false），首次失败记一条 warning，之后静默。
     */
    private function unavailable(int $page, int $perPage, string $reason): array
    {
        if (!self::$unavailableLogged) {
            self::$unavailableLogged = true;
            \App\Helper\Log::warning('[部署日志] CD 端点不可用，部署日志审计降级为占位提示', [
                'reason' => $reason,
                'url'    => $this->baseUrl . '/api/integration/deploy-logs',
            ]);
        }
        return [
            'available'   => false,
            'total'       => 0,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => 1,
            'items'       => [],
        ];
    }
}
