<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Service\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /healthz 存活/就绪探针（无需鉴权）。
 *
 * 供 Uptime Kuma / 云 LB / 容器编排抓取，只做 DB 探活 + schema 版本读取，
 * 不探测 Jenkins/Git/Harbor 等外部依赖，毫秒级返回。
 *
 * 为什么单独成类、不挂在 MainController：
 * MainController 的构造器强依赖 \PDO（DI 解析时即走 Database::getPdo() 建立连接），
 * 一旦 DB 宕机，DI 解析 MainController 就会抛异常 → Slim 兜成 500，探针的 try/catch 根本进不去。
 * 本控制器零构造依赖，在本处理器内用 Database::createPdo() 新建连接并就地捕获异常，
 * DB 不可访问时如实返回 503 + status=degraded，而不是 500。
 */
final class HealthzController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $db = true;
        $schemaVersion = null;
        try {
            $pdo = Database::createPdo();
            $pdo->query('SELECT 1');
            $row = $pdo->query(
                'SELECT value FROM ' . AppConfig::TABLE_APP_SETTINGS
                . " WHERE setting_key = '" . Database::SCHEMA_VERSION_KEY . "'"
            )->fetchColumn();
            $schemaVersion = ($row === false) ? null : (string) $row;
        } catch (\Throwable $e) {
            $db = false;
        }

        $payload = [
            'status'         => $db ? 'ok' : 'degraded',
            'db'             => $db,
            'app_version'    => AppConfig::APP_VERSION,
            'schema_version' => $schemaVersion,
            'time'           => time(),
        ];
        $response->getBody()->write((string) json_encode($payload));
        return $response
            ->withStatus($db ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }
}
