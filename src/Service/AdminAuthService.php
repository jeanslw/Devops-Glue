<?php
namespace App\Service;

use App\Config\AppConfig;

class AdminAuthService
{
    public function __construct(
        private \PDO $pdo,
        private AdminUserRepository $repository,
        private AppConfig $config
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
        $allowEnvFallback = false;
        $dbAccessible = true;
        $dbUser = null;

        try {
            $dbUser = $this->repository->findByUsername($username);
        } catch (\Throwable $e) {
            $dbAccessible = false;
        }

        if ($dbUser !== null && password_verify($password, $dbUser['password_hash'])) {
            if (!$this->isAllowedSystem($dbUser['systems'], $systemType)) {
                return ['success' => false, 'errorKey' => $this->getSystemErrorKey($systemType)];
            }
            $this->clearAdminLoginFailCount($username);
            return [
                'success' => true,
                'user'    => $username,
                'role'    => $dbUser['role'],
                'isRoot'  => $username === $rootUser,
            ];
        }

        if (!$dbAccessible) {
            $allowEnvFallback = true;
        } elseif ($username === $rootUser) {
            $allowEnvFallback = $this->incrementAdminLoginFailCount($username) >= 3;
        }

        if ($this->authenticateEnvRoot($username, $password, $allowEnvFallback, $dbAccessible)) {
            $this->clearAdminLoginFailCount($username);
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
        } catch (\Throwable $e) {
            // continue to .env fallback
        }

        $cred = $this->config->getAdminCredentials();
        return $username === $cred['user'] && $password === $cred['password'] && $password !== '';
    }

    private function authenticateEnvRoot(string $username, string $password, bool $allowEnvFallback, bool $dbAccessible): bool
    {
        $cred = $this->config->getAdminCredentials();
        if ($username !== $cred['user'] || $password !== $cred['password'] || $password === '') {
            return false;
        }
        return $allowEnvFallback || !$dbAccessible;
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

    private function getAdminLoginFailCount(string $username): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?");
            $stmt->execute([AppConfig::CACHE_KEY_ADMIN_LOGIN_FAIL_PREFIX . $username, time()]);
            $row = $stmt->fetch();
            return $row ? (int)$row['value'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function setAdminLoginFailCount(string $username, int $count): void
    {
        try {
            $sql = Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $this->pdo->prepare($sql)->execute([AppConfig::CACHE_KEY_ADMIN_LOGIN_FAIL_PREFIX . $username, (string)$count, time() + AppConfig::TTL_LOGIN_FAIL]);
        } catch (\Throwable $e) {
        }
    }

    private function incrementAdminLoginFailCount(string $username): int
    {
        $count = $this->getAdminLoginFailCount($username) + 1;
        $this->setAdminLoginFailCount($username, $count);
        return $count;
    }

    private function clearAdminLoginFailCount(string $username): void
    {
        try {
            $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?")->execute([AppConfig::CACHE_KEY_ADMIN_LOGIN_FAIL_PREFIX . $username]);
        } catch (\Throwable $e) {
        }
    }
}
