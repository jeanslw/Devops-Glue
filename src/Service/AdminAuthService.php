<?php
namespace App\Service;

use App\Config\AppConfig;

class AdminAuthService
{
    public function __construct(
        private \PDO $pdo,
        private AdminUserRepository $repository,
        private AppConfig $config,
        private ?LdapService $ldap = null,
        private ?UserIdentityRepository $identities = null
    ) {}


    /**
     * Authenticate a user via DB or .env fallback.
     * Returns success or error payload for controller handling.
     */
    public function authenticate(string $username, string $password, string $systemType): array
    {
        if ($username === '' || $password === '') {
            return ['success' => false, 'errorKey' => 'auth.wrong_credentials'];
        }

        $rootUser = $this->config->getRootAdminUser();
        $dbAccessible = true;
        $dbUser = null;

        try {
            $dbUser = $this->repository->findByUsername($username);
        } catch (\Throwable $e) {
            $dbAccessible = false;
        }

        if ($dbUser !== null) {
            // 停用账号（status=0）禁止登录：无论密码对错都提示「已停用」，避免误报「账号或密码错误」。
            // 必须先于密码校验判断，否则停用账号输入错误密码会落到「密码错误」分支，误导用户以为密码忘了。
            if ((int)($dbUser['status'] ?? 1) === 0) {
                return ['success' => false, 'errorKey' => 'auth.user_disabled'];
            }
            if (password_verify($password, $dbUser['password_hash'])) {
                if (!$this->isAllowedSystem($dbUser['systems'], $systemType)) {
                    return ['success' => false, 'errorKey' => $this->getSystemErrorKey($systemType)];
                }
                return [
                    'success' => true,
                    'user'    => $username,
                    'role'    => $dbUser['role'],
                    'email'   => (string)($dbUser['email'] ?? ''),
                    'isRoot'  => $username === $rootUser,
                ];
            }
        }

        // 本地 DB 校验未通过 → 尝试 LDAP（先账号密码打 LDAP，再在 user_identities 里找已绑定账号）
        // 策略：LDAP 只负责"验明正身"，授权（role/systems/email）仍以 admin_users 对应行作为唯一权威，
        //       未绑定（provider_uid 未出现在 user_identities）一律拒绝，不自动建号；
        //       若 LDAP 链路有故障（连接失败等），落到 env 兜底（与现有灾难恢复路径一致）。
        if ($this->ldap !== null && $this->ldap->isEnabled()) {
            $ldapRes = $this->ldap->authenticate($username, $password);
            if (!empty($ldapRes['ok'])) {
                $dn = (string)($ldapRes['dn'] ?? '');
                $identity = $dn !== '' && $this->identities !== null
                    ? $this->identities->find('ldap', $dn)
                    : null;
                if ($identity !== null) {
                    $boundUser = $this->repository->findByUsername($identity['username']);
                    if ($boundUser !== null) {
                        // 停用账号（status=0）禁止通过 LDAP 登录
                        if ((int)($boundUser['status'] ?? 1) === 0) {
                            return ['success' => false, 'errorKey' => 'auth.user_disabled'];
                        }
                        if (!$this->isAllowedSystem($boundUser['systems'], $systemType)) {
                            return ['success' => false, 'errorKey' => $this->getSystemErrorKey($systemType)];
                        }
                        // 顺手回填最新 LDAP 属性到 user_identities（email / raw_profile），失败不阻断登录
                        $attrs = is_array($ldapRes['attrs'] ?? null) ? $ldapRes['attrs'] : [];
                        $email = (string)($attrs['mail'] ?? $attrs['email'] ?? $identity['email'] ?? '');
                        try {
                            $this->identities->refreshProfile('ldap', $dn, $email, $attrs === [] ? null : $attrs);
                        } catch (\Throwable $e) { /* 缓存写入失败不影响登录 */ }

                        return [
                            'success' => true,
                            'user'    => $boundUser['username'],
                            'role'    => $boundUser['role'],
                            'email'   => (string)($boundUser['email'] ?? ''),
                            'avatar_url' => (string)($boundUser['avatar_url'] ?? ''),
                            'isRoot'  => strtolower($boundUser['username']) === $rootUser,
                            'via'     => 'ldap',
                        ];
                    }
                }
                // LDAP 通过但未绑定本地账号 → 明确拒绝，不放行、不自动建号
                return ['success' => false, 'errorKey' => 'auth.ldap_not_bound'];
            }
            // LDAP 明确失败（密码错误 / 用户不存在）→ 直接拒绝；其余错误放行到 env 兜底
            if (in_array($ldapRes['error'] ?? '', ['ldap_bind_failed', 'ldap_user_not_found'], true)) {
                return ['success' => false, 'errorKey' => 'auth.wrong_credentials'];
            }
        }

        // env 兜底仅限两类场景（DB 是唯一权威，禁止常开明文旁路）：

        //  1. DB 完全不可访问（灾难恢复，此时无法验证任何账号）
        //  2. DB 可访问但尚无任何账号（首次部署，admin_users 为空）
        // 其他情况一律不认 .env 密码；忘记密码走离线补丁
        $allowEnvFallback = $dbAccessible ? $this->hasNoAdminUsers() : true;

        if ($allowEnvFallback && $this->authenticateEnvRoot($username, $password)) {
            return [
                'success' => true,
                'user'    => $username,
                'role'    => AppConfig::ROLE_SUPER_ADMIN,
                'isRoot'  => true,
            ];
        }

        return ['success' => false, 'errorKey' => 'auth.wrong_credentials'];
    }

    public function verifyCurrentPassword(string $username, string $password): bool
    {
        try {
            $dbUser = $this->repository->findByUsername($username);
            if ($dbUser && password_verify($password, $dbUser['password_hash'])) {
                return true;
            }
            // DB 可访问：仅首次部署（无任何账号）才接受 .env 密码，与 authenticate 保持一致
            if ($this->hasNoAdminUsers()) {
                $cred = $this->config->getAdminCredentials();
                return strtolower($username) === strtolower($cred['user']) && $password === $cred['password'] && $password !== '';
            }
        } catch (\Throwable $e) {
            // DB 不可访问：接受 .env 密码作为灾难恢复
            $cred = $this->config->getAdminCredentials();
            return strtolower($username) === strtolower($cred['user']) && $password === $cred['password'] && $password !== '';
        }
        return false;
    }

    private function authenticateEnvRoot(string $username, string $password): bool
    {
        $cred = $this->config->getAdminCredentials();
        return strtolower($username) === strtolower($cred['user'])
            && $password === $cred['password']
            && $password !== '';
    }

    private function getSystemErrorKey(string $systemType): string
    {
        return $systemType === AppConfig::SYSTEM_CI
            ? 'auth.no_ci_access'
            : ($systemType === AppConfig::SYSTEM_CD ? 'auth.no_cd_access' : 'auth.no_system_access');
    }

    private function isAllowedSystem(string $systems, string $systemType): bool
    {
        if ($systemType === AppConfig::SYSTEM_CI) {
            return $this->systemsContain($systems, AppConfig::SYSTEM_CI);
        }
        if ($systemType === AppConfig::SYSTEM_CD) {
            return $this->systemsContain($systems, AppConfig::SYSTEM_CD);
        }
        return $this->systemsContain($systems, AppConfig::SYSTEM_CI)
            || $this->systemsContain($systems, AppConfig::SYSTEM_CD);
    }

    private function systemsContain(string $systems, string $needle): bool
    {
        return in_array($needle, $this->parseSystems($systems), true);
    }

    private function parseSystems(string $systems): array
    {
        return array_filter(array_map('trim', explode(',', strtolower($systems))), fn($value) => $value !== '');
    }

    /** 首次部署判断：admin_users 表为空（此时允许 .env 根密码兜底） */
    private function hasNoAdminUsers(): bool
    {
        try {
            $cnt = (int)$this->pdo->query(
                "SELECT count(*) c FROM " . AppConfig::TABLE_ADMIN_USERS
            )->fetch()['c'];
            return $cnt === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
