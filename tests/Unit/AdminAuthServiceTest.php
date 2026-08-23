<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use PHPUnit\Framework\TestCase;

/**
 * AdminAuthService 回归测试（内存 SQLite）
 *
 * 锁定 #4 修复：Web 登录以 DB 为唯一权威，砍掉「root 3 次失败后 .env 密码兜底」的明文旁路。
 * 仅剩两种场景允许 .env 根密码：DB 不可访问（灾难恢复）、DB 可访问但尚无任何账号（首次部署）。
 */
class AdminAuthServiceTest extends TestCase
{
    private \PDO $pdo;
    private AdminUserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            systems TEXT NOT NULL,
            email TEXT NOT NULL DEFAULT "",
            updated_at TEXT
        )');
        $this->repo = new AdminUserRepository($this->pdo);
    }

    private function makeService(array $adminCfg = [], ?AdminUserRepository $repo = null): AdminAuthService
    {
        return new AdminAuthService($this->pdo, $repo ?? $this->repo, new AppConfig($adminCfg));
    }

    private function seedRoot(string $username, string $password): void
    {
        $this->repo->createUser($username, password_hash($password, PASSWORD_BCRYPT), AppConfig::ROLE_SUPER_ADMIN, 'ci,cd');
    }

    public function testDbPasswordWinsWhenRootExists(): void
    {
        // DB 里已有根账号（.env 密码不同）
        $this->seedRoot('admin', 'dbpass1234');
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);

        // DB 密码 → 正常登录
        $ok = $service->authenticate('admin', 'dbpass1234', AppConfig::SYSTEM_CI);
        $this->assertTrue($ok['success']);
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $ok['role']);

        // .env 明文密码 → 必须拒绝（否则等于常开明文旁路）
        $env = $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
        $this->assertFalse($env['success'], 'DB 已有 root 时 .env 密码不得登录');
    }

    public function testEnvFallbackWhenNoAdminUsers(): void
    {
        // 首次部署：admin_users 为空 → 允许 .env 根密码
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);
        $result = $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
        $this->assertTrue($result['success']);
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $result['role']);
        $this->assertTrue($result['isRoot']);
    }

    public function testEnvFallbackWhenDbDown(): void
    {
        // 覆盖 repository：findByUsername 抛异常 → 模拟 DB 完全不可访问（灾难恢复仍允许 .env 根密码）
        $brokenRepo = new class($this->pdo) extends AdminUserRepository {
            public function findByUsername(string $username): ?array
            {
                throw new \RuntimeException('db down');
            }
        };
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']], $brokenRepo);

        $result = $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
        $this->assertTrue($result['success'], 'DB 不可访问时应允许 .env 根密码灾难恢复');
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $result['role']);
    }

    public function testWrongCredentialsStillRejected(): void
    {
        $this->seedRoot('admin', 'dbpass1234');
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);

        $result = $service->authenticate('admin', 'totally_wrong', AppConfig::SYSTEM_CI);
        $this->assertFalse($result['success']);
    }

    public function testVerifyCurrentPasswordAcceptsDbPasswordOnlyWhenRootExists(): void
    {
        $this->seedRoot('admin', 'dbpass1234');
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);

        $this->assertTrue($service->verifyCurrentPassword('admin', 'dbpass1234'));
        $this->assertFalse($service->verifyCurrentPassword('admin', 'envpass1234'), '改密码校验同样不应接受 .env 明文密码');
    }
}
