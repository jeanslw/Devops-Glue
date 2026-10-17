<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use PHPUnit\Framework\TestCase;

/**
 * 构建模式集合（多选）单元测试
 *
 * 覆盖 build_mode 从单值枚举到已启用 CI 源集合的解析与存储规则：
 *   - 旧值 both 解析为 jenkins,gitlab_ci（读取时回写为新格式）
 *   - 新格式为逗号分隔，规范顺序恒为 jenkins,gitlab_ci,gitea_ci
 *   - 空值表示无拉取式 CI；未知值丢弃
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

    private function storedGitCiEnabled(): string
    {
        $row = $this->pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'git_ci_enabled'")->fetch();
        return $row ? (string) $row['value'] : '';
    }

    private function repo(): AppSettingRepository
    {
        return new AppSettingRepository($this->pdo);
    }

    public function testLegacyBothMapsToJenkinsAndGitlabAndSelfHeals(): void
    {
        $this->seedBuildMode('both');
        $this->assertSame(['jenkins', 'gitlab_ci'], $this->repo()->getBuildModes());
        // 二元拆分：git 平台 CI 迁入 git_ci_enabled，build_mode 只留独立 CI
        $this->assertSame('jenkins', $this->storedBuildMode(), '旧值 both 读取时自愈并拆分 git CI');
        $this->assertSame('gitlab', $this->storedGitCiEnabled());
    }

    public function testLegacySingleValue(): void
    {
        $this->seedBuildMode('gitlab_ci');
        $this->assertSame(['gitlab_ci'], $this->repo()->getBuildModes());
    }

    public function testNewCommaFormatIsCanonicalOrder(): void
    {
        $this->seedBuildMode('jenkins,gitlab_ci,gitea_ci');
        $this->assertSame(['jenkins', 'gitlab_ci', 'gitea_ci'], $this->repo()->getBuildModes());
        $this->assertSame('jenkins,gitlab_ci,gitea_ci', $this->repo()->getBuildMode());
    }

    public function testSetBuildModesSortsToCanonicalOrder(): void
    {
        $this->repo()->setBuildModes(['gitea_ci', 'jenkins']);
        $this->assertSame(['jenkins', 'gitea_ci'], $this->repo()->getBuildModes());
        $this->assertSame('jenkins', $this->storedBuildMode(), 'build_mode 只存独立 CI');
        $this->assertSame('gitea', $this->storedGitCiEnabled());
    }

    public function testGitCiSplitRoundTrip(): void
    {
        $this->repo()->setBuildModes(['jenkins', 'gitlab_ci', 'gitea_ci']);
        $this->assertSame(['jenkins', 'gitlab_ci', 'gitea_ci'], $this->repo()->getBuildModes());
        $this->assertSame('jenkins', $this->storedBuildMode());
        $this->assertSame('gitlab,gitea', $this->storedGitCiEnabled());
        $this->assertSame(['gitlab', 'gitea'], $this->repo()->getGitCiEnabledPlatforms());
    }

    public function testSetGitCiEnabledPlatformsFiltersUnknown(): void
    {
        $this->repo()->setGitCiEnabledPlatforms(['gitlab', 'unknown', 'gitea']);
        $this->assertSame(['gitlab', 'gitea'], $this->repo()->getGitCiEnabledPlatforms());
        $this->assertSame('gitlab,gitea', $this->storedGitCiEnabled());
    }

    public function testEmptyValueMeansNoPullCi(): void
    {
        $this->seedBuildMode('');
        $this->assertSame([], $this->repo()->getBuildModes());
        $this->assertSame('', $this->repo()->getBuildMode());
    }

    public function testUnknownValuesDropped(): void
    {
        $this->seedBuildMode('jenkins,unknown_provider,gitea_ci');
        $this->assertSame(['jenkins', 'gitea_ci'], $this->repo()->getBuildModes());
    }
}
