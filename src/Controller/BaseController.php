<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;

abstract class BaseController
{
    /**
     * 统一输出字符型数组格式 ["xxx","yyy"]
     * 如果service返回了 ['error'=>'...'] 也包装成数组
     */
    protected function jsonResponse(Response $response, mixed $data): Response
    {
        if (isset($data['error'])) {
            $output = [$data['error']];
        } elseif (is_array($data)) {
            $output = array_values($data);
        } else {
            $output = [(string)$data];
        }

        $response->getBody()->write(json_encode($output, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}