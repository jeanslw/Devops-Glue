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
     * ci_app_settings 缺失（表没建）时 schemaState() 必须抛异常：探针据此判定「库不可用」（503），
     * 不能悄悄吞掉异常把空库报成 ok。
     */
    public function testSchemaStateThrowsWhenSettingsTableMissing(): void
    {
        $pdo = $this->createMemoryPdo();

        $this->expectException(\PDOException::class);
        Database::schemaState($pdo);
    }

    /**
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
        Database::reset();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => false]);
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
