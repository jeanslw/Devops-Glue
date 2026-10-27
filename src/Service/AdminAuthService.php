<?php

namespace App\Service;

use App\Config\AppConfig;

class AdminAuthService
{
    public function __construct(
        private \PDO $pdo,
        private AdminUserRepository $repository,
        private Settings $config,
        private ?LdapService $ldap = null,
        private ?UserIdentityRepository $identities = null
    ) {
    }


    /**
     * Authenticate a user via DB or .env fallback.
     * Returns success or error payload for controller handling.
     *
     * @return array<string,mixed>
     */
    public function authenticate(string $username, string $password, string $systemType): array
    {
        if ($username === '' || $password === '') {
            return ['success' => false, 'errorKey' => 'auth.wrong_credentials'];
        }

        $rootUser = $this->config->getRootAdminUser();


        $dbUser = $this->repository->findByUsername($username);

        if ($dbUser !== null) {
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
                    'isRoot'  => strtolower($username) === $rootUser,
                    'must_change_password' => ((int)($dbUser['must_change_password'] ?? 0)) === 1,
                ];
            }
        }





        if ($this->ldap !== null && $this->ldap->isEnabled()) {
            $ldapRes = $this->ldap->authenticate($username, $password);
            if (!empty($ldapRes['ok'])) {
                $dn = (string)($ldapRes['dn'] ?? '');
                $identities = $this->identities;
                $identity = $dn !== '' && $identities !== null
                    ? $identities->find('ldap', $dn)
                    : null;
                if ($identity !== null && $identities !== null) {
                    $boundUser = $this->repository->findByUsername($identity['username']);
                    if ($boundUser !== null) {
                        if ((int)($boundUser['status'] ?? 1) === 0) {
                            return ['success' => false, 'errorKey' => 'auth.user_disabled'];
                        }
                        if (!$this->isAllowedSystem($boundUser['systems'], $systemType)) {
                            return ['success' => false, 'errorKey' => $this->getSystemErrorKey($systemType)];
                        }

                        $attrs = is_array($ldapRes['attrs'] ?? null) ? $ldapRes['attrs'] : [];
                        $email = (string)($attrs['mail'] ?? $attrs['email'] ?? $identity['email'] ?? '');
                        try {
                            $identities->refreshProfile('ldap', $dn, $email, $attrs === [] ? null : $attrs);
                        } catch (\Throwable $e) {
                        }

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

                return ['success' => false, 'errorKey' => 'auth.ldap_not_bound'];
            }

            if (in_array($ldapRes['error'] ?? '', ['ldap_bind_failed', 'ldap_user_not_found'], true)) {
                return ['success' => false, 'errorKey' => 'auth.wrong_credentials'];
            }
        }




        if ($this->hasNoAdminUsers() && $this->authenticateEnvRoot($username, $password)) {
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
        $dbUser = $this->repository->findByUsername($username);
        if ($dbUser && password_verify($password, $dbUser['password_hash'])) {
            return true;
        }


        if ($this->hasNoAdminUsers()) {
            $cred = $this->config->getAdminCredentials();
            return \hash_equals(strtolower($cred['user']), strtolower($username)) && \hash_equals($cred['password'], $password) && $password !== '';
        }
        return false;
    }

    private function authenticateEnvRoot(string $username, string $password): bool
    {
        $cred = $this->config->getAdminCredentials();

        return \hash_equals(strtolower($cred['user']), strtolower($username))
            && \hash_equals($cred['password'], $password)
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

    /**
     * @return array<int,string>
     */
    private function parseSystems(string $systems): array
    {
        return array_filter(array_map('trim', explode(',', strtolower($systems))), fn($value) => $value !== '');
    }

    /** 登录失败限流：是否已被锁定（IP + 用户名 维度，连续失败达阈值即锁） */
    public function isLoginLocked(string $ip, string $username): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([self::loginFailKey($ip, $username), time()]);
            $val = $stmt->fetchColumn();
            return $val !== false && (int)$val >= AppConfig::LOGIN_FAIL_MAX_ATTEMPTS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 登录失败计数 +1；expires_at 即锁定窗口到期时间，达阈值后由 isLoginLocked 拦截 */
    public function recordLoginFailure(string $ip, string $username): void
    {
        try {
            $key = self::loginFailKey($ip, $username);
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([$key, time()]);
            $val = $stmt->fetchColumn();
            $count = ($val === false ? 0 : (int)$val) + 1;
            $sql = Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $this->pdo->prepare($sql)->execute([$key, (string)$count, time() + AppConfig::LOGIN_FAIL_LOCK_SECONDS]);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /** 登录成功清除失败计数 */
    public function clearLoginFailure(string $ip, string $username): void
    {
        try {
            $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?")
                ->execute([self::loginFailKey($ip, $username)]);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /** 限流缓存键：IP + 用户名 哈希，避免用户名含特殊字符污染 cache_key */
    private static function loginFailKey(string $ip, string $username): string
    {
        return AppConfig::CACHE_KEY_LOGIN_FAIL_PREFIX . md5($ip . ':' . strtolower($username));
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

    /** 清除所有登录失败限流计数（super_admin），返回清除行数。 */
    public function clearAllLoginLocks(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key LIKE ?");
        $stmt->execute([AppConfig::CACHE_KEY_LOGIN_FAIL_PREFIX . '%']);
        return $stmt->rowCount();
    }
}
