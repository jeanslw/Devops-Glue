<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\Settings;
use PHPUnit\Framework\TestCase;

/**
 * AdminAuthService 回归测试（内存 SQLite）
 *
 * 锁定 #4 修复：Web 登录以 DB 为唯一权威，去掉 root 3 次失败后回退 .env 密码的明文旁路。
 * 仅剩一种场景允许 .env 根密码：DB 可访问但尚无任何账号（首次部署）。DB 不可访问时直接抛异常。
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
            status INTEGER NOT NULL DEFAULT 1,
            avatar_url TEXT,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )');
        $this->repo = new AdminUserRepository($this->pdo);
    }

    private function makeService(array $adminCfg = [], ?AdminUserRepository $repo = null): AdminAuthService
    {
        return new AdminAuthService($this->pdo, $repo ?? $this->repo, new Settings($adminCfg));
    }

    private function seedRoot(string $username, string $password): void
    {
        $this->repo->createUser($username, password_hash($password, PASSWORD_BCRYPT), AppConfig::ROLE_SUPER_ADMIN, 'ci,cd');
    }

    public function testDbPasswordWinsWhenRootExists(): void
    {
        
        $this->seedRoot('admin', 'dbpass1234');
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);

        
        $ok = $service->authenticate('admin', 'dbpass1234', AppConfig::SYSTEM_CI);
        $this->assertTrue($ok['success']);
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $ok['role']);

        
        $env = $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
        $this->assertFalse($env['success'], 'DB 已有 root 时 .env 密码不得登录');
    }

    public function testEnvFallbackWhenNoAdminUsers(): void
    {
        
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);
        $result = $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
        $this->assertTrue($result['success']);
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $result['role']);
        $this->assertTrue($result['isRoot']);
    }

    public function testDbDownThrowsInsteadOfEnvFallback(): void
    {
        
        
        $brokenRepo = new class($this->pdo) extends AdminUserRepository {
            public function findByUsername(string $username): ?array
            {
                throw new \RuntimeException('db down');
            }
        };
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']], $brokenRepo);

        $this->expectException(\RuntimeException::class);
        $service->authenticate('admin', 'envpass1234', AppConfig::SYSTEM_CI);
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

    public function testDisabledAccountReportsDisabledNotWrongPassword(): void
    {
        $this->seedRoot('admin', 'dbpass1234');
        $this->repo->setStatus('admin', 0); 
        $service = $this->makeService(['admin' => ['user' => 'admin', 'password' => 'envpass1234']]);

        
        $correct = $service->authenticate('admin', 'dbpass1234', AppConfig::SYSTEM_CI);
        $this->assertFalse($correct['success']);
        $this->assertSame('auth.user_disabled', $correct['errorKey']);

        
        $wrong = $service->authenticate('admin', 'totally_wrong', AppConfig::SYSTEM_CI);
        $this->assertFalse($wrong['success']);
        $this->assertSame('auth.user_disabled', $wrong['errorKey']);
    }
}
