<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\PlatformConnectionRepository;
use App\Service\Settings;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Settings 与 ci_platform_connections 的容器接线测试（内存 SQLite）。
 *
 * 单独测试的原因：连接值从 settings.php 的 env() 迁到 DB 注入，若容器闭包漏接，
 * 业务单测全部照过，只会在生产上表现为「后台改了 DB 配置不生效」或「删掉 env 后功能全断」。
 */
class PlatformConnectionWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CONNECTION_ENV_KEYS = [
        'GITLAB_BASE_URL', 'GIT_BASE_URL', 'GITLAB_TOKEN', 'GIT_TOKEN',
        'GITHUB_BASE_URL', 'GITHUB_TOKEN', 'GITEE_BASE_URL', 'GITEE_TOKEN',
        'GITEA_BASE_URL', 'GITEA_TOKEN', 'HARBOR_BASE_URL', 'HARBOR_USER', 'HARBOR_PASSWORD',
        'JENKINS_BASE_URL', 'JENKINS_USER', 'JENKINS_TOKEN',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);
        $_ENV['DATA_ENC_KEY'] = base64_encode(random_bytes(32));
        foreach (self::CONNECTION_ENV_KEYS as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['DATA_ENC_KEY']);
        foreach (self::CONNECTION_ENV_KEYS as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
        Database::reset();
        parent::tearDown();
    }

    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        // 只建连接表即可：Settings 构造不立即查询 PlatformVersionRepository（惰性）。
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' (
            platform    TEXT PRIMARY KEY,
            url         TEXT,
            token       TEXT,
            username    TEXT,
            password    TEXT,
            description TEXT DEFAULT \'\',
            created_at  TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at  TEXT DEFAULT (datetime(\'now\',\'localtime\'))
        )');
        return $pdo;
    }

    private function buildContainer(\PDO $pdo): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::ROOT . '/config/container.php');
        // 容器默认 \PDO 定义走 Database::getPdo()（会碰真实库文件），测试换内存库
        $builder->addDefinitions([\PDO::class => fn() => $pdo]);
        return $builder->build();
    }

    public function testSettingsReadsConnectionsFromDb(): void
    {
        $pdo = $this->memoryPdo();
        (new PlatformConnectionRepository($pdo))->save('gitlab', [
            'url' => 'https://gitlab.db', 'token' => 'glpat-db', 'username' => '', 'password' => '', 'description' => '',
        ]);

        $settings = $this->buildContainer($pdo)->get(Settings::class);
        $this->assertSame('https://gitlab.db', $settings->getGitlabConfig()['base_url'], 'DB 连接值必须经容器注入 Settings');
        $this->assertSame('glpat-db', $settings->getGitlabConfig()['token']);
    }

    public function testSettingsHasNoEnvFallbackForConnections(): void
    {
        // env 提供值、DB 无行：彻底移除 env 后，连接值必须仍是空/代码默认，不得回退 env。
        $_ENV['GITLAB_BASE_URL']  = 'https://gitlab.from-env';
        $_ENV['GITLAB_TOKEN']     = 'glpat-env';
        $_ENV['HARBOR_BASE_URL']  = 'https://harbor.from-env';
        $_ENV['HARBOR_USER']      = 'env-user';
        $_ENV['HARBOR_PASSWORD']  = 'env-pw';
        $_ENV['JENKINS_BASE_URL'] = 'https://jenkins.from-env';

        $settings = $this->buildContainer($this->memoryPdo())->get(Settings::class);

        $this->assertSame('', $settings->getGitlabConfig()['base_url'], '连接值不再从 env 兜底');
        $this->assertSame('', $settings->getHarborConfig()['url']);
        $this->assertSame('', $settings->getJenkinsConfig()['url']);
        // 代码级默认仍保留（非 env）
        $this->assertSame('https://api.github.com', $settings->getGithubConfig()['base_url']);
        $this->assertSame('admin', $settings->getHarborConfig()['username']);
        $this->assertSame('admin', $settings->getJenkinsConfig()['user']);
    }
}
