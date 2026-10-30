<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Helper\Log;
use App\Service\Database;
use PHPUnit\Framework\TestCase;

/**
 * 启动期告警的跨请求去重（日志刷屏防御）。
 *
 * 回归背景（实测）：Database::bootstrap() 每个请求都会跑一遍，而 php-fpm 每个请求是独立
 * 生命周期，进程内的 static 守卫挡不住跨请求——「已废弃的连接/OIDC env 键」这类启动自检
 * 未去重时会把容器日志刷屏（WSL / Docker 下最明显），真正的问题反而被淹没。
 *
 * 锁死三条不变量：
 *   1. 同一「状态指纹」跨 bootstrap（= 跨请求、跨重启、多副本）只告警一次，指纹记在 ci_app_settings；
 *   2. 状态一变（增删键、改值）立刻重新提醒；
 *   3. 联合部署时 CD 仍要读的键（CD_OIDC_SECRET / CD_OIDC_REDIRECT_URI）被点名「不可删」，
 *      不会被笼统建议「迁完就删」（照做会让 CD 起不来）。
 *
 * 同一机制（Database::warnOnceOnStateChange）也覆盖其它每请求都会跑的启动期提示：
 * 非 PEM 私钥、「初始种子密码未改」提示等，各自用「值 / 账号」指纹去重。
 */
class BootstrapWarnDedupeTest extends TestCase
{
    /** 匹配用片段刻意不含 '/'：日志是 json_encode 产物，'/' 会被转义成 '\/'，带斜杠的 needle 匹配不到。 */
    private const WARN_DEPRECATED      = '检测到已废弃的连接';
    private const WARN_NOT_PEM         = '不是 PEM 私钥';
    private const WARN_SEED_PASSWORD   = '仍使用首次建库的初始种子密码';

    /** warnDeprecatedEnvKeys() 会检查的全部键；用例前后都清干净，避免跨用例串味。 */
    private const DEPRECATED_KEYS = [
        'GITLAB_BASE_URL', 'GIT_BASE_URL', 'GITLAB_TOKEN', 'GIT_TOKEN',
        'GITHUB_BASE_URL', 'GITHUB_TOKEN', 'GITEE_BASE_URL', 'GITEE_TOKEN',
        'GITEA_BASE_URL', 'GITEA_TOKEN', 'HARBOR_BASE_URL', 'HARBOR_USER', 'HARBOR_PASSWORD',
        'JENKINS_BASE_URL', 'JENKINS_USER', 'JENKINS_TOKEN',
        'OIDC_ISSUER', 'OIDC_ID_TOKEN_TTL', 'JENKINS_OIDC_SECRET', 'JENKINS_OIDC_REDIRECT_URI',
        'CD_OIDC_SECRET', 'CD_OIDC_REDIRECT_URI', 'CD_OIDC_SYSTEM',
    ];

    private string $logDir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'glue-bootwarn-' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0755, true);

        $_ENV['ADMIN_USER']     = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        $_ENV['LOG_PATH']       = $this->logDir;
        $_ENV['DATA_ENC_KEY']   = base64_encode(random_bytes(32));

        $this->clearKeyEnv();

        Database::reset();
        // Log 单例按「首个 LOG_PATH」绑定，先重置才能让本用例的日志目录生效。
        Log::reset();

        $this->pdo = $this->memoryPdo();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['LOG_PATH'], $_ENV['DATA_ENC_KEY']);
        $this->clearKeyEnv();
        Database::reset();

        // 清掉本用例的临时日志目录，别在系统 temp 里留垃圾
        foreach ($this->logFiles() as $file) {
            @unlink($file);
        }
        @rmdir($this->logDir);

        parent::tearDown();
    }

    /** 清掉所有相关 env 键（含 OIDC 私钥两项），确保每个用例的起点一致。 */
    private function clearKeyEnv(): void
    {
        foreach (array_merge(self::DEPRECATED_KEYS, ['OIDC_RSA_PRIVATE_KEY', 'OIDC_KEY_FILE']) as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** 同一个内存库上跑一次引导（$bootstrapped 是进程级，重跑必须经 nextRequest()）。 */
    private function bootstrapOn(\PDO $pdo): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        Database::bootstrap($pdo);
    }

    /** 模拟「下一个请求」：php-fpm 每请求都会重建静态状态。 */
    private function nextRequest(): void
    {
        Database::reset();
        $this->bootstrapOn($this->pdo);
    }

    /** 统计日志文件里命中 $needle 的行数（只读本用例自己的日志目录）。 */
    private function logHits(string $needle): int
    {
        $hits = 0;
        foreach ($this->logFiles() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_contains($line, $needle)) {
                    $hits++;
                }
            }
        }
        return $hits;
    }

    private function readLog(): string
    {
        $out = '';
        foreach ($this->logFiles() as $file) {
            $out .= (string) file_get_contents($file);
        }
        return $out;
    }

    /** @return list<string> */
    private function logFiles(): array
    {
        return array_values(glob($this->logDir . DIRECTORY_SEPARATOR . '*.log') ?: []);
    }

    /** 读取启动期告警的状态指纹行（不存在返回 null）。 */
    private function marker(string $name): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT value FROM ' . AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key = ?'
        );
        $stmt->execute([Database::BOOT_WARN_KEY_PREFIX . $name]);
        $row = $stmt->fetchColumn();
        return $row === false ? null : (string) $row;
    }

    public function testDeprecatedEnvWarningIsLoggedOncePerKeySetAcrossRequests(): void
    {
        $_ENV['OIDC_ISSUER']          = 'https://glue.example.com';
        $_ENV['CD_OIDC_SECRET']       = 'cd-secret';
        $_ENV['CD_OIDC_REDIRECT_URI'] = 'http://cd.example.com/api/auth/oidc/callback';

        $this->bootstrapOn($this->pdo);
        $this->assertSame(1, $this->logHits(self::WARN_DEPRECATED), '首次检测到残留键应告警一次');
        $this->assertNotNull($this->marker('deprecated_env_keys'), '状态指纹必须落到 ci_app_settings');

        $this->nextRequest();
        $this->nextRequest();
        $this->assertSame(1, $this->logHits(self::WARN_DEPRECATED), '同状态跨请求不得重复刷屏');
    }

    public function testChangedKeySetWarnsAgain(): void
    {
        $_ENV['OIDC_ISSUER'] = 'https://glue.example.com';
        $this->bootstrapOn($this->pdo);
        $this->nextRequest();
        $this->assertSame(1, $this->logHits(self::WARN_DEPRECATED));

        // 又冒出一个待迁移键 → 状态变了，必须重新提醒一次
        $_ENV['HARBOR_BASE_URL'] = 'https://harbor.example.com';
        $this->nextRequest();
        $this->assertSame(2, $this->logHits(self::WARN_DEPRECATED), '键集合变化必须重新提醒');
        $this->assertNotNull($this->marker('deprecated_env_keys'));
    }

    public function testCdPairingKeysAreCalledOutAsNotDeletable(): void
    {
        $_ENV['OIDC_ISSUER']          = 'https://glue.example.com';
        $_ENV['CD_OIDC_SECRET']       = 'cd-secret';
        $_ENV['CD_OIDC_REDIRECT_URI'] = 'http://cd.example.com/api/auth/oidc/callback';

        $this->bootstrapOn($this->pdo);

        $log = $this->readLog();
        $this->assertStringContainsString('CD_OIDC_SECRET', $log);
        $this->assertStringContainsString('CD 容器仍要读取', $log, '必须点明 CD 侧仍在读，不能笼统建议删除');
        $this->assertStringContainsString('cd_still_reads', $log, 'context 里的 cd_still_reads 供日志检索');
    }

    public function testOidcKeyWarningsAreDedupedToo(): void
    {
        $_ENV['OIDC_RSA_PRIVATE_KEY'] = 'deprecated_env_keys_kdkssffsfdfdf';

        $this->bootstrapOn($this->pdo);
        $this->nextRequest();

        $this->assertSame(1, $this->logHits(self::WARN_NOT_PEM), '非 PEM 私钥提示同样只应告警一次');
        $this->assertNotNull($this->marker('oidc_private_key_not_pem'), '按值指纹去重');
    }

    public function testSeedPasswordWarningIsDedupedToo(): void
    {
        // 首次建库会种下 must_change_password=1 的账号：该提示原本每个请求都会写一条（同步路径每请求都跑）
        $this->bootstrapOn($this->pdo);
        $this->nextRequest();
        $this->nextRequest();

        $this->assertSame(1, $this->logHits(self::WARN_SEED_PASSWORD), '初始种子密码提示同样只应告警一次');
        $this->assertNotNull($this->marker('seed_password_pending'), '按待改密账号指纹去重');
    }
}
