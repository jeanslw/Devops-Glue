<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 由原 Database 拆分出的「BootstrapOrchestrator」职责（门面转调，行为不变）。
 */
class BootstrapOrchestrator
{
    private \PDO $pdo;
    private SchemaMigrator $migrator;
    private RbacSeeder $seeder;
    private DataRepairer $repairer;
    private DistributedLock $lock;
    private bool $schemaListDriftLogged = false;

    public function __construct(\PDO $pdo, SchemaMigrator $migrator, RbacSeeder $seeder, DataRepairer $repairer, DistributedLock $lock)
    {
        $this->pdo = $pdo;
        $this->migrator = $migrator;
        $this->seeder = $seeder;
        $this->repairer = $repairer;
        $this->lock = $lock;
        $this->schemaListDriftLogged = false;
    }

    public function bootstrap(): void
    {
        $autoMigrate = Database::isAutoMigrateEnabled();
        $versionKey  = $autoMigrate ? Database::SCHEMA_VERSION_KEY : Database::SEED_VERSION_KEY;
        $recorded    = $this->migrator->recordedVersion($versionKey);
        $seedMode    = Database::SEED_MODE_PRUNE;

        if (! $this->migrator->isVersionCurrent($recorded)) {
            $seedMode = $this->migrator->isVersionAhead($recorded) ? Database::SEED_MODE_MERGE : Database::SEED_MODE_PRUNE;
            if ($autoMigrate) {
                $this->lock->ensureCacheTable();
            }
            $this->lock->runExclusively($versionKey, function () use ($autoMigrate, $seedMode, $recorded): void {
                $key = $autoMigrate ? Database::SCHEMA_VERSION_KEY : Database::SEED_VERSION_KEY;
                if ($this->migrator->isVersionCurrent($this->migrator->recordedVersion($key))) {
                    return;
                }
                if ($autoMigrate) {
                    $this->migrator->ensureTables($seedMode);
                } else {
                    $this->seeder->seedRbac($seedMode);
                }
                $this->repairer->healCdServiceTokenScopes($this->pdo);
                $this->repairer->migrateScopeHarborReadToRegistryRead($this->pdo);
                if ($autoMigrate) {
                    $this->migrator->reconcileColumns($this->pdo, false);
                }
                $this->seeder->seedAdmin();
                $this->seeder->verifySeed();
                $this->logSchemaListDriftIfAny($this->pdo);
                if ($autoMigrate) {
                    $this->migrator->markSchemaCurrent();
                    $this->migrator->noteVersionTransition(Database::SCHEMA_VERSION_KEY, $recorded, $seedMode, 'schema');
                } else {
                    $this->migrator->markSeedCurrent();
                    $this->migrator->noteVersionTransition(Database::SEED_VERSION_KEY, $recorded, $seedMode, 'seed');
                }
            });
        }

        $this->seeder->seedOidcConfigFromEnvIfEmpty();
        $this->assertDataEncKeyPresent($this->pdo);
        $this->warnDeprecatedEnvKeys();
        $this->warnOidcSigningKeyIssues();
    }

    private function warnDeprecatedEnvKeys(): void
    {
        $deprecated = [

            'GITLAB_BASE_URL', 'GIT_BASE_URL', 'GITLAB_TOKEN', 'GIT_TOKEN',
            'GITHUB_BASE_URL', 'GITHUB_TOKEN',
            'GITEE_BASE_URL', 'GITEE_TOKEN',
            'GITEA_BASE_URL', 'GITEA_TOKEN',
            'HARBOR_BASE_URL', 'HARBOR_USER', 'HARBOR_PASSWORD',
            'JENKINS_BASE_URL', 'JENKINS_USER', 'JENKINS_TOKEN',

            'OIDC_ISSUER', 'OIDC_ID_TOKEN_TTL',
            'JENKINS_OIDC_SECRET', 'JENKINS_OIDC_REDIRECT_URI',
            'CD_OIDC_SECRET', 'CD_OIDC_REDIRECT_URI', 'CD_OIDC_SYSTEM',
        ];
        $leftover = [];
        foreach ($deprecated as $key) {
            if (trim(\App\Support\EnvFileLoader::envVal($key, '')) !== '') {
                $leftover[] = $key;
            }
        }
        if ($leftover === []) {
            return;
        }

        $cdStillReads = array_values(array_intersect($leftover, ['CD_OIDC_SECRET', 'CD_OIDC_REDIRECT_URI']));
        $deletable    = array_values(array_diff($leftover, $cdStillReads));

        Database::warnOnceOnStateChange(
            'deprecated_env_keys',
            sha1(\App\Config\AppConfig::APP_VERSION . '|' . implode(',', $leftover)),
            function () use ($leftover, $cdStillReads, $deletable): void {
                $message = '检测到已废弃的连接/OIDC env 键（v2.10.0 起应用不再读取）：' . implode(', ', $leftover)
                    . '。若从旧版升级，请运行 cli/migrate-platform-connections.php 与 cli/migrate-oidc.php 迁移；'
                    . '在后台「系统设置 → 平台管理」页确认值已迁到位后，可从配置文件删除这些键'
                    . ($deletable === []
                        ? '（本次剩余清单都是 CD 配对项，见下段，均不可删）。'
                        : '（顺序与清单见《用户配置手册》第 10 章）。');
                if ($cdStillReads !== []) {
                    $message .= '其中 ' . implode(', ', $cdStillReads)
                        . ' 联合部署时 CD 容器仍要读取（CD 侧没有别的来源），'
                        . '即使 Glue 后台已有同值，也不要从共享 .env 删除，否则 CD 起不来。';
                }
                $message .= '注意：OIDC_RSA_PRIVATE_KEY / OIDC_KEY_FILE 不在此清单内，应用仍在读取，请勿删除或改写成占位值。'
                    . '本提示按「键集合 / 版本变化」去重（见 ci_app_settings.' . Database::BOOT_WARN_KEY_PREFIX
                    . 'deprecated_env_keys），不会按请求重复刷屏。';

                \App\Helper\Log::warning($message, [
                    'deprecated_env_keys' => $leftover,
                    'cd_still_reads'      => $cdStillReads,
                ]);
            }
        );
    }

    private function warnOidcSigningKeyIssues(): void
    {
        $pem = trim(\App\Support\EnvFileLoader::envVal('OIDC_RSA_PRIVATE_KEY', ''));
        if ($pem !== '' && !str_contains($pem, 'BEGIN')) {
            Database::warnOnceOnStateChange(
                'oidc_private_key_not_pem',
                sha1(\App\Config\AppConfig::APP_VERSION . '|' . $pem),
                function (): void {
                    \App\Helper\Log::error(
                        'OIDC_RSA_PRIVATE_KEY 不是 PEM 私钥（缺少 -----BEGIN ... PRIVATE KEY----- 头）：'
                        . 'id_token 签发与 JWKS 都会失败（/oauth/token 500）。'
                        . '请把它留空（自动生成并持久化到 OIDC_KEY_FILE），或填入完整 RSA PEM。'
                        . '本提示按值去重（见 ci_app_settings.' . Database::BOOT_WARN_KEY_PREFIX
                        . 'oidc_private_key_not_pem），不会按请求重复刷屏。'
                    );
                }
            );
        }

        $file = trim(\App\Support\EnvFileLoader::envVal('OIDC_KEY_FILE', ''));
        if ($file !== '' && is_file($file) && !is_readable($file)) {
            Database::warnOnceOnStateChange(
                'oidc_key_file_unreadable',
                sha1($file),
                function () use ($file): void {
                    \App\Helper\Log::error(
                        "OIDC 私钥文件当前不可读：{$file}。PHP 运行用户读不到时无法复用该密钥，"
                        . '下游按 kid 取 JWKS 会验签失败（症状酷似密码错）。'
                        . "修正：chown www-data:www-data {$file}（并确保其所在目录可进入）。"
                    );
                }
            );
        }
    }

    private function assertDataEncKeyPresent(\PDO $pdo): void
    {
        if (\App\Support\EnvFileLoader::envVal(\App\Service\Encryptor::ENV_KEY, '') !== '') {
            return;
        }
        $checks = [
            \App\Config\AppConfig::TABLE_PLATFORM_CONNECTIONS => ['token', 'username', 'password'],
            \App\Config\AppConfig::TABLE_OAUTH_CLIENTS         => ['secret'],
        ];
        foreach ($checks as $table => $cols) {
            $like = implode(' OR ', array_map(fn($c) => "{$c} LIKE 'enc:v1:%'", $cols));
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$like}")->fetchColumn();
            } catch (\Throwable $e) {
                continue;
            }
            if ((int) $count > 0) {
                throw new \RuntimeException(
                    '检测到已加密字段但未配置 DATA_ENC_KEY，无法解密平台凭据（fail-closed）。'
                    . '请在 config/app.env（或根 .env / k8s Secret）配置 32 字节 base64 密钥后重启'
                    . '（生成：php -r "echo base64_encode(random_bytes(32));"）'
                );
            }
        }
    }

    private function logSchemaListDriftIfAny(\PDO $pdo): void
    {
        if ($this->schemaListDriftLogged) {
            return;
        }
        $drift = Database::schemaListDrift();
        if ($drift['ok']) {
            return;
        }
        $this->schemaListDriftLogged = true;
        Database::warnOnceOnStateChange(
            'schema_list_drift',
            sha1(
                \App\Config\AppConfig::APP_VERSION . '|' . implode(',', $drift['missing'])
                . '|' . implode(',', $drift['probe_unknown'])
            ),
            function () use ($drift): void {
                \App\Helper\Log::error('schema 探测清单与 AppConfig 表常量不一致（后台「数据表清单」可能少表）', [
                    'missing_from_probe' => $drift['missing'],
                    'probe_unknown'      => $drift['probe_unknown'],
                    'hint'               => '请在 Database::schemaTables() 补上 AppConfig 中缺失的表（新增应用表时必须同步）；'
                        . 'CI 由 DatabaseBootstrapTest::testSchemaProbeListCoversEveryAppOwnedTable 守着该不变量',
                ]);
            },
            $pdo
        );
    }
}
