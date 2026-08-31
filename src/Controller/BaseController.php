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
    protected array $userPermissions = [];

    public function __construct(protected I18nService $i18n)
    {
    }

    /**
     * 翻译快捷方法
     * @param string $key    翻译键
     * @param array  $params 替换参数
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
     * 统一输出处理
     * @param Response $response
     * @param mixed $data 要输出的数据
     * @param Request|null $request 请求对象（用于读取 ?format 参数）
     * @param bool $forceRaw 强制原样输出（console 等纯文本接口用）
     * @return Response
     */
    protected function output(Response $response, $data, Request $request = null, bool $forceRaw = false): Response
    {
        // 强制原样输出（如控制台日志）
        if ($forceRaw) {
            $response->getBody()->write($data);
            return $response->withHeader('Content-Type', 'text/plain');
        }

        // 读取格式参数，默认 raw（保持原有行为）
        $format = $request ? ($request->getQueryParams()['format'] ?? 'raw') : 'raw';

        switch ($format) {
            case 'json':
                return $this->jsonResponse($response, $data, 200);
            case 'xml':
                return $this->xmlResponse($response, $data, 200);
            case 'raw':
            default:
                // 原始行为：数组/对象转JSON，字符串原样输出
                $response->getBody()->write(is_string($data) ? $data : json_encode($data));
                return $response->withHeader('Content-Type', is_string($data) ? 'text/plain' : 'application/json');
        }
    }

    /**
     * JSON 成功响应
     * 如果数据已包含 'code' 键，直接输出，避免二次包裹
     */
    protected function jsonResponse(Response $response, $data, int $code = 200): Response
    {
        // 如果数据本身已经是完整的响应结构（比如 buildTrigger），直接输出
        if (is_array($data) && array_key_exists('code', $data)) {
            $response->getBody()->write(json_encode($data));
            $status = is_int($data['code']) ? $data['code'] : $code;
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        } else {
            // 否则只包裹 data
            $response->getBody()->write(json_encode(['data' => $data]));
        }
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * XML 成功响应
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
     */
    protected function jsonError(Response $response, string $message, int $code = 400): Response
    {
        // 消息看起来像翻译键（含点号且无空格）时尝试翻译
        if (str_contains($message, '.') && !str_contains($message, ' ')) {
            $translated = $this->i18n->trans($message);
            // 翻译成功（不同于键本身）则使用翻译结果
            if ($translated !== $message) {
                $message = $translated;
            }
        }
        $response->getBody()->write(json_encode(['code' => $code, 'message' => $message]));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 递归将数组转为 XML 字符串
     */
    private function arrayToXml($data, string $root = 'root'): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<{$root}>";
        $xml .= $this->arrayToXmlNodes($data);
        $xml .= "</{$root}>";
        return $xml;
    }

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
