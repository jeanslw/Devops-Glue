<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Helper\Log;
use App\Service\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /healthz 存活/就绪探针（无需鉴权）。
 *
 * 供 Uptime Kuma / 云 LB / 容器编排抓取，只做 DB 探活 + 已应用版本读取（版本落后时额外逐表定位原因），
 * 不探测 Jenkins/Git/Harbor 等外部依赖，毫秒级返回。
 *
 * 字段与状态码语义（两阶段探测，`db` 只表达连接性）：
 *  - 连接失败（DB 宕机/凭据错）→ `db=false`，503 + `status=degraded`；
 *  - 连接成功但版本表缺失（空库未初始化）→ `db=true` 但仍 503 + `status=degraded`（服务尚未就绪不能
 *    接流；用 db=true 与「真宕机」区分，便于监控归因）；
 *  - DB 可达且 `schema_current=false`（如升级了代码还没跑结构迁移）→ 200 + `status=degraded`：
 *    滚动升级期「新代码 + 旧 schema」是预期中间态，若一并 503，LB / K8s 会摘掉全部副本，危害更大；
 *  - `schema_current=null`（手动建库模式从未跑过结构迁移）→ 200 + `status=ok`：不降级也不谎称对齐。
 * 各失败分支都写 error 日志，不再静默吞掉异常。
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
        $schemaVersion = null;
        $seedVersion   = null;
        $schemaCurrent = null;
        $missing       = [];
        $connected     = false; // 连接性：createPdo() + SELECT 1 成功
        $ready         = false; // 就绪：能读到版本状态（ci_app_settings 可查）
        try {
            $pdo = Database::createPdo();
            $pdo->query('SELECT 1');
            $connected = true;
            $state         = Database::schemaState($pdo);
            $schemaVersion = $state['schema_version'];
            $seedVersion   = $state['seed_version'];
            $schemaCurrent = $state['schema_current'];
            $ready         = true;
            if ($schemaCurrent === false) {
                // 仅在已判定未对齐时逐表探测（N 次查询），正常路径不付这个成本
                $missing = Database::missingTables($pdo);
            }
        } catch (\Throwable $e) {
            // 两阶段失败分开归因：连不上（DB 宕机/凭据错）与「连上但版本表缺失」（空库未初始化）
            // 的下一步动作完全不同；探针此前静默吞异常，线上排障没有任何线索。
            if ($connected) {
                Log::error('healthz: 数据库已连接但版本状态不可读（库未初始化？）', ['error' => $e->getMessage()]);
            } else {
                Log::error('healthz: 数据库连接失败', ['error' => $e->getMessage()]);
            }
        }

        // 未就绪（连不上 / 连上但空库）→ degraded；版本确认落后也 degraded（HTTP 仍 200 不摘流）；
        // 已对齐或未知（手动模式）→ ok。
        $degraded = !$ready || $schemaCurrent === false;
        $payload = [
            'status'         => $degraded ? 'degraded' : 'ok',
            'db'             => $connected,
            'app_version'    => AppConfig::APP_VERSION,
            'schema_version' => $schemaVersion,
            'seed_version'   => $seedVersion,
            'schema_current' => $schemaCurrent,
            'auto_migrate'   => Database::isAutoMigrateEnabled(),
            'time'           => time(),
        ];
        if ($missing !== []) {
            $payload['tables_missing'] = $missing;
        }

        $response->getBody()->write((string) json_encode($payload));
        return $response
            ->withStatus($ready ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }
}
