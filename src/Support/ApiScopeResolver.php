<?php

namespace App\Support;

use App\Config\AppConfig;

/**
 * 根据 HTTP 方法 + 路径解析 API token 所需 scope。
 *
 * 从 AppConfig 迁出：这是路由匹配逻辑而非配置常量。
 *
 * 返回值约定：
 *   - null       → API token 禁止访问（/api/admin/* 等管理端点，fail-closed）
 *   - '*'        → 任意有效 token 均可访问（如 /api/health）
 *   - 具体 scope → token 必须持有该 scope
 *
 * 只对「已被 AuthMiddleware 保护」的路径生效；公开路由（i18n/docs 等）不经过此方法。
 */
class ApiScopeResolver
{
    public static function resolve(string $method, string $path): ?string
    {
        $m = strtoupper($method);
        // 归一化：去掉查询串、统一斜杠
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $path;
        $path = '/' . trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // 健康检查：任意有效 token
        if ($path === '/api/health') {
            return '*';
        }

        // 管理端点：API token 一律禁止（super_admin 交互式专属）
        if (preg_match('#^/api/admin($|/)#', $path)) {
            return null;
        }

        // RBAC（CD 服务账号专用）：用户读写（建/读/改/删）+ 角色目录，统一 scope。
        // 复用 rbac.user.write 而非新增 read scope：CD 是单一 trusted 消费方，token 本就有写权限，
        // 读操作不构成额外提权，也避免为读接口再重签 token。不落入 /api/admin fail-closed。
        if (preg_match('#^/api/rbac($|/)#', $path)) {
            return AppConfig::API_SCOPE_RBAC_USER_WRITE;
        }

        // MAIN：只读
        if (preg_match('#^/api/main($|/)#', $path)) {
            return AppConfig::API_SCOPE_MAIN;
        }

        // GIT：只读
        if (preg_match('#^/api/git($|/)#', $path)) {
            return AppConfig::API_SCOPE_GIT;
        }

        // Harbor：触发扫描（写）优先于读判断
        if (preg_match('#^/api/harbor/.+/repositories/.+/tags/.+/scan$#', $path) && $m === 'POST') {
            return AppConfig::API_SCOPE_HARBOR_SCAN;
        }
        if (preg_match('#^/api/harbor($|/)#', $path)) {
            return AppConfig::API_SCOPE_HARBOR_READ;
        }

        // Build：写操作（trigger / retry / cancel）
        if (preg_match('#^/api/build/.+/pipelines/\d+/(retry|cancel)$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_WRITE;
        }
        if (preg_match('#^/api/build/.+/trigger$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_WRITE;
        }

        // Build：CI 流水线回写（scan-sync / commit-status / report）
        if (preg_match('#^/api/build/.+/(scan-sync|commit-status|report)$#', $path)) {
            return AppConfig::API_SCOPE_BUILD_REPORT;
        }

        // Build：其余全部只读
        if (preg_match('#^/api/build($|/)#', $path)) {
            return AppConfig::API_SCOPE_BUILD_READ;
        }

        // 未知路径：fail-closed
        return null;
    }
}
