<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AdminUserRepository;
use PHPUnit\Framework\TestCase;

class AdminUserRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER'] = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['ADMIN_EMAIL']);
        parent::tearDown();
    }

    /** 读取某用户的 email 原始值（findUser 只返回 username/role） */
    private function emailOf(\PDO $pdo, string $username): ?string
    {
        $stmt = $pdo->prepare('SELECT email FROM ' . AppConfig::TABLE_ADMIN_USERS . ' WHERE username = ?');
        $stmt->execute([$username]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : ($v === null ? null : (string)$v);
    }

    public function testSeedAdminWritesAdminEmailWhenConfigured(): void
    {
        $_ENV['ADMIN_EMAIL'] = 'ops@example.com';
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        $this->assertSame('ops@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminDefaultsToPlaceholderEmail(): void
    {
        // 未配置 ADMIN_EMAIL 时，落占位地址，保证 SSO 的 userinfo/id_token 有 email 可用
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        $this->assertSame('admin@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminIgnoresMalformedAdminEmail(): void
    {
        $_ENV['ADMIN_EMAIL'] = 'not-an-email';
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        // 脏值不得落库：回落到占位地址
        $this->assertSame('admin@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminBackfillsEmptyEmailOnExistingDatabase(): void
    {
        // 先建号（无 ADMIN_EMAIL），模拟存量库
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);
        $this->assertSame('admin@example.com', $this->emailOf($pdo, 'admin'));

        // 事后配置 ADMIN_EMAIL，再次启动应回填（仅 email 为空时）
        $_ENV['ADMIN_EMAIL'] = 'ops@example.com';
        AdminUserRepository::seedAdminFromEnv($pdo);

        $this->assertSame('ops@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminDoesNotOverwriteExistingEmail(): void
    {
        $_ENV['ADMIN_EMAIL'] = 'seed@example.com';
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        // 用户在后台把邮箱改成了别的，之后重启不得被 ADMIN_EMAIL 覆盖回去
        (new AdminUserRepository($pdo))->updateUser('admin', null, null, 'changed@example.com');
        AdminUserRepository::seedAdminFromEnv($pdo);

        $this->assertSame('changed@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminCreatesSuperAdminRootUserWhenTableIsEmpty(): void
    {
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        $row = $pdo->query('SELECT username, role, systems FROM ' . AppConfig::TABLE_ADMIN_USERS)->fetch();

        $this->assertNotFalse($row);
        $this->assertEquals('admin', $row['username']);
        $this->assertEquals(AppConfig::ROLE_SUPER_ADMIN, $row['role']);
        $this->assertEquals('ci,cd', $row['systems']);
    }

    public function testSeedAdminUpgradesExistingAdminRoleToSuperAdmin(): void
    {
        $pdo = $this->createMemoryDatabase();
        $stmt = $pdo->prepare('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, password_hash, role, systems) VALUES (?, ?, ?, ?)');
        $stmt->execute(['admin', password_hash('secret', PASSWORD_BCRYPT), AppConfig::ROLE_ADMIN, 'ci,cd']);

        AdminUserRepository::seedAdminFromEnv($pdo);

        $row = $pdo->query('SELECT role FROM ' . AppConfig::TABLE_ADMIN_USERS . ' WHERE username = "admin"')->fetch();

        $this->assertNotFalse($row);
        $this->assertEquals(AppConfig::ROLE_SUPER_ADMIN, $row['role']);
    }

    public function testCreateUserAndFindUser(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);

        $this->assertFalse($repo->userExists('alice'));

        $repo->createUser('alice', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $this->assertTrue($repo->userExists('alice'));

        $user = $repo->findUser('alice');
        $this->assertNotNull($user);
        $this->assertEquals('alice', $user['username']);
        $this->assertEquals(AppConfig::ROLE_DEPLOYER, $user['role']);
    }

    public function testListUsersExcludesAdminAndSuperAdmin(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('admin', password_hash('adminpass', PASSWORD_BCRYPT), AppConfig::ROLE_ADMIN, 'ci,cd');
        $repo->createUser('root', password_hash('rootpass', PASSWORD_BCRYPT), AppConfig::ROLE_SUPER_ADMIN, 'ci,cd');
        $repo->createUser('bob', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $users = $repo->listUsers(false);
        $this->assertCount(1, $users);
        $this->assertEquals('bob', $users[0]['username']);
    }

    public function testUpdateUserChangesPasswordAndRole(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('carol', password_hash('oldpass123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $repo->updateUser('carol', password_hash('newpass456', PASSWORD_BCRYPT), AppConfig::ROLE_ADMIN);

        $updated = $repo->findUser('carol');
        $this->assertNotNull($updated);
        $this->assertEquals(AppConfig::ROLE_ADMIN, $updated['role']);
    }

    public function testUpdateUserThrowsWhenUserMissing(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);

        $this->expectException(\RuntimeException::class);
        $repo->updateUser('ghost', null, AppConfig::ROLE_ADMIN);
    }

    /**
     * 幂等重提交不得误报「未命中用户」。
     *
     * 存在性必须靠 userExists() 判定，不能靠 rowCount()：MySQL 下 rowCount 是「变更行数」，
     * 同一秒内用相同 role/email 重复提交（updated_at 的 NOW() 为秒级精度也不变）会返回 0，
     * 对存在的用户误抛异常。SQLite 的 changes() 语义不同，本用例在 sqlite 上属回归护栏、
     * 真正的判别环境是 MySQL。
     */
    public function testUpdateUserIsIdempotentOnUnchangedValues(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('erin', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $repo->updateUser('erin', null, AppConfig::ROLE_ADMIN, 'erin@example.com');
        $repo->updateUser('erin', null, AppConfig::ROLE_ADMIN, 'erin@example.com');

        $updated = $repo->findUser('erin');
        $this->assertNotNull($updated);
        $this->assertEquals(AppConfig::ROLE_ADMIN, $updated['role']);
    }

    public function testDeleteUserRemovesUser(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('dave', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $this->assertTrue($repo->userExists('dave'));
        $repo->deleteUser('dave');
        $this->assertFalse($repo->userExists('dave'));
    }

    public function testUpdatePasswordPreservesRoleAndSystems(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        // 超级管理员，systems 非默认值，用于验证不会被回退
        $repo->createUser('root', password_hash('oldpass123', PASSWORD_BCRYPT), AppConfig::ROLE_SUPER_ADMIN, 'cd');

        $repo->updatePassword('root', password_hash('newpass456', PASSWORD_BCRYPT));

        $row = $pdo->query('SELECT password_hash, role, systems FROM ' . AppConfig::TABLE_ADMIN_USERS . ' WHERE username = "root"')->fetch();
        $this->assertNotFalse($row);
        $this->assertTrue(password_verify('newpass456', $row['password_hash']));
        $this->assertSame(AppConfig::ROLE_SUPER_ADMIN, $row['role'], '重置密码不得把 super_admin 降级');
        $this->assertSame('cd', $row['systems'], '重置密码不得改写 systems');
    }

    public function testUpdatePasswordThrowsForUnknownUser(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);

        $this->expectException(\RuntimeException::class);
        $repo->updatePassword('ghost', password_hash('x', PASSWORD_BCRYPT));
    }

    public function testSetStatusTogglesAndRetoggleDoesNotThrow(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('grace', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $repo->setStatus('grace', 0);
        $this->assertSame(0, (int)$repo->findByUsername('grace')['status']);

        // 同一秒内重复 toggle（status 值未变）不得误报「未命中用户」——这正是 rowCount 方案会误伤的场景
        $repo->setStatus('grace', 0);
        $repo->setStatus('grace', 1);
        $this->assertSame(1, (int)$repo->findByUsername('grace')['status']);
    }

    public function testSetStatusThrowsForUnknownUser(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);

        $this->expectException(\RuntimeException::class);
        $repo->setStatus('ghost', 0);
    }

    public function testCountByRoleAndUpdateRoleName(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);
        $repo->createUser('eve', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');
        $repo->createUser('frank', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $this->assertEquals(2, $repo->countByRole(AppConfig::ROLE_DEPLOYER));

        $repo->updateRoleName(AppConfig::ROLE_DEPLOYER, 'custom_deployer');
        $this->assertEquals(0, $repo->countByRole(AppConfig::ROLE_DEPLOYER));
        $this->assertEquals(2, $repo->countByRole('custom_deployer'));
    }

    public function testRoleExistsChecksRolesTable(): void
    {
        $pdo = $this->createMemoryDatabaseWithRoles();
        $repo = new AdminUserRepository($pdo);

        // 真实存在的角色 → true
        $pdo->exec("INSERT INTO " . AppConfig::TABLE_ROLES . " (name, description, is_system) VALUES ('deployer', '', 0)");
        $this->assertTrue($repo->roleExists('deployer'));

        // 不存在的角色 → false（建号/改号应据此拦下悬空角色名）
        $this->assertFalse($repo->roleExists('ghost_role'));
    }

    private function createMemoryDatabaseWithRoles(): \PDO
    {
        $pdo = $this->createMemoryDatabase();
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ROLES . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            is_system INTEGER NOT NULL DEFAULT 0
        )');
        return $pdo;
    }

    private function createMemoryDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "' . AppConfig::ROLE_ADMIN . '",
            systems TEXT NOT NULL DEFAULT "ci,cd",
            email TEXT NOT NULL DEFAULT "",
            status INTEGER NOT NULL DEFAULT 1,
            avatar_url TEXT,
            created_at TEXT,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        return $pdo;
    }
}
