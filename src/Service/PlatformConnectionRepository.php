<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_platform_connections 仓储：Git 平台（gitlab/github/gitee/gitea）、Harbor、Jenkins 连接配置。
 *
 * 边界约定（彻底移除 env）：
 *   - DB 是唯一权威，没有 env 兜底。DB 无行 = 平台未配置（回填代码级默认值，见 DEFAULTS）。
 *   - 不自动迁移 env：旧版 env 里的连接值由离线工具 cli/migrate-platform-connections.php
 *     手动搬一次，应用本身永不读这些连接 env 键。
 *   - token/username/password 加密落库（Encryptor，AES-256-GCM），list/find 读出时透明解密；
 *     仍可逆（供 HTTP 头回传，不能哈希），但「只读数据库」不再等于「泄露密钥」。
 */
class PlatformConnectionRepository
{
    private Encryptor $encryptor;

    public function __construct(private \PDO $pdo, ?Encryptor $encryptor = null)
    {
        $this->encryptor = $encryptor ?? new Encryptor();
    }

    /**
     * DB 列 → 配置键 的归一化方向（resolveConnections 用）。
     * 每个平台只使用相关列，其余列留空（如 git 平台不使用 username/password）。
     */
    /**
     * 镜像仓库（Container Registry）逻辑上只有一个连接，但其 platform 取值随 registry_type 变化：
     *   - registry_type=harbor  → platform='harbor'（Harbor 服务的 REST API）
     *   - registry_type=registry → platform='registry'（标准 OCI Distribution，如 registry:2）
     */
    public const REGISTRY_PLATFORMS = ['harbor', 'registry'];

    private const FIELD_MAP = [
        'gitlab'  => ['url' => 'base_url', 'token' => 'token'],
        'github'  => ['url' => 'base_url', 'token' => 'token'],
        'gitee'   => ['url' => 'base_url', 'token' => 'token'],
        'gitea'   => ['url' => 'base_url', 'token' => 'token'],
        'harbor'  => ['url' => 'url', 'username' => 'username', 'password' => 'password'],
        'registry' => ['url' => 'url', 'username' => 'username', 'password' => 'password'],
        'jenkins' => ['url' => 'url', 'username' => 'user', 'token' => 'token'],
    ];

    /**
     * 代码级默认值（平台常量，非用户 env）：DB 无行时回填。
     * github/gitee 的 SaaS 默认 base_url、jenkins user / harbor username 的 admin 默认在此保留。
     */
    private const DEFAULTS = [
        'gitlab'  => ['base_url' => '', 'token' => ''],
        'github'  => ['base_url' => 'https://api.github.com', 'token' => ''],
        'gitee'   => ['base_url' => 'https://gitee.com/api/v5', 'token' => ''],
        'gitea'   => ['base_url' => '', 'token' => ''],
        'harbor'  => ['url' => '', 'username' => 'admin', 'password' => ''],
        'registry' => ['url' => '', 'username' => '', 'password' => ''],
        'jenkins' => ['url' => '', 'user' => 'admin', 'token' => ''],
    ];

    /**
     * 全表按 platform 排序返回原始行。
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        if (!Database::tableExists($this->pdo, AppConfig::TABLE_PLATFORM_CONNECTIONS)) {
            return [];
        }
        $stmt = $this->pdo->query(
            'SELECT platform, url, token, username, password, description, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' ORDER BY platform'
        );
        $rows = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        foreach ($rows as &$row) {
            $row['token']    = $this->encryptor->decrypt((string)($row['token'] ?? ''));
            $row['username'] = $this->encryptor->decrypt((string)($row['username'] ?? ''));
            $row['password'] = $this->encryptor->decrypt((string)($row['password'] ?? ''));
        }
        return $rows;
    }

    /**
     * 容错读取：逐行解密并捕获失败，不让某个平台的坏凭据拖垮整个请求。
     *
     * 默认 $keepCipher=true（后台展示）：失败字段保留原密文，「已配置」仍成立，且 save()
     * 遇到 enc:v1: 前缀会原样写回、不二次加密，用户重填前不丢数据。
     * $keepCipher=false（运行时配置）：失败字段置空，绝不把密文当 token 发给 CI；
     * 这样 DI 构造 Settings 不会抛异常，页面能正常渲染并逐行提示。
     *
     * @return array{rows: list<array<string,mixed>>, errors: array<string,string>}
     */
    public function listForDisplay(bool $keepCipher = true): array
    {
        if (!Database::tableExists($this->pdo, AppConfig::TABLE_PLATFORM_CONNECTIONS)) {
            return ['rows' => [], 'errors' => []];
        }
        $rows = array_values($this->pdo->query(
            'SELECT platform, url, token, username, password, description, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' ORDER BY platform'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        $errors = [];
        foreach ($rows as &$row) {
            $platform = trim((string)($row['platform'] ?? ''));
            foreach (['token', 'username', 'password'] as $col) {
                $raw = (string)($row[$col] ?? '');
                if (!str_starts_with($raw, Encryptor::PREFIX)) {
                    continue;
                }
                try {
                    $row[$col] = $this->encryptor->decrypt($raw);
                } catch (\Throwable $e) {
                    $errors[$platform] = $e->getMessage();
                    if (!$keepCipher) {
                        $row[$col] = '';
                    }
                }
            }
        }
        unset($row);
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * 取单个平台原始行（不存在返回 null）。
     *
     * $tolerant=true 时解密失败保留原密文、不抛异常，供后台保存路径使用：
     *
     * @return array<string,mixed>|null
     */
    public function find(string $platform, bool $tolerant = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT platform, url, token, username, password, description, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' WHERE platform = ?'
        );
        $stmt->execute([$platform]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['token', 'username', 'password'] as $col) {
            $raw = (string)($row[$col] ?? '');
            if ($tolerant && str_starts_with($raw, Encryptor::PREFIX)) {
                try {
                    $row[$col] = $this->encryptor->decrypt($raw);
                } catch (\Throwable) {
                }
            } else {
                $row[$col] = $this->encryptor->decrypt($raw);
            }
        }
        return $row;
    }

    /**
     * 保存（插入或更新）。行不存在时 INSERT（写 created_at/updated_at），
     * 已存在时 UPDATE（只刷新 updated_at，保留 created_at）。
     * 刻意不用 sqlUpsert/REPLACE INTO：后者会删除重插并重置 created_at。
     *
     * @param array{url:string, token:string, username:string, password:string, description:string} $data
     */
    public function save(string $platform, array $data): void
    {
        $url         = trim((string)$data['url']);
        $token       = $this->encryptor->encrypt((string)$data['token']);
        $username    = $this->encryptor->encrypt((string)$data['username']);
        $password    = $this->encryptor->encrypt((string)$data['password']);
        $description = trim((string)$data['description']);

        if ($this->find($platform) === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . AppConfig::TABLE_PLATFORM_CONNECTIONS
                . ' (platform, url, token, username, password, description, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ' . Database::sqlNow() . ', ' . Database::sqlNow() . ')'
            );
            $stmt->execute([$platform, $url, $token, $username, $password, $description]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_PLATFORM_CONNECTIONS
            . ' SET url = ?, token = ?, username = ?, password = ?, description = ?,'
            . ' updated_at = ' . Database::sqlNow()
            . ' WHERE platform = ?'
        );
        $stmt->execute([$url, $token, $username, $password, $description, $platform]);
    }

    /**
     * 删除平台行（回到「未配置」，由 DEFAULTS 回填代码默认）。
     */
    public function delete(string $platform): void
    {
        $this->pdo->prepare('DELETE FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' WHERE platform = ?')
            ->execute([$platform]);
    }

    /**
     * 解析 6 个平台的连接配置（DB 唯一权威、无 env 兜底）。
     * DB 行存在但某列空 → 该键空串；整行不存在 → DEFAULTS[platform] 代码默认。
     *
     * $source 仅供后台展示路径传入 listForDisplay() 的容错行；业务调用不传，仍走严格 list()
     *
     * @param list<array<string,mixed>>|null $source
     * @return array<string,array<string,string>>
     */
    public function resolveConnections(?array $source = null): array
    {
        $rows = [];
        foreach ($source ?? $this->list() as $row) {
            $platform = trim((string)($row['platform'] ?? ''));
            if ($platform !== '') {
                $rows[$platform] = $row;
            }
        }

        $result = [];
        foreach (self::FIELD_MAP as $platform => $cols) {
            $row = $rows[$platform] ?? null;
            if ($row === null) {
                $result[$platform] = self::DEFAULTS[$platform];
                continue;
            }
            $cfg = [];
            foreach ($cols as $dbCol => $cfgKey) {
                $value = (string)($row[$dbCol] ?? '');


                if (($cfgKey === 'username' || $cfgKey === 'user') && $value === '') {
                    $value = 'admin';
                }
                $cfg[$cfgKey] = $value;
            }
            $result[$platform] = $cfg;
        }
        return $result;
    }
}
