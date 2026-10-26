<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\I18nService;
use App\Config\AppConfig;

class BaseController
{
    protected string $currentUser = '';
    protected string $currentRole = AppConfig::ROLE_ADMIN;
    /** @var list<string> 用户权限键列表 */
    protected array $userPermissions = [];

    public function __construct(protected I18nService $i18n)
    {
    }

    /**
     * 翻译快捷方法
     * @param string                    $key    翻译键
     * @param array<string,string|int> $params 替换参数
     */
    protected function __(string $key, array $params = []): string
    {
        return $this->i18n->trans($key, $params);
    }

    /**
     * 从请求中检测语言
     */
    protected function getLocale(Request $request): string
    {
        return $this->i18n->detectLocale($request);
    }

    /**
     * 从 request attribute 读取中间件设置的鉴权信息
     *（currentUser / currentRole / userPermissions 均由 AuthMiddleware 统一设置）
     */
    protected function initAuthFromRequest(Request $request): void
    {
        $this->currentUser     = $request->getAttribute('currentUser', '');
        $this->currentRole     = $request->getAttribute('currentRole', AppConfig::ROLE_ADMIN);
        $this->userPermissions = $request->getAttribute('userPermissions', []);
    }

    /**
     * 判断当前用户是否有指定权限
     * super_admin 始终拥有所有权限
     */
    protected function hasPermission(string $permKey): bool
    {
        if ($this->currentRole === AppConfig::ROLE_SUPER_ADMIN) {
            return true;
        }
        return in_array($permKey, $this->userPermissions, true);
    }

    /**
     * 权限检查：无权限时返回错误响应，有权限返回 null
     */
    protected function requirePermission(Response $response, string $permKey, string $messageKey = 'auth.forbidden'): ?Response
    {
        if (!$this->hasPermission($permKey)) {
            return $this->jsonError($response, $messageKey, 403);
        }
        return null;
    }

    /**
     * 内存列表分页（tag 详情列表等一次拉全量、再按页返回的场景）。
     *
     * 口径与 /api/build/{path}/artifacts 一致：page 默认 1；page_size 默认 50、
     * 钳制 1..200；page 越界钳到最后一页；total_pages 至少为 1。
     *
     * query 中不带 page / page_size 时原样返回（保持既有「裸数组」契约，
     * 同步类调用方一次性拿全量，不受影响）；任一带上即返回
     * {items, total, page, page_size, total_pages} 分页信封。
     *
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    protected function paginateList(Request $request, array $items): array
    {
        $params  = $request->getQueryParams();
        $hasPage = isset($params['page']);
        $hasSize = isset($params['page_size']);
        if (!$hasPage && !$hasSize) {
            return $items;
        }

        $page     = max(1, (int) ($params['page'] ?? 1));
        $pageSize = (int) ($params['page_size'] ?? 50);
        if ($pageSize < 1 || $pageSize > 200) {
            $pageSize = 50;
        }

        $items      = array_values($items);
        $total      = count($items);
        $totalPages = $total > 0 ? max(1, (int) ceil($total / $pageSize)) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return [
            'items'       => array_slice($items, ($page - 1) * $pageSize, $pageSize),
            'total'       => $total,
            'page'        => $page,
            'page_size'   => $pageSize,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * 统一输出处理
     * @param Response $response
     * @param mixed $data 要输出的数据
     * @param Request|null $request 请求对象（用于读取 ?format 参数）
     * @param bool $forceRaw 强制原样输出（console 等纯文本接口用）
     * @return Response
     */
    protected function output(Response $response, $data, Request $request = null, bool $forceRaw = false): Response
    {

        if ($forceRaw) {
            $response->getBody()->write($data);
            return $response->withHeader('Content-Type', 'text/plain');
        }


        $format = $request ? ($request->getQueryParams()['format'] ?? 'raw') : 'raw';

        switch ($format) {
            case 'json':
                return $this->jsonResponse($response, $data, 200);
            case 'xml':
                return $this->xmlResponse($response, $data, 200);
            case 'raw':
            default:
                $response->getBody()->write(is_string($data) ? $data : (string) json_encode($data));
                return $response->withHeader('Content-Type', is_string($data) ? 'text/plain' : 'application/json');
        }
    }

    /**
     * JSON 成功响应
     * 如果数据已包含 'code' 键，直接输出，避免二次包裹
     *
     * @param mixed $data 要输出的数据（数组或标量）
     */
    protected function jsonResponse(Response $response, $data, int $code = 200): Response
    {

        if (is_array($data) && array_key_exists('code', $data)) {
            $response->getBody()->write((string) json_encode($data));
            $status = is_int($data['code']) ? $data['code'] : $code;
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write((string) json_encode(['data' => $data]));
        }
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * XML 成功响应
     *
     * @param mixed $data 要输出的数据（数组或标量）
     */
    protected function xmlResponse(Response $response, $data, int $code = 200): Response
    {
        $xml = $this->arrayToXml($data, 'root');
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml')->withStatus($code);
    }

    /**
     * JSON 错误响应（不受 format 参数影响）
     * @param string $message 错误消息（如为翻译键如 "auth.wrong_credentials"，会先尝试翻译）
     * @param array<string,mixed> $extra 附加字段（合并进错误响应体）
     */
    protected function jsonError(Response $response, string $message, int $code = 400, array $extra = []): Response
    {

        if (str_contains($message, '.') && !str_contains($message, ' ')) {
            $translated = $this->i18n->trans($message);

            if ($translated !== $message) {
                $message = $translated;
            }
        }
        $response->getBody()->write((string) json_encode(array_merge(['code' => $code, 'message' => $message], $extra)));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 递归将数组转为 XML 字符串
     *
     * @param mixed $data 要转换的数据（数组或标量）
     */
    private function arrayToXml($data, string $root = 'root'): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<{$root}>";
        $xml .= $this->arrayToXmlNodes($data);
        $xml .= "</{$root}>";
        return $xml;
    }

    /**
     * 递归输出 XML 节点
     *
     * @param mixed $data 节点数据（数组或标量）
     */
    private function arrayToXmlNodes($data): string
    {
        $xml = '';
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $nodeName = is_int($key) ? 'item' : $key;
                $xml .= "<{$nodeName}>";
                $xml .= is_array($value) ? $this->arrayToXmlNodes($value) : htmlspecialchars((string)$value);
                $xml .= "</{$nodeName}>";
            }
        } else {
            $xml .= htmlspecialchars((string)$data);
        }
        return $xml;
    }
}
