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
        
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['ADMIN_EMAIL'], $_ENV['LOG_PATH']);
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

    public function testSeedAdminDefaultsToDeploymentSpecificPlaceholderEmail(): void
    {
        
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        $email = $this->emailOf($pdo, 'admin');
        $this->assertNotNull($email);
        $this->assertMatchesRegularExpression('/^admin@.+\.local$/', $email);
        $this->assertNotSame('admin@example.com', $email);
    }

    public function testSeedAdminIgnoresMalformedAdminEmail(): void
    {
        $_ENV['ADMIN_EMAIL'] = 'not-an-email';
        $pdo = $this->createMemoryDatabase();
        AdminUserRepository::seedAdminFromEnv($pdo);

        
        $email = $this->emailOf($pdo, 'admin');
        $this->assertNotNull($email);
        $this->assertMatchesRegularExpression('/^admin@.+\.local$/', $email);
        $this->assertNotSame('admin@example.com', $email);
    }

    public function testSeedAdminBackfillsEmptyEmailOnExistingDatabase(): void
    {
        
        $pdo = $this->createMemoryDatabase();
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, password_hash, role, systems, email) VALUES (?, ?, ?, ?, ?)')
            ->execute(['admin', $hash, AppConfig::ROLE_SUPER_ADMIN, 'ci,cd', '']);

        $_ENV['ADMIN_EMAIL'] = 'ops@example.com';
        AdminUserRepository::seedAdminFromEnv($pdo);

        $this->assertSame('ops@example.com', $this->emailOf($pdo, 'admin'));
    }

    public function testSeedAdminDoesNotOverwriteExistingEmail(): void
    {
        $pdo = $this->createMemoryDatabase();
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, password_hash, role, systems, email) VALUES (?, ?, ?, ?, ?)')
            ->execute(['admin', $hash, AppConfig::ROLE_SUPER_ADMIN, 'ci,cd', 'changed@example.com']);

        $_ENV['ADMIN_EMAIL'] = 'seed@example.com';
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

    public function testCreateUserNormalizesUsernameToLowercase(): void
    {
        $pdo = $this->createMemoryDatabase();
        $repo = new AdminUserRepository($pdo);

        $repo->createUser('Alice', password_hash('password123', PASSWORD_BCRYPT), AppConfig::ROLE_DEPLOYER, 'cd');

        $this->assertTrue($repo->userExists('alice'));
        $this->assertTrue($repo->userExists('Alice'));

        $user = $repo->findUser('alice');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
        $this->assertSame(AppConfig::ROLE_DEPLOYER, $user['role']);
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
     * 幂等重提交不得误报未命中用户。
     *
     * 存在性必须靠 userExists() 判定，不能靠 rowCount()：MySQL 下 rowCount 是变更行数，
     * 同一秒内用相同 role/email 重复提交（updated_at 的 NOW() 为秒级精度也不变）会返回 0，
     * 对存在的用户误抛异常。SQLite 的 changes() 语义不同，本用例在 SQLite 上主要用于回归，
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

    public function testCanonicalRoleReturnsStoredName(): void
    {
        $pdo = $this->createMemoryDatabaseWithRoles();
        $repo = new AdminUserRepository($pdo);

        
        $pdo->exec("INSERT INTO " . AppConfig::TABLE_ROLES . " (name, description, is_system) VALUES ('deployer', '', 0)");
        $this->assertSame('deployer', $repo->canonicalRole('deployer'));

        
        $this->assertNull($repo->canonicalRole('ghost_role'));
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
            must_change_password INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        return $pdo;
    }
}
