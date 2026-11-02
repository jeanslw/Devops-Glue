<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AdminRbacService;
use PHPUnit\Framework\TestCase;

/**
 * AdminRbacService 隐含规则相关单测：环检测与「按被删规则定向剪枝」。
 *
 * 依赖 SQLite 内存库（自建 ci_implied_rules / ci_roles / ci_role_permissions），不触网。
 */
class AdminRbacServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_IMPLIED_RULES . ' (source_key TEXT, target_key TEXT, PRIMARY KEY(source_key, target_key))');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ROLES . ' (id INTEGER PRIMARY KEY, is_system INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' (role_id INTEGER, perm_key TEXT, PRIMARY KEY(role_id, perm_key))');
    }

    private function svc(): AdminRbacService
    {
        return new AdminRbacService($this->pdo);
    }

    private function addRule(string $s, string $t): void
    {
        $this->pdo->prepare('INSERT INTO ' . AppConfig::TABLE_IMPLIED_RULES . ' (source_key, target_key) VALUES (?, ?)')->execute([$s, $t]);
    }

    private function addRole(int $id, bool $system = false): void
    {
        $this->pdo->prepare('INSERT INTO ' . AppConfig::TABLE_ROLES . ' (id, is_system) VALUES (?, ?)')->execute([$id, $system ? 1 : 0]);
    }

    private function addPerm(int $role, string $perm): void
    {
        $this->pdo->prepare('INSERT INTO ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' (role_id, perm_key) VALUES (?, ?)')->execute([$role, $perm]);
    }

    private function permsOf(int $role): array
    {
        return $this->pdo->query('SELECT perm_key FROM ' . AppConfig::TABLE_ROLE_PERMISSIONS . ' WHERE role_id = ' . $role)->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testCycleDetectionCatchesTransitiveLoop(): void
    {
        $this->addRule('a', 'b');
        $this->addRule('b', 'c');
        $this->assertTrue($this->svc()->wouldCreateImpliedCycle('c', 'a'), 'c→a 应经 c→b→a 成环');
        $this->assertFalse($this->svc()->wouldCreateImpliedCycle('a', 'b'), '已存在规则不应判为新环');
        $this->assertFalse($this->svc()->wouldCreateImpliedCycle('x', 'a'), '无环路径不应误判');
    }

    public function testSelfRuleIsCycle(): void
    {
        $this->assertTrue($this->svc()->wouldCreateImpliedCycle('a', 'a'));
    }

    public function testPruneRemovesOnlyDerivedByDeletedRule(): void
    {
        $this->addRule('a', 'b');
        $this->addRole(1);
        $this->addPerm(1, 'a');
        $this->addPerm(1, 'b');
        $cleaned = $this->svc()->pruneImpliedPermissionForDeletedRule('a', 'b');
        $this->assertSame(1, $cleaned);
        $this->assertSame(['a'], $this->permsOf(1));
    }

    public function testPruneKeepsExplicitGrant(): void
    {
        $this->addRule('x', 'y');
        $this->addRole(1);
        $this->addPerm(1, 'b');
        $cleaned = $this->svc()->pruneImpliedPermissionForDeletedRule('x', 'y');
        $this->assertSame(0, $cleaned);
        $this->assertSame(['b'], $this->permsOf(1));
    }

    public function testPruneKeepsWhenDerivedViaOtherRule(): void
    {
        $this->addRule('a', 'b');
        $this->addRule('c', 'b');
        $this->addRole(1);
        $this->addPerm(1, 'a');
        $this->addPerm(1, 'c');
        $this->addPerm(1, 'b');
        $cleaned = $this->svc()->pruneImpliedPermissionForDeletedRule('a', 'b');
        $this->assertSame(0, $cleaned);
        $this->assertSame(['a', 'b', 'c'], $this->permsOf(1));
    }
}
