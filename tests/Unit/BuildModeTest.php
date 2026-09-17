<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * 构建模式集合（多选）单元测试
 *
 * 锁定 build_mode 从单值枚举到「已启用 CI 源集合」的解析/存储规则：
 *   - 旧值 both → jenkins,gitlab_ci（读取时自愈回写）
 *   - 新格式逗号分隔，规范顺序恒为 jenkins,gitlab_ci,gitea_ci
 *   - 空值 = 无拉取式 CI；未知值丢弃
 *
 * 依赖 SQLite 内存库（ci_app_settings 表），不触网。
 * 运行：vendor/bin/phpunit tests/Unit/BuildModeTest.php
 */
class BuildModeTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT
        )');
    }

    private function seedBuildMode(string $value): void
    {
        $this->pdo->prepare("INSERT INTO " . AppConfig::TABLE_APP_SETTINGS . " (setting_key, value) VALUES ('build_mode', ?)")->execute([$value]);
    }

    private function storedBuildMode(): string
    {
        $row = $this->pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
        return $row ? (string) $row['value'] : '';
    }

    private function cfg(): AppConfig
    {
        return new AppConfig([], $this->pdo);
    }

    public function testLegacyBothMapsToJenkinsAndGitlabAndSelfHeals(): void
    {
        $this->seedBuildMode('both');
        $this->assertSame(['jenkins', 'gitlab_ci'], $this->cfg()->getBuildModes());
        $this->assertSame('jenkins,gitlab_ci', $this->storedBuildMode(), '旧值 both 读取时自愈回写为新格式');
    }

    public function testLegacySingleValue(): void
    {
        $this->seedBuildMode('gitlab_ci');
        $this->assertSame(['gitlab_ci'], $this->cfg()->getBuildModes());
    }

    public function testNewCommaFormatIsCanonicalOrder(): void
    {
        $this->seedBuildMode('jenkins,gitlab_ci,gitea_ci');
        $this->assertSame(['jenkins', 'gitlab_ci', 'gitea_ci'], $this->cfg()->getBuildModes());
        $this->assertSame('jenkins,gitlab_ci,gitea_ci', $this->cfg()->getBuildMode());
    }

    public function testSetBuildModesSortsToCanonicalOrder(): void
    {
        $this->cfg()->setBuildModes(['gitea_ci', 'jenkins']);
        $this->assertSame(['jenkins', 'gitea_ci'], $this->cfg()->getBuildModes());
        $this->assertSame('jenkins,gitea_ci', $this->storedBuildMode());
    }

    public function testEmptyValueMeansNoPullCi(): void
    {
        $this->seedBuildMode('');
        $this->assertSame([], $this->cfg()->getBuildModes());
        $this->assertSame('', $this->cfg()->getBuildMode());
    }

    public function testUnknownValuesDropped(): void
    {
        $this->seedBuildMode('jenkins,unknown_provider,gitea_ci');
        $this->assertSame(['jenkins', 'gitea_ci'], $this->cfg()->getBuildModes());
    }
}
