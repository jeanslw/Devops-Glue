<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Helper\Log;
use App\Service\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /healthz 存活与就绪探针（无需鉴权）。
 *
 * 供 Uptime Kuma / 云 LB / 容器编排抓取，只做 DB 探活与已应用版本读取（版本落后时额外逐表定位原因），
 * 不探测 Jenkins/Git/Harbor 等外部依赖，毫秒级返回。
 */
final class HealthzController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $schemaVersion = null;
        $seedVersion   = null;
        $schemaCurrent = null;
        $schemaAhead   = false;
        $missing       = [];
        $connected     = false;
        $ready         = false;


        $drift = Database::schemaListDrift();
        try {
            $pdo = Database::createPdo();
            $pdo->query('SELECT 1');
            $connected = true;
            $state         = Database::schemaState($pdo);
            $schemaVersion = $state['schema_version'];
            $seedVersion   = $state['seed_version'];
            $schemaCurrent = $state['schema_current'];
            $schemaAhead   = $state['schema_ahead'];
            $ready         = true;
            if ($schemaCurrent === false) {
                $missing = Database::missingTables($pdo);
            }
        } catch (\Throwable $e) {
            if ($connected) {
                Log::error('healthz: 数据库已连接但版本状态不可读（库未初始化？）', ['error' => $e->getMessage()]);
            } else {
                Log::error('healthz: 数据库连接失败', ['error' => $e->getMessage()]);
            }
        }



        $degraded = !$ready || $schemaCurrent === false || !$drift['ok'];
        $payload = [
            'status'         => $degraded ? 'degraded' : 'ok',
            'db'             => $connected,
            'app_version'    => AppConfig::APP_VERSION,
            'schema_version' => $schemaVersion,
            'seed_version'   => $seedVersion,
            'schema_current' => $schemaCurrent,
            'schema_ahead'   => $schemaAhead,
            'auto_migrate'   => Database::isAutoMigrateEnabled(),
            'time'           => time(),
        ];
        if ($missing !== []) {
            $payload['tables_missing'] = $missing;
        }
        if ($drift['tables'] !== []) {
            $payload['tables_list_drift'] = $drift['tables'];
        }

        $response->getBody()->write((string) json_encode($payload));
        return $response
            ->withStatus($ready ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }
}
