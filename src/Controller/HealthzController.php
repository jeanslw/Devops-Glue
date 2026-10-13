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
 *
 * 字段与状态码语义（两阶段探测，`db` 只表达连接性）：
 *  - 连接失败（DB 宕机或凭据错误）：`db=false`，503 + `status=degraded`；
 *  - 连接成功但版本表缺失（空库未初始化）：`db=true` 但仍返回 503 + `status=degraded`，服务尚未就绪
 *    不能接流；用 db=true 与真正宕机区分，便于监控定位原因；
 *  - DB 可达且 `schema_current=false`（如升级了代码但还没跑结构迁移）：200 + `status=degraded`。
 *    滚动升级期出现新代码配旧 schema 属预期中间状态，若一并返回 503，LB / K8s 会摘掉全部副本，影响更大；
 *  - `schema_current=null`（手动建库模式从未跑过结构迁移）：200 + `status=ok`，不降级也不表示已对齐。
 * 各失败分支都写 error 日志，不再忽略异常。
 *
 * `tables_list_drift`：代码内自检（`Database::schemaListDrift()`，纯反射、与库无关）发现
 * AppConfig 的 `TABLE_*` 表常量与 `schemaTables()` 探测清单不一致时出现，并计入 `status=degraded`
 * （HTTP 仍 200：问题出在代码内的表清单而非数据，不应摘流）。它列出的是后台数据表清单与本探针的
 * `tables_missing` 会漏报的表，v2.8.9 修复的 `ci_api_access_logs` 漏加即属此类。正常代码不会出现。
 *
 * `schema_ahead` 是方向位，用于区分两种未对齐（处置方式不同）：
 *  - `schema_current=false` + `schema_ahead=false`：代码比库新（未跑结构迁移，执行 cli/migrate.php）；
 *  - `schema_current=false` + `schema_ahead=true`：库比代码新（代码已回滚降级，先确认是否接受旧代码
 *    跑新库，再对齐并复核种子被回退覆盖的影响；详见 FAQ 回滚/降级指引）。
 *
 * 单独成类而不挂在 MainController 的原因：
 * MainController 的构造器强依赖 \PDO（DI 解析时即走 Database::getPdo() 建立连接），
 * 一旦 DB 宕机，DI 解析 MainController 就会抛异常，Slim 转成 500，探针的 try/catch 根本进不去。
 * 本控制器没有构造依赖，在处理器内用 Database::createPdo() 新建连接并就地捕获异常，
 * DB 不可访问时返回 503 + status=degraded，而不是 500。
 */
final class HealthzController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $schemaVersion = null;
        $seedVersion   = null;
        $schemaCurrent = null;
        $schemaAhead   = false; // 方向位：库结构版本高于当前代码（疑似回滚降级）
        $missing       = [];
        $connected     = false; // 连接性：createPdo() + SELECT 1 成功
        $ready         = false; // 就绪：能读到版本状态（ci_app_settings 可查）
        // 代码自检（纯反射，与库无关，故放在 try 外）：探测清单漂移意味着本探针与后台表清单会漏表，
        // 这样绕过 CI 升级或打补丁的实例也能被监控发现。
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
                // 仅在已判定未对齐时逐表探测（N 次查询），正常路径不付这个成本
                $missing = Database::missingTables($pdo);
            }
        } catch (\Throwable $e) {
            // 两阶段失败分开归因：连不上（DB 宕机或凭据错误）与连上但版本表缺失（空库未初始化）
            // 的下一步处置完全不同；若像以前那样忽略异常，线上排障没有线索。
            if ($connected) {
                Log::error('healthz: 数据库已连接但版本状态不可读（库未初始化？）', ['error' => $e->getMessage()]);
            } else {
                Log::error('healthz: 数据库连接失败', ['error' => $e->getMessage()]);
            }
        }

        // 未就绪（连不上 / 连上但空库）返回 degraded；确认版本落后也返回 degraded（HTTP 仍 200，不摘流）；
        // 已对齐或未知（手动模式）返回 ok。代码内表清单漂移（tables_list_drift）同样只降级不摘流。
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
            // 代码内自检：这两张清单不一致时，本探针的 tables_missing 与后台数据表清单会漏报这些表。
            // 属代码内表清单问题（包内容与 CI 结果不符），故只降级不清零、也不 return 503。
            $payload['tables_list_drift'] = $drift['tables'];
        }

        $response->getBody()->write((string) json_encode($payload));
        return $response
            ->withStatus($ready ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }
}
