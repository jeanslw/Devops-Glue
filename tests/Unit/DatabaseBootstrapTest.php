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
