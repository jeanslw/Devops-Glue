<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Support\EnvFileLoader;

/**
 * OIDC 登录配置的 env → DB 导入器（唯一实现，供两个入口复用）：
 *
 *   - {@see \App\Service\Database::bootstrap()}：全新库（ci_oauth_clients 空表）首次引导时自动播种，
 *     避免「联合部署完就登不上」（忘了跑迁移 → /oauth/authorize 400 无效的 OAuth 请求）；
 *   - cli/migrate-oidc.php：老版本升级时显式迁移，输出迁移/跳过明细。
 *
 * 边界（只补不改）：
 *   - DB 已有值（issuer / id_token_ttl）或已有行（客户端）一律跳过，重复执行安全；
 *   - 因此不会覆盖后台「系统设置 → 平台管理」里的人工配置。
 *
 * 不在本类范围：签名私钥（OIDC_RSA_PRIVATE_KEY / OIDC_KEY_FILE）始终留在 env / 文件，不迁库。
 */
final class OidcConfigImporter
{
    /**
     * client_id → 各列的候选 env 键（按顺序取第一个非空值）。
     *
     * @var array<string, array{secret:list<string>, redirect_uri:list<string>, system?:list<string>}>
     */
    private const CLIENT_ENV = [
        'jenkins' => [
            'secret'       => ['JENKINS_OIDC_SECRET'],
            'redirect_uri' => ['JENKINS_OIDC_REDIRECT_URI'],
        ],
        AppConfig::SERVICE_CD => [
            'secret'       => ['CD_OIDC_SECRET'],
            'redirect_uri' => ['CD_OIDC_REDIRECT_URI'],
            'system'       => ['CD_OIDC_SYSTEM'],
        ],
    ];

    private AppSettingRepository $appSettings;
    private OAuthClientRepository $clients;

    public function __construct(private \PDO $pdo)
    {
        $this->appSettings = new AppSettingRepository($pdo);
        $this->clients     = new OAuthClientRepository($pdo);
    }

    /**
     * 客户端白名单是否还是空表（判断「全新库、从未配置过」）。
     *
     * 表不存在时视为空（引导期间建表失败等异常场景交给调用方另行处理）。
     */
    public static function hasNoClientRow(\PDO $pdo): bool
    {
        if (!Database::tableExists($pdo, AppConfig::TABLE_OAUTH_CLIENTS)) {
            return true;
        }
        $count = $pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_OAUTH_CLIENTS)->fetchColumn();
        return (int) $count === 0;
    }

    /**
     * 把 env 里非空的 OIDC 配置补进 DB。
     *
     * @return array{migrated:list<string>, skipped:list<string>}
     */
    public function importFromEnv(): array
    {
        $migrated = [];
        $skipped  = [];

        if ($this->appSettings->getOidcIssuer() !== null) {
            $skipped[] = 'issuer（DB 已有值，不覆盖）';
        } else {
            $issuer = trim(EnvFileLoader::envVal('OIDC_ISSUER'));
            if ($issuer !== '') {
                $this->appSettings->setOidcIssuer($issuer);
                $migrated[] = 'issuer';
            }
        }

        if ($this->appSettings->getOidcIdTokenTtl() !== null) {
            $skipped[] = 'id_token_ttl（DB 已有值，不覆盖）';
        } else {
            $ttl = (int) trim(EnvFileLoader::envVal('OIDC_ID_TOKEN_TTL'));
            if ($ttl > 0) {
                $this->appSettings->setOidcIdTokenTtl($ttl);
                $migrated[] = 'id_token_ttl';
            }
        }

        foreach (self::CLIENT_ENV as $clientId => $cols) {
            if ($this->clients->find($clientId) !== null) {
                $skipped[] = "client:{$clientId}（DB 已有行，不覆盖）";
                continue;
            }

            $secret      = self::firstEnvValue($cols['secret']);
            $redirectUri = self::firstEnvValue($cols['redirect_uri']);
            $system      = isset($cols['system']) ? self::firstEnvValue($cols['system']) : '';
            $hasEnvValue = $secret !== '' || $redirectUri !== '' || $system !== '';

            // cd 客户端天然归属 CD 系统：未显式提供 CD_OIDC_SYSTEM 时默认 'cd'。
            // 否则 OAuthClientRepository::save() 会把空 system 归一为 'ci'，
            // CD 账号（systems 含 cd）登录时会被 auth.no_ci_access 拒绝。
            if ($clientId === AppConfig::SERVICE_CD && $system === '') {
                $system = AppConfig::SYSTEM_CD;
            }

            if ($secret === '' || $redirectUri === '') {
                if ($hasEnvValue) {
                    $skipped[] = "client:{$clientId}（secret/redirect_uri 不齐，未写入）";
                }
                continue;
            }

            $this->clients->save($clientId, [
                'secret'       => $secret,
                'redirect_uri' => $redirectUri,
                'system'       => $system,
                'enabled'      => true,
                'description'  => '',
            ]);
            $migrated[] = 'client:' . $clientId;
        }

        return ['migrated' => $migrated, 'skipped' => $skipped];
    }

    /**
     * 按给定 env 键顺序取第一个非空值。
     *
     * @param list<string> $keys
     */
    private static function firstEnvValue(array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim(EnvFileLoader::envVal($key));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
