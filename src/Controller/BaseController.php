<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BaseController
{
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
        } else {
            // 否则只包裹 data
            $response->getBody()->write(json_encode(['data' => $data]));
        }
        return $response->withHeader('Content-Type', 'application/json');
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
     */
    protected function jsonError(Response $response, string $message, int $code = 400): Response
    {
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