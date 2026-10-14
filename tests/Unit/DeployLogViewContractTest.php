<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\DeployLogRepository;
use PHPUnit\Framework\TestCase;

/**
 * 契约测试：CD 部署记录只读审计视图更名为 deploy_logs_view（v2.9.0，旧名 v_glue_deploy_logs）。
 *
 * 该视图由 Devops-CD 建立并维护，但「叫什么名字」是两侧共享的硬契约。CI 侧漏改一处的表现是
 * **部署日志审计页静默降级为占位提示**（而非报错），极易被当成「CD 还没数据」长期搁置，
 * 因此用测试把三件事钉死：
 *   1. 常量值就是新名，且不得再出现旧名（防有人「顺手」改回去）；
 *   2. 源码 / CLI 脚本里不再残留旧名（改常量只是一处，备份排除说明、CLI 说明同样要跟）；
 *   3. 仓储只经常量拼 SQL，且视图缺失时降级不抛异常、视图就绪时列清单可读。
 */
class DeployLogViewContractTest extends TestCase
{
    /** 旧名。拼接构造：本文件要能通过下面「全仓不残留旧名」的断言 */
    private const LEGACY_VIEW = 'v_glue' . '_deploy_logs';

    /** 契约视图暴露给 CI 的审计列（与 CD 侧视图定义一一对应，改名不得顺带改列） */
    private const AUDIT_COLUMNS = [
        'id', 'project', 'tag', 'image', 'deploy_type', 'target', 'status', 'triggered_by', 'deploy_note', 'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // 降级路径会写一条 warning 日志，指向临时目录以免污染真实日志目录
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['LOG_PATH']);
        Database::reset();
        parent::tearDown();
    }

    /** 常量值必须与 CD 侧 CREATE VIEW 的名字一致；旧名不得复用。 */
    public function testConstantPointsToRenamedView(): void
    {
        $this->assertSame('deploy_logs_view', AppConfig::TABLE_CD_DEPLOY_LOG_VIEW, '契约视图名必须与 CD 侧建视图的名字一致');
        $this->assertNotSame(self::LEGACY_VIEW, AppConfig::TABLE_CD_DEPLOY_LOG_VIEW, 'v2.9.0 起不得再使用旧视图名');
    }

    /** 视图归 CD 所有：不进 CI 的应用表探测清单，否则 /healthz 会报一张永远「缺失」的表。 */
    public function testContractViewIsNotProbedAsApplicationTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');

        $this->assertNotContains(
            AppConfig::TABLE_CD_DEPLOY_LOG_VIEW,
            Database::missingTables($pdo),
            'CD 侧契约视图不属于 CI 的应用表清单'
        );
        $this->assertSame([], Database::schemaListDrift()['probe_excluded'], '视图不得被塞进 schemaTables() 探测清单');
    }

    /** 仓储必须经常量引用视图名（写死名字会让改常量时被悄悄漏掉）。 */
    public function testRepositoryReferencesViewThroughConstantOnly(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../src/Service/DeployLogRepository.php');

        $this->assertStringNotContainsString("'deploy_logs_view'", $src, '仓储必须经 AppConfig 常量引用视图名，不得写死');
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($src, 'AppConfig::TABLE_CD_DEPLOY_LOG_VIEW'),
            'isAvailable / count / list 三处查询都应经常量'
        );
    }

    /**
     * 全仓不得再以**标识符字面量**形式引用旧名（注释里的更名说明是有意保留的，不在此列）。
     *
     * 真正危险的是「被当成标识符用」：常量值改回旧名、或某处 SQL / JS 写死旧名。这两种情况都会让
     * 两侧契约错位，而表现只是部署日志页静默降级，因此必须由测试拦住；.sql 文件无注释惯例，出现即算引用。
     */
    public function testNoCodeReferencesLegacyViewNameAsLiteral(): void
    {
        $roots   = ['src', 'config', 'cli', 'public', 'templates', 'tests'];
        $exts    = ['php', 'js', 'sql', 'json', 'html'];
        $base    = dirname(__DIR__, 2);
        $quoted  = "/['\"]" . self::LEGACY_VIEW . "['\"]/";
        $stale   = [];

        foreach ($roots as $root) {
            $dir = $base . DIRECTORY_SEPARATOR . $root;
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $exts, true)) {
                    continue;
                }
                $body = (string)file_get_contents($file->getPathname());
                $hit  = $ext === 'sql' ? str_contains($body, self::LEGACY_VIEW) : (bool)preg_match($quoted, $body);
                if ($hit) {
                    $stale[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $stale,
            '下列文件仍把旧视图名 ' . self::LEGACY_VIEW . ' 当标识符引用（应为 ' . AppConfig::TABLE_CD_DEPLOY_LOG_VIEW . '）：'
            . implode(', ', $stale)
        );
    }

    /** 视图缺失（CD 未同步改名 / 尚未创建）时降级为 available=false，绝不 500。 */
    public function testMissingViewDegradesGracefully(): void
    {
        $repo = new DeployLogRepository($this->createMemoryPdo());

        $this->assertFalse($repo->isAvailable(), '空库里不存在契约视图');

        $result = $repo->list([], 1, 20);
        $this->assertFalse($result['available'], '视图缺失时不得抛异常，应降级为占位提示');
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** 正向：视图按契约列建好后可正常读取（同时锁定列清单，防改名顺带改列）。 */
    public function testListReadsAuditColumnsFromView(): void
    {
        $pdo = $this->createMemoryPdo();
        $columns = implode(', ', self::AUDIT_COLUMNS);
        $pdo->exec('CREATE TABLE cd_deploy_logs (' . $columns . ', output TEXT, stage_times TEXT)');
        $pdo->exec(
            'CREATE VIEW ' . AppConfig::TABLE_CD_DEPLOY_LOG_VIEW . ' AS SELECT ' . $columns . ' FROM cd_deploy_logs'
        );
        $pdo->exec(
            "INSERT INTO cd_deploy_logs (project, tag, image, deploy_type, target, status, triggered_by, deploy_note, created_at)"
            . " VALUES ('shop', 'v1.2.3', 'reg/shop:v1.2.3', 'release', 'prod', 'success', 'alice', 'hotfix', '"
            . date('Y-m-d H:i:s', time() - 3600) . "')"
        );

        $repo = new DeployLogRepository($pdo);
        $this->assertTrue($repo->isAvailable());

        $result = $repo->list(['project' => 'shop'], 1, 20);
        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('v1.2.3', $result['items'][0]['tag']);
        $this->assertArrayNotHasKey('output', $result['items'][0], '审计列表不得带出 output 等大字段');
    }

    private function createMemoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }
}
