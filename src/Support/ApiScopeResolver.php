<?php

namespace App\Support;

use App\Config\AppConfig;

/**
 * 根据 HTTP 方法 + 路径解析 API token 所需 scope。
 *
 * 从 AppConfig 迁出：这是路由匹配逻辑而非配置常量。
 *
 * 返回值约定：
 *   - null       ：API token 禁止访问（/api/admin/* 等管理端点，默认拒绝）
 *   - '*'        ：任意有效 token 均可访问（如 /api/health）
 *   - 具体 scope ：token 必须持有该 scope
 *
 * 只对已被 AuthMiddleware 保护的路径生效；公开路由（i18n/docs 等）不经过此方法。
 */
class ApiScopeResolver
{
    public static function resolve(string $method, string $path): ?string
    {
        $m = strtoupper($method);

        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $path;
        $path = '/' . trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }


        if ($path === '/api/health') {
            return '*';
        }


        if (preg_match('#^/api/admin($|/)#', $path)) {
            return null;
        }




        if (preg_match('#^/api/rbac($|/)#', $path)) {
            return AppConfig::API_SCOPE_RBAC_USER_WRITE;
        }


        if (preg_match('#^/api/integration($|/)#', $path)) {
            return AppConfig::API_SCOPE_BUILD_READ;
        }

        if (preg_match('#^/api/main($|/)#', $path)) {
            return AppConfig::API_SCOPE_MAIN;
        }

        if (preg_match('#^/api/git($|/)#', $path)) {
            return AppConfig::API_SCOPE_GIT;
        }

        if (preg_match('#^/api/harbor/.+/repositories/.+/tags/.+/scan$#', $path) && $m === 'POST') {
            return AppConfig::API_SCOPE_HARBOR_SCAN;
        }
        if (preg_match('#^/api/harbor($|/)#', $path)) {
            return AppConfig::API_SCOPE_REGISTRY_READ;
        }

        if (preg_match('#^/api/registry($|/)#', $path)) {
            return AppConfig::API_SCOPE_REGISTRY_READ;
        }

        if (preg_match('#^/api/build/.+/pipelines/\d+/(retry|cancel)$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_WRITE;
        }
        if (preg_match('#^/api/build/.+/trigger$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_WRITE;
        }


        if (preg_match('#^/api/build/.+/(scan-sync|registry-sync|commit-status|report)$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_REPORT;
        }


        if (preg_match('#^/api/build($|/)#', $path)) {
            return AppConfig::API_SCOPE_BUILD_READ;
        }


        return null;
    }
}
