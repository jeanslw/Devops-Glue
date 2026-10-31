<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 轻量控制器：仅依赖 I18nService。
 *
 * 把语言包接口从 MainController 的重依赖图（HarborService / JenkinsService 等）里
 * 解耦出来。这样即便某个 service 构造失败，也只会影响各自功能，不会再连带拖垮
 * 前端翻译字典的加载（此前 MainController 构造函数依赖 HarborService，构造异常会
 * 让 /api/i18n 一起 500）。
 */
class I18nController extends BaseController
{
    /**
     * GET /api/i18n/{locale} — 获取指定语言的语言包
     *
     * @param array<string,string> $args 路由参数
     */
    public function i18n(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'zh_CN';
        $messages = $this->i18n->getAll($locale);
        $response->getBody()->write((string) json_encode($messages, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
