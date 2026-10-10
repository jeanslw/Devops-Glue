<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use PHPUnit\Framework\TestCase;

/**
 * 回归测试：Slim4 重构后 Database::getPdo() 变成死代码，
 * 容器直接 new PDO，导致建库脚本（无 INSERT）重建的库永远没有种子数据，
 * 登录后无 RBAC 权限、无「修改密码」功能。
 *
 * 这里锁住 bootstrap() 入口：必须写入 RBAC 系统角色/权限/角色映射 + 根管理员，
 * 且种子缺失时 fail-fast 抛异常，而不是静默。
 */
class DatabaseBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER'] = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        // 避免 ensureTables() 里 ALTER 失败触发的日志写到 /data/logs 污染环境
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['LOG_PATH']);
        Database::reset();
        parent::tearDown();
    }

    public function testBootstrapSeedsRbacAndRootAdmin(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);

        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        // 系统角色
        $stmt = $pdo->prepare('SELECT name FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?');
        $stmt->execute([AppConfig::ROLE_SUPER_ADMIN]);
        $this->assertNotFalse($stmt->fetch(), 'super_admin 角色应被种子写入');

        // super_admin 描述被种子写入
        $descStmt = $pdo->prepare('SELECT description FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?');
        $descStmt->execute([AppConfig::ROLE_SUPER_ADMIN]);
        $this->assertSame(AppConfig::DEFAULT_ROLE_DESCRIPTIONS[AppConfig::ROLE_SUPER_ADMIN], $descStmt->fetchColumn(), 'super_admin 描述应被种子写入');

        // 权限定义非空
        $permCount = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();
        $this->assertGreaterThan(0, $permCount, 'permissions 应被种子写入');

        // super_admin 映射了全部权限（role_permissions）
        $rpCount = (int)$pdo->query(
            'SELECT count(*) FROM ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' rp'
            . ' JOIN ' . AppConfig::TABLE_ROLES . ' r ON r.id = rp.role_id'
            . " WHERE r.name = '" . AppConfig::ROLE_SUPER_ADMIN . "'"
        )->fetchColumn();
        $this->assertEquals(count(AppConfig::DEFAULT_PERMISSIONS), $rpCount, 'super_admin 应映射全部权限');

        // 根管理员账号（角色 super_admin）
        $stmt = $pdo->prepare('SELECT role FROM ' . AppConfig::TABLE_ADMIN_USERS . ' WHERE username = ?');
        $stmt->execute(['admin']);
        $admin = $stmt->fetch();
        $this->assertNotFalse($admin, '根管理员应被种子写入');
        $this->assertEquals(AppConfig::ROLE_SUPER_ADMIN, $admin['role']);
    }

    public function testBootstrapSeedsViewerAsReadOnlySystemRole(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        // viewer 被种子写入且是系统内置角色（不可删）
        $stmt = $pdo->prepare('SELECT is_system FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?');
        $stmt->execute([AppConfig::ROLE_VIEWER]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'viewer 角色应被种子写入');
        $this->assertEquals(1, (int)$row['is_system'], 'viewer 应为系统内置角色（不可删）');

        // viewer 描述被种子写入
        $descStmt = $pdo->prepare('SELECT description FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?');
        $descStmt->execute([AppConfig::ROLE_VIEWER]);
        $this->assertSame(AppConfig::DEFAULT_ROLE_DESCRIPTIONS[AppConfig::ROLE_VIEWER], $descStmt->fetchColumn(), 'viewer 描述应被种子写入');

        // 只读 = 恰好 15 个纯读视图 key（6 CD + 9 CI），无任何写 key，尤其不含 cd.image-registry 与 ci.manage
        $keys = $pdo->query(
            'SELECT rp.perm_key FROM ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' rp'
            . ' JOIN ' . AppConfig::TABLE_ROLES . ' r ON r.id = rp.role_id'
            . " WHERE r.name = '" . AppConfig::ROLE_VIEWER . "'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEqualsCanonicalizing([
            AppConfig::PERM_CD_APPROVAL_CENTER,
            AppConfig::PERM_CD_BUILD,
            AppConfig::PERM_CD_HISTORY,
            AppConfig::PERM_CD_MONITOR,
            'cd.monitor.app',
            'cd.monitor.system',
            AppConfig::PERM_CI_USERS_LIST,
            AppConfig::PERM_CI_PERMISSIONS_LIST,
            AppConfig::PERM_CI_BUILD_RECORDS,
            AppConfig::PERM_CI_BUILD_RECORDS_PULL,
            AppConfig::PERM_CI_BUILD_RECORDS_PUSH,
            AppConfig::PERM_CI_OPERATION_LOGS,
            AppConfig::PERM_CI_LOGS,
            AppConfig::PERM_CI_DEPLOY_LOGS,
            AppConfig::PERM_CI_API_LOGS,
        ], $keys, 'viewer 应恰好拥有 15 个纯读视图 key（6 CD + 9 CI）');
    }

    public function testBootstrapSkipsSeedWhenSchemaVersionMatches(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $before = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();

        // 模拟运行时删掉一条权限：同版本再次 bootstrap 不应把它写回来（种子被跳过）
        $firstKey = array_key_first(AppConfig::DEFAULT_PERMISSIONS);
        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_PERMISSIONS . ' WHERE perm_key = ' . $pdo->quote($firstKey));

        Database::bootstrap($pdo);

        $after = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();
        $this->assertSame($before - 1, $after, '同版本重复 bootstrap 不应重新写入种子');
    }

    public function testBootstrapThrowsWhenRbacSeedMissing(): void
    {
        // 模拟手动建库脚本模式：只建了 admin_users，roles/permissions 等表缺失
        Database::init(['driver' => 'sqlite', 'auto_migrate' => false]);

        $pdo = $this->createMemoryPdo();
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "admin",
            systems TEXT NOT NULL DEFAULT "ci,cd",
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $this->expectException(\RuntimeException::class);
        Database::bootstrap($pdo);
    }

    /**
     * 手动建库模式（DB_AUTO_MIGRATE=false）首次引导：写 seed_version 记录种子已应用，
     * 但**不写 schema_version** —— 该模式的 DDL（含存量库补列）归运维，标记 schema 当前会谎报表结构已升级。
     */
    public function testManualModeMarksSeedVersionButNotSchemaVersion(): void
    {
        $pdo = $this->createManualModePdo();

        $seedVersion = $pdo->query(
            'SELECT value FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'seed_version'"
        )->fetchColumn();
        $this->assertSame(AppConfig::APP_VERSION, $seedVersion, '手动模式应记录 seed_version');

        $schemaVersion = $pdo->query(
            'SELECT value FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'schema_version'"
        )->fetchColumn();
        $this->assertFalse($schemaVersion, '手动模式不应标记 schema_version');
    }

    /**
     * 手动建库模式命中 seed_version 后应短路：不再每请求重跑 seedRbac 的写库
     * （php-fpm 每请求独立进程，bootstrap() 的进程内守卫无法跨请求生效）。
     */
    public function testManualModeSkipsRbacSeedWhenSeedVersionMatches(): void
    {
        $pdo = $this->createManualModePdo();
        $before = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();

        // 删一条权限后再次引导：命中 seed_version 短路，不应把它写回来
        $firstKey = array_key_first(AppConfig::DEFAULT_PERMISSIONS);
        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_PERMISSIONS . ' WHERE perm_key = ' . $pdo->quote($firstKey));
        $this->bootstrapManualMode($pdo);

        $after = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();
        $this->assertSame($before - 1, $after, '手动模式命中 seed_version 后不应重复播种');
    }

    /** seed_version 缺失（如代码升级后尚未补种子）时应重新播种自愈，而不是永久短路。 */
    public function testManualModeReseedsWhenSeedVersionMissing(): void
    {
        $pdo = $this->createManualModePdo();
        $before = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();

        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'seed_version'");
        $firstKey = array_key_first(AppConfig::DEFAULT_PERMISSIONS);
        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_PERMISSIONS . ' WHERE perm_key = ' . $pdo->quote($firstKey));
        $this->bootstrapManualMode($pdo);

        $after = (int)$pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_PERMISSIONS)->fetchColumn();
        $this->assertSame($before, $after, 'seed_version 缺失时应重新播种自愈');
    }

    /**
     * 迁移失败归因（建议 4）：MySQL 权限类驱动码 1044/1142/1227、SQLSTATE 42000 与 SQLite 只读库
     * 都应归为「账号无 DDL 权限」，供后台按钮返回 409 + 可操作提示（而不是裸 500）。
     */
    public function testClassifyMigrationErrorDetectsDdlDenial(): void
    {
        $this->assertSame('ddl_denied', Database::classifyMigrationError(
            "SQLSTATE[42000]: Syntax error or access violation: 1142 CREATE command denied to user 'devops'@'%' for table 'ci_job_git_map'",
            '42000',
            1142
        ));
        $this->assertSame('ddl_denied', Database::classifyMigrationError('CREATE command denied', '', 1044));
        $this->assertSame('ddl_denied', Database::classifyMigrationError('Access denied', '', 1227));
        // 无驱动码时按 SQLSTATE 42000 兜底，但还须消息含 denied（PDO 标准前缀 "syntax error or access
        // violation" 里的 access violation 不能作为权限信号，否则 1064 语法错误会被连带误判）
        $this->assertSame('ddl_denied', Database::classifyMigrationError('CREATE command denied for user', '42000'));
        $this->assertSame('ddl_denied', Database::classifyMigrationError('access denied; you need the SUPER privilege', '42000'));
        // SQLite 只读库/只读文件与「缺 DDL 权限」同类处置
        $this->assertSame('ddl_denied', Database::classifyMigrationError('SQLite: attempt to write a readonly database'));
    }

    /** 连接层错误（凭据被拒 / 库不存在 / 连不上）归为 unreachable，与 DDL 权限区分（二者的下一步动作不同）。 */
    public function testClassifyMigrationErrorDetectsUnreachable(): void
    {
        $this->assertSame('unreachable', Database::classifyMigrationError(
            "SQLSTATE[HY000] [1045] Access denied for user 'devops'@'localhost' (using password: YES)",
            'HY000',
            1045
        ));
        $this->assertSame('unreachable', Database::classifyMigrationError('Unknown database', '', 1049));
        $this->assertSame('unreachable', Database::classifyMigrationError('SQLSTATE[HY000] [2002] Connection refused', 'HY000', 2002));
        $this->assertSame('unreachable', Database::classifyMigrationError('SQLSTATE[HY000] [28000] login failed', '28000'));
        $this->assertSame('unreachable', Database::classifyMigrationError('SQLite: unable to open database file'));
        // PHP open_basedir 限制 → SQLite 打不开库文件（线上真实见过），同样属于「库不可达」
        $this->assertSame('unreachable', Database::classifyMigrationError('SQLSTATE[HY000]: General error: 14 open_basedir prohibits opening C:\\data\\x.db', 'HY000', 14));
    }

    /**
     * 无法归因的错误保持 other；尤其 42000 大类里的语法错误(1064)/重复列(1060) 绝不能误判成缺 DDL
     * 权限——迁移部分成功后重跑撞 1060 是最常见路径，误判会把排障引向「换高权账号」的错误方向。
     */
    public function testClassifyMigrationErrorFallsBackToOther(): void
    {
        $this->assertSame('other', Database::classifyMigrationError('Duplicate entry for key ci_roles.name', '23000', 1062));
        // MySQL 1064 语法错误：SQLSTATE 也是 42000、驱动码 1064，且标准前缀含 "access violation"，仍须归 other
        $this->assertSame('other', Database::classifyMigrationError(
            "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax near 'FOO'",
            '42000',
            1064
        ));
        // 1060 重复列（迁移中途失败后重跑的常见情形）→ other，而非 ddl_denied
        $this->assertSame('other', Database::classifyMigrationError(
            'SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name',
            '42S21',
            1060
        ));
        // 无驱动码、仅 SQLSTATE 42000 但消息不含 denied → 不能按权限拒绝处理
        $this->assertSame('other', Database::classifyMigrationError('some syntactic problem', '42000'));
        $this->assertSame('other', Database::classifyMigrationError(''));
    }

    /**
     * 自动建库模式：只写 schema_version（seed_version 是手动建库模式的记录键，此模式不写）
     * → schema_current=true，且无缺失表。
     */
    public function testSchemaStateReportsAlignedInAutoMigrateMode(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $state = Database::schemaState($pdo);
        $this->assertSame(AppConfig::APP_VERSION, $state['schema_version']);
        $this->assertNull($state['seed_version'], '自动建库模式不写 seed_version（该键服务于手动建库模式的短路）');
        $this->assertTrue($state['schema_current']);
        $this->assertSame([], Database::missingTables($pdo), '建表后不应有缺失表');
    }

    /**
     * 手动建库模式只写 seed_version（不写 schema_version）：schema_current 必须保持 null（未知），
     * 不得用 seed_version 回落判 true——种子（RBAC INSERT）补齐不代表业务表新增列已就绪，
     * 升级漏跑结构迁移时种子照样能补齐，回落判 true 会把「新代码 + 旧表结构」谎报成健康。
     */
    public function testSchemaStateStaysUnknownInManualModeWithoutSchemaVersion(): void
    {
        $pdo = $this->createManualModePdo();

        $state = Database::schemaState($pdo);
        $this->assertNull($state['schema_version'], '手动模式不应有 schema_version');
        $this->assertSame(AppConfig::APP_VERSION, $state['seed_version'], '种子版本仍记录（观测/播种短路用）');
        $this->assertNull($state['schema_current'], '无 schema_version 时必须是「未知」，不得据 seed_version 谎称结构已对齐');
    }

    /** 版本落后（升级了代码还没跑迁移）→ schema_current=false；缺失表清单只在真缺表时非空。 */
    public function testSchemaStateDetectsStaleVersionAndMissingTables(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);
        $pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value = '0.0.0' WHERE setting_key = 'schema_version'");

        $state = Database::schemaState($pdo);
        $this->assertSame('0.0.0', $state['schema_version']);
        $this->assertFalse($state['schema_current'], '版本不一致应判定为未对齐');
        $this->assertSame([], Database::missingTables($pdo), '版本落后 ≠ 缺表，清单应为空');

        $pdo->exec('DROP TABLE ' . AppConfig::TABLE_PLATFORM_VERSIONS);
        $this->assertSame([AppConfig::TABLE_PLATFORM_VERSIONS], Database::missingTables($pdo));
    }

    /** 两个版本键都没有（既非自动建库也未手动标记）→ schema_current=null（未知），探针不据此降级。 */
    public function testSchemaStateReturnsNullWhenNoVersionRecorded(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);
        $pdo->exec(
            'DELETE FROM ' . AppConfig::TABLE_APP_SETTINGS
            . " WHERE setting_key IN ('schema_version', 'seed_version')"
        );

        $state = Database::schemaState($pdo);
        $this->assertNull($state['schema_version']);
        $this->assertNull($state['seed_version']);
        $this->assertNull($state['schema_current'], '无版本记录应为未知（null），不判降级');
    }

    /**
     * 方向位 schema_ahead：库结构版本**高于**代码版本 = 代码被降级（回滚）→ current=false + ahead=true。
     * 方向必须用 version_compare 判定（'2.10.0' > '2.8.9'）；字符串比较会得出相反结论，故专门锁住该用例。
     */
    public function testSchemaStateReportsAheadOnCodeDowngrade(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value = '2.10.0' WHERE setting_key = 'schema_version'");
        $state = Database::schemaState($pdo);
        $this->assertFalse($state['schema_current'], '版本不一致 → 未对齐');
        $this->assertTrue($state['schema_ahead'], '库版本高于代码 → 疑似降级回滚（须用 version_compare，非字典序）');

        // 反向：版本低于代码 → 属于「没跑结构迁移」，不是「代码回滚」
        $pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value = '0.0.0' WHERE setting_key = 'schema_version'");
        $this->assertFalse(Database::schemaState($pdo)['schema_ahead'], '库版本低于代码不算 ahead');
    }

    /** 无版本记录（未知）时 ahead 必须为 false：没有记录可判定为「更高」，避免监控误报降级。 */
    public function testSchemaStateReportsAheadFalseWhenNoVersionRecorded(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);
        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'schema_version'");

        $state = Database::schemaState($pdo);
        $this->assertNull($state['schema_current']);
        $this->assertFalse($state['schema_ahead'], '无记录 → 非 ahead');
    }

    /**
     * 降级后 migrateNow() 会把「更新」的标记改写回当前（旧）版本（自动模式 bootstrap 走同一 markSchemaCurrent 路径），
     * 且改写前先经 warnOnVersionDowngrade() 留 warning 日志——本用例锁住「不抛异常且标记被对齐」。
     */
    public function testMigrateNowRewritesAheadMarkerToCurrentVersion(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);
        $pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value = '99.0.0' WHERE setting_key = 'schema_version'");

        $status = Database::migrateNow();
        $this->assertSame(AppConfig::APP_VERSION, $status['schema_version'], '降级后迁移应把标记对齐到当前代码版本');
        $this->assertTrue($status['is_current']);
        $this->assertFalse(Database::schemaState($pdo)['schema_ahead'], '标记已对齐后 ahead 归零');
    }

    /** 空库：missingTables() 列出全部核心表（/healthz 用它解释 schema_current=false 的原因）。 */
    public function testMissingTablesListsEveryCoreTableOnEmptyDatabase(): void
    {
        $pdo = $this->createMemoryPdo();

        $missing = Database::missingTables($pdo);
        $this->assertNotEmpty($missing);
        $this->assertContains(AppConfig::TABLE_APP_SETTINGS, $missing);
        $this->assertContains(AppConfig::TABLE_ADMIN_USERS, $missing);
    }

    /**
     * 只读预检（cli/migrate.php --dry-run 的数据源）：空库 → 列出全部缺失表，
     * 且**确实没写库**（ci_app_settings 仍不存在，证明未触发建表/种子）。
     */
    public function testPendingChangesOnEmptyDatabaseReportsTablesAndStaysReadOnly(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();

        $pending = Database::pendingChanges($pdo);

        $this->assertNotEmpty($pending['missing_tables']);
        $this->assertContains(AppConfig::TABLE_APP_SETTINGS, $pending['missing_tables']);
        $this->assertSame([], $pending['missing_columns'], '整表缺失时不逐列报告（避免与「缺失表」重复）');

        // 只读证明：预检后 ci_app_settings 依然不存在
        $this->expectException(\PDOException::class);
        $pdo->query('SELECT 1 FROM ' . AppConfig::TABLE_APP_SETTINGS . ' LIMIT 1');
    }

    /** 只读预检：已建全的库 → 表与列都没有待补项（与 CLI「迁移后仍缺表」的校验口径一致）。 */
    public function testPendingChangesAfterBootstrapIsEmpty(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $pending = Database::pendingChanges($pdo);
        $this->assertSame([], $pending['missing_tables']);
        $this->assertSame([], $pending['missing_columns']);
    }

    /**
     * 只读预检：存量旧表（缺 columnMigrations 中的列）→ 该表出现在「缺失列」而非「缺失表」，
     * 并逐列列出待补字段。这正对应 --dry-run 最有用的场景：存量库升级前先看清会 ALTER 什么。
     */
    public function testPendingChangesReportsLegacyColumnsOfExistingTable(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        // 手工造一张「旧版」表：存在，但没有 status 列（status 在 columnMigrations 映射里）
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_JOB_GIT_MAP . ' (job_name TEXT PRIMARY KEY, git_platform TEXT)');

        $pending = Database::pendingChanges($pdo);

        $this->assertNotContains(AppConfig::TABLE_JOB_GIT_MAP, $pending['missing_tables'], '已存在的表不算缺失表');
        $this->assertArrayHasKey(AppConfig::TABLE_JOB_GIT_MAP, $pending['missing_columns'], '存在的旧表应逐列报告');
        $this->assertContains('status', $pending['missing_columns'][AppConfig::TABLE_JOB_GIT_MAP]);
    }

    /**
     * 预检与实跑同源：pendingChanges() 报出的缺列，跑一次迁移后必须全部补齐（否则预检会误导运维）。
     *
     * 用真实 SQLite 库文件 + Database::connect() 注入连接：migrateNow() 自带 connect()，
     * 只有让 self::$pdo 指向同一个库，才能验证「预检结果 == 迁移实际补齐的内容」。
     */
    public function testPendingChangesMatchesWhatMigrateNowFix(): void
    {
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'glue_dryrun_' . getmypid() . '.db';
        @unlink($dbPath);
        Database::reset();
        Database::init(['driver' => 'sqlite', 'path' => $dbPath, 'auto_migrate' => true]);

        $pdo = Database::connect(); // 只建连：起一个真实库文件并注入 self::$pdo
        // 手工造一张「旧版」表：存在，但缺 status 列
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_JOB_GIT_MAP . ' (job_name TEXT PRIMARY KEY, git_platform TEXT)');

        $before = Database::pendingChanges($pdo);
        $this->assertNotEmpty($before['missing_columns'], '预检应报出待补列');

        Database::migrateNow();

        $after = Database::pendingChanges($pdo);
        $this->assertSame([], $after['missing_tables']);
        $this->assertSame([], $after['missing_columns'], '迁移后预检必须清零（预检与实跑同源）');

        Database::reset();
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
    }

    /**
     * ci_app_settings 缺失（表没建）时 schemaState() 必须抛异常：探针据此判定「库不可用」（503），
     * 不能悄悄吞掉异常把空库报成 ok。
     */
    public function testSchemaStateThrowsWhenSettingsTableMissing(): void
    {
        $pdo = $this->createMemoryPdo();

        $this->expectException(\PDOException::class);
        Database::schemaState($pdo);
    }

    // ────────────── 降级（回滚）时的播种语义：merge（只补不删）vs prune（按当前代码收敛） ──────────────

    /**
     * 降级走「合并播种」：新版本授予系统角色的映射必须**保留**（不得被回滚顺手收窄），
     * 版本标记仍自愈为当前代码版本，并在改写前留 warning 痕迹。
     */
    public function testDowngradeUsesMergeSeedingAndKeepsNewerMappings(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        // 模拟「新版本」：给系统角色 viewer 多授一个权限
        $extra = $this->firstPermissionNotGrantedToViewer();
        $this->grantRolePermission($pdo, AppConfig::ROLE_VIEWER, $extra);

        // 模拟「代码被降级」：记录版本高于当前代码版本
        $this->setVersion($pdo, 'schema_version', '99.0.0');

        $this->reBootstrap($pdo, true); // 降级后的首个请求（自动建库模式）

        $this->assertTrue(
            $this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $extra),
            '降级走合并播种：新版本授予的映射必须保留，而不是被清空重建'
        );
        $this->assertSame(
            AppConfig::APP_VERSION,
            $this->version($pdo, 'schema_version'),
            '降级后版本标记仍会自愈为当前代码版本'
        );
    }

    /** 合并播种的另一半是「补」：当前代码期望的映射即使被删，降级后也要补回来（只补不删的两个方向）。 */
    public function testDowngradeMergeStillRestoresMappingsExpectedByCurrentCode(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $expected = AppConfig::DEFAULT_ROLES[AppConfig::ROLE_VIEWER][0];
        $this->revokeRolePermission($pdo, AppConfig::ROLE_VIEWER, $expected);
        $this->assertFalse($this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $expected), '前置：先制造缺失');

        $this->setVersion($pdo, 'schema_version', '99.0.0');
        $this->reBootstrap($pdo, true);

        $this->assertTrue(
            $this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $expected),
            '合并播种仍要把当前代码期望的映射补上'
        );
    }

    /** 正常升级方向不受影响：仍是 prune —— 系统角色的多余映射会被清理，权限以代码为准。 */
    public function testUpgradeStillPrunesToCurrentDefinitions(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $extra = $this->firstPermissionNotGrantedToViewer();
        $this->grantRolePermission($pdo, AppConfig::ROLE_VIEWER, $extra);

        $this->setVersion($pdo, 'schema_version', '0.0.0'); // 老版本标记 → 升级方向
        $this->reBootstrap($pdo, true);

        $this->assertFalse(
            $this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $extra),
            '升级方向必须保持 prune：多余映射按当前代码清理'
        );
    }

    /** 显式迁移（cli/migrate.php / 后台「同步库结构」）在降级场景下仍按当前代码收敛，避免「合并」被永久化。 */
    public function testExplicitMigrateOnDowngradeStillPrunes(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $extra = $this->firstPermissionNotGrantedToViewer();
        $this->grantRolePermission($pdo, AppConfig::ROLE_VIEWER, $extra);
        $this->setVersion($pdo, 'schema_version', '99.0.0');

        Database::migrateNow(); // 显式动作 = 权威种子

        $this->assertFalse(
            $this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $extra),
            '显式迁移应把权限收敛到当前代码定义（prune），而不是沿用合并结果'
        );
        $this->assertSame(AppConfig::APP_VERSION, $this->version($pdo, 'schema_version'));
    }

    /** 手动建库模式（DB_AUTO_MIGRATE=false）的降级同样走合并播种，并把 seed_version 自愈为当前版本。 */
    public function testManualModeDowngradeAlsoUsesMergeSeeding(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $extra = $this->firstPermissionNotGrantedToViewer();
        $this->grantRolePermission($pdo, AppConfig::ROLE_VIEWER, $extra);
        $this->setVersion($pdo, 'seed_version', '99.0.0'); // 手动模式以 seed_version 判定

        $this->reBootstrap($pdo, false);

        $this->assertTrue(
            $this->roleHasPermission($pdo, AppConfig::ROLE_VIEWER, $extra),
            '手动模式的降级同样只补不删'
        );
        $this->assertSame(AppConfig::APP_VERSION, $this->version($pdo, 'seed_version'));
    }


    // ────────────── 降级事件的审计留痕：写进操作日志（后台「操作日志」可见） ──────────────

    /** 降级必须落一条操作日志：动作 schema_downgrade、操作人 system、结果 failure、target=被降级到的版本。 */
    public function testDowngradeIsRecordedInOperationLog(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'schema_version', '99.0.0');
        $this->reBootstrap($pdo, true);

        $rows = $this->operationLogRows($pdo, 'schema_downgrade');
        $this->assertCount(1, $rows, '降级必须写一条操作日志（文件日志会轮转，审计表可供后台查看）');
        $this->assertSame('system', $rows[0]['username'], '系统级事件以 system 记录');
        $this->assertSame('system', $rows[0]['operator_type'], '操作人类型 system（与 cli/backup-db.php 约定一致）');
        $this->assertSame('failure', $rows[0]['result'], '「检测到异常」记为失败，便于按结果筛选');
        $this->assertSame('99.0.0', $rows[0]['target'], 'target=被降级到的版本（同时作为去重键）');

        $detail = json_decode((string)$rows[0]['detail'], true);
        $this->assertSame('merge', $detail['seed_mode'] ?? null, 'detail 记录本次播种模式，便于事后审计');
        $this->assertSame(AppConfig::APP_VERSION, $detail['current'] ?? null);
    }

    /** 同一个「被降级到的版本」只记一条：分布式部署下多进程各自检测不应刷屏；不同版本各记一条。 */
    public function testDowngradeOperationLogIsDeduplicatedPerVersion(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'schema_version', '99.0.0');
        $this->reBootstrap($pdo, true);
        $this->setVersion($pdo, 'schema_version', '99.0.0'); // 同一降级事件被再次检测
        $this->reBootstrap($pdo, true);
        $this->assertCount(1, $this->operationLogRows($pdo, 'schema_downgrade'), '同一 previous 版本不重复记录');

        $this->setVersion($pdo, 'schema_version', '98.0.0'); // 另一版本 = 另一事件
        $this->reBootstrap($pdo, true);
        $this->assertCount(2, $this->operationLogRows($pdo, 'schema_downgrade'), '不同 previous 版本各记一条');
    }

    /** 正常升级方向绝不能留下"降级"审计（否则操作日志会被误报污染）。 */
    public function testNormalUpgradeIsNotRecordedAsDowngrade(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'schema_version', '0.0.0'); // 老版本标记 → 升级方向
        $this->reBootstrap($pdo, true);

        $this->assertCount(0, $this->operationLogRows($pdo, 'schema_downgrade'), '升级不应记为降级');
    }

    // ────────────── 升 / 首次 也要留痕：版本跃迁审计（降级已有，见上一组） ──────────────

    /** 首次初始化建库 → schema_init（success，target 为空表示"此前无版本记录"）。 */
    public function testFirstBootIsRecordedAsSchemaInit(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo); // 空库首启

        $rows = $this->operationLogRows($pdo, 'schema_init');
        $this->assertCount(1, $rows, '首次建库应记一条 schema_init');
        $this->assertSame('system', $rows[0]['username']);
        $this->assertSame('system', $rows[0]['operator_type']);
        $this->assertSame('success', $rows[0]['result']);
        $this->assertSame('', $rows[0]['target'], '此前无版本记录 → target 为空');

        $detail = json_decode((string)$rows[0]['detail'], true);
        $this->assertSame('schema', $detail['scope'] ?? null, '自动模式是"结构+种子"');
        $this->assertNull($detail['previous'] ?? null);
        $this->assertSame(AppConfig::APP_VERSION, $detail['current'] ?? null);
    }

    /** 自动引导里的版本向前推进 → schema_upgrade（success，target=跃迁前版本）。 */
    public function testUpgradeIsRecordedAsSchemaUpgrade(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'schema_version', '0.0.0'); // 老版本标记 = 升级方向
        $this->reBootstrap($pdo, true);

        $rows = $this->operationLogRows($pdo, 'schema_upgrade');
        $this->assertCount(1, $rows, '升级应记一条 schema_upgrade');
        $this->assertSame('success', $rows[0]['result'], '升级是正常运维 → success');
        $this->assertSame('0.0.0', $rows[0]['target'], 'target=跃迁前版本');

        $detail = json_decode((string)$rows[0]['detail'], true);
        $this->assertSame('0.0.0', $detail['previous'] ?? null);
        $this->assertSame(AppConfig::APP_VERSION, $detail['current'] ?? null);
    }

    /** 手动建库模式：引导只补种子（seed_version 跃迁）→ 同样记 schema_upgrade，但 scope=seed 以示区分。 */
    public function testManualModeSeedTransitionIsRecordedWithSeedScope(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'seed_version', '0.0.0');
        $this->reBootstrap($pdo, false); // 手动建库模式

        $rows = $this->operationLogRows($pdo, 'schema_upgrade');
        $this->assertCount(1, $rows);
        $detail = json_decode((string)$rows[0]['detail'], true);
        $this->assertSame('seed', $detail['scope'] ?? null, '手动模式引导只补种子 → scope=seed');
        $this->assertSame('seed_version', $detail['key'] ?? null);
    }

    /**
     * 显式迁移（`cli/migrate.php` / 后台按钮）**不写**版本跃迁记录——它记录的是"操作"本身
     * （`migrate_schema`，按钮路径在 AdminController、CLI 路径在 cli/migrate.php），避免一次操作两行日志。
     */
    public function testExplicitMigrateDoesNotRecordTransition(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        $this->setVersion($pdo, 'schema_version', '0.0.0');
        Database::migrateNow(); // 显式动作

        $this->assertCount(0, $this->operationLogRows($pdo, 'schema_upgrade'), '显式迁移不该再记跃迁');
        $this->assertCount(0, $this->operationLogRows($pdo, 'schema_downgrade'));
        $this->assertSame(AppConfig::APP_VERSION, $this->version($pdo, 'schema_version'), '但标记仍要对齐');
    }

    // ────────────── seedRbac 部分失败必须 fail-fast（不能再"记日志继续"） ──────────────

    /**
     * 部分失败必须 fail-fast：用 SQLite 触发器让角色权限的清理（prune DELETE）必然失败，
     * 断言 ①抛异常 ②版本标记未被推进（下次引导重试）③文件日志 + 操作日志（`rbac_seed_failed`）都留痕
     * ④同一次失败在去重窗口内不重复写审计（否则失败期间每个请求都会刷一行）。
     */
    public function testPartialSeedFailureThrowsAndKeepsVersionMarker(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);

        // 让 role_permissions 的任何删除都失败 → prune 阶段产生部分失败（模拟"种子只播了一半"）
        $pdo->exec(
            'CREATE TRIGGER fail_rp_delete BEFORE DELETE ON ' . AppConfig::TABLE_ROLE_PERMISSIONS
            . " BEGIN SELECT RAISE(ABORT, 'forced seed failure'); END"
        );
        $this->setVersion($pdo, 'schema_version', '0.0.0'); // 制造"需要重新播种"

        $caught = null;
        try {
            $this->reBootstrap($pdo, true);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '部分失败必须抛异常，而不是静默继续');
        $this->assertStringContainsString('RBAC 种子部分失败', $caught->getMessage());
        $this->assertSame('0.0.0', $this->version($pdo, 'schema_version'), '失败后不得推进版本标记');

        $rows = $this->operationLogRows($pdo, 'rbac_seed_failed');
        $this->assertCount(1, $rows, '部分失败要写进操作日志');
        $this->assertSame('failure', $rows[0]['result']);
        $this->assertSame('system', $rows[0]['username']);
        $detail = json_decode((string)$rows[0]['detail'], true);
        $this->assertGreaterThanOrEqual(1, (int)($detail['count'] ?? 0), 'detail.count 记录失败项数');
        $this->assertStringContainsString('forced', (string)($detail['sample'][0]['error'] ?? ''), 'sample 带真实错误便于排障');

        // 去重：失败持续存在时（每个请求都会重试）审计表不能被刷爆
        try {
            $this->reBootstrap($pdo, true);
        } catch (\RuntimeException $e) {
            // 预期再次失败
        }
        $this->assertCount(1, $this->operationLogRows($pdo, 'rbac_seed_failed'), '去重窗口内同一次失败只记一条');
    }

    /**
     * 读某个 action 的操作日志行（按 id 正序）。
     *
     * @param \PDO   $pdo
     * @param string $action
     * @return list<array<string,mixed>>
     */
    private function operationLogRows(\PDO $pdo, string $action): array
    {
        $stmt = $pdo->prepare(
            'SELECT username, action, target, detail, result, operator_type'
            . ' FROM ' . AppConfig::TABLE_OPERATION_LOGS . ' WHERE action = ? ORDER BY id'
        );
        $stmt->execute([$action]);
        return $stmt->fetchAll();
    }


    /**
     * 构造「纯手工建库 + DB_AUTO_MIGRATE=false」的库：先用自动模式建全表，
     * 再清掉 schema_version，等价于运维只跑了 database/*.sql（脚本不含该键），
     * 最后按手动模式引导一次。
     */
    private function createManualModePdo(): \PDO
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->createMemoryPdo();
        Database::bootstrap($pdo);
        $pdo->exec('DELETE FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'schema_version'");

        $this->bootstrapManualMode($pdo);
        return $pdo;
    }

    private function bootstrapManualMode(\PDO $pdo): void
    {
        $this->reBootstrap($pdo, false);
    }

    /** viewer 未被授予的某个权限键（用于模拟「新版本多授的权限」）。 */
    private function firstPermissionNotGrantedToViewer(): string
    {
        foreach (array_keys(AppConfig::DEFAULT_PERMISSIONS) as $key) {
            if (!in_array($key, AppConfig::DEFAULT_ROLES[AppConfig::ROLE_VIEWER], true)) {
                return $key;
            }
        }
        $this->fail('DEFAULT_ROLES[viewer] 不应覆盖全部权限');
    }

    private function grantRolePermission(\PDO $pdo, string $role, string $permKey): void
    {
        $pdo->prepare(
            'INSERT OR IGNORE INTO ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' (role_id, perm_key)'
            . ' SELECT id, ? FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?'
        )->execute([$permKey, $role]);
    }

    private function revokeRolePermission(\PDO $pdo, string $role, string $permKey): void
    {
        $pdo->prepare(
            'DELETE FROM ' . AppConfig::TABLE_ROLE_PERMISSIONS
            . ' WHERE perm_key = ? AND role_id = (SELECT id FROM ' . AppConfig::TABLE_ROLES . ' WHERE name = ?)'
        )->execute([$permKey, $role]);
    }

    private function roleHasPermission(\PDO $pdo, string $role, string $permKey): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' rp'
            . ' JOIN ' . AppConfig::TABLE_ROLES . ' r ON r.id = rp.role_id'
            . ' WHERE r.name = ? AND rp.perm_key = ? LIMIT 1'
        );
        $stmt->execute([$role, $permKey]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * 设置某个版本键的值（**upsert**，不是 UPDATE）。
     *
     * 必须用 upsert：自动建库模式只写 `schema_version`、不写 `seed_version`，
     * 若用 UPDATE 去改 seed_version 会匹配 0 行，手动模式就会把它当成"升级"而不是"降级"。
     */
    private function setVersion(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare(
            'INSERT OR REPLACE INTO ' . AppConfig::TABLE_APP_SETTINGS . ' (setting_key, value, updated_at)'
            . ' VALUES (?, ?, 0)'
        )->execute([$key, $value]);
    }

    private function version(\PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value FROM ' . AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        return ($row === false) ? null : (string) $row;
    }

    /** 模拟「新进程再引导一次」：同一 PDO，清掉进程内幂等守卫。 */
    private function reBootstrap(\PDO $pdo, bool $autoMigrate): void
    {
        Database::reset();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => $autoMigrate]);
        Database::bootstrap($pdo);
    }

    private function createMemoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }
}
