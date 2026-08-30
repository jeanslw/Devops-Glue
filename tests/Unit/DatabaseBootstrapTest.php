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

        // 只读 = 恰好 8 个纯读视图 key（6 CD + 2 CI），无任何写 key，尤其不含 cd.image-registry 与 ci.manage
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
        ], $keys, 'viewer 应恰好拥有 8 个纯读视图 key（6 CD + 2 CI）');
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

    private function createMemoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }
}
