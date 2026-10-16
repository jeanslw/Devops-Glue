<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_oauth_clients 仓储：OIDC/OAuth 客户端白名单。
 *
 * 边界约定：
 *   - 本表是 OIDC 客户端配置的唯一权威来源（彻底移除 env 兜底）：DB 无行 = 客户端不存在。
 *   - 不自动种子化：客户端只由后台 UI 或迁移工具 cli/migrate-oidc.php 写入，应用不自动搬 env。
 *   - secret/redirect_uri 留空即 fail-closed（该客户端被 OAuthService 构造函数剔除），
 *     因此 controller 在保存 enabled 行前必须校验二者非空，避免「刚启用就被静默丢弃」。
 *   - secret 加密落库（Encryptor，AES-256-GCM），list/find 读出时透明解密。
 */
class OAuthClientRepository
{
    private Encryptor $encryptor;

    public function __construct(private \PDO $pdo, ?Encryptor $encryptor = null)
    {
        $this->encryptor = $encryptor ?? new Encryptor();
    }

    /**
     * 全表按 client_id 排序返回原始行。
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        if (!Database::tableExists($this->pdo, AppConfig::TABLE_OAUTH_CLIENTS)) {
            return [];
        }
        $stmt = $this->pdo->query(
            'SELECT client_id, secret, redirect_uri, `system`, enabled, description, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_OAUTH_CLIENTS . ' ORDER BY client_id'
        );
        $rows = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        foreach ($rows as &$row) {
            $row['secret'] = $this->encryptor->decrypt((string)($row['secret'] ?? ''));
        }
        return $rows;
    }

    /**
     * 取单个客户端原始行（不存在返回 null）。
     *
     * @return array<string,mixed>|null
     */
    public function find(string $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_id, secret, redirect_uri, `system`, enabled, description, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_OAUTH_CLIENTS . ' WHERE client_id = ?'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['secret'] = $this->encryptor->decrypt((string)($row['secret'] ?? ''));
        return $row;
    }

    /**
     * 保存（插入或更新）。行不存在时 INSERT（写 created_at/updated_at），
     * 已存在时 UPDATE（只刷新 updated_at，保留 created_at）。
     * 刻意不用 sqlUpsert/REPLACE INTO：后者会删除重插并重置 created_at。
     *
     * @param array{secret:string, redirect_uri:string, system:string, enabled:bool, description:string} $data
     */
    public function save(string $clientId, array $data): void
    {
        $secret      = $this->encryptor->encrypt((string)$data['secret']);
        $redirectUri = (string)$data['redirect_uri'];
        $system      = trim((string)$data['system']) !== '' ? trim((string)$data['system']) : AppConfig::SYSTEM_CI;
        $enabled     = !empty($data['enabled']) ? 1 : 0;
        $description = trim((string)$data['description']);

        if ($this->find($clientId) === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . AppConfig::TABLE_OAUTH_CLIENTS
                . ' (client_id, secret, redirect_uri, `system`, enabled, description, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ' . Database::sqlNow() . ', ' . Database::sqlNow() . ')'
            );
            $stmt->execute([$clientId, $secret, $redirectUri, $system, $enabled, $description]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_OAUTH_CLIENTS
            . ' SET secret = ?, redirect_uri = ?, `system` = ?, enabled = ?, description = ?,'
            . ' updated_at = ' . Database::sqlNow()
            . ' WHERE client_id = ?'
        );
        $stmt->execute([$secret, $redirectUri, $system, $enabled, $description, $clientId]);
    }

    /**
     * 删除客户端行（回到「未配置」，该客户端不再可用）。
     */
    public function delete(string $clientId): void
    {
        $this->pdo->prepare('DELETE FROM ' . AppConfig::TABLE_OAUTH_CLIENTS . ' WHERE client_id = ?')
            ->execute([$clientId]);
    }

    /**
     * 读取 DB 客户端（唯一权威）。$envClients 参数保留向后兼容（历史调用可能传 env 基线），
     * 但生产入口一律传 []：DB 无行的 client_id 不再回退 env。
     *   - enabled=1 的 DB 行进入结果；
     *   - enabled=0 的 DB 行显式移除该 client。
     *
     * 返回结构对齐 settings.php 的 oauth_clients 形状（secret/redirect_uri/system），
     * 最终 fail-closed 过滤仍由 OAuthService 构造函数兜底。
     *
     * @param array<string,array{secret:string,redirect_uri:string,system:string}> $envClients
     * @return array<string,array{secret:string,redirect_uri:string,system:string}>
     */
    public function resolveClients(array $envClients = []): array
    {
        $merged = $envClients;
        foreach ($this->list() as $row) {
            $id = trim((string)($row['client_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ((int)($row['enabled'] ?? 1) !== 1) {
                unset($merged[$id]);
                continue;
            }
            $merged[$id] = [
                'secret'       => (string)($row['secret'] ?? ''),
                'redirect_uri' => (string)($row['redirect_uri'] ?? ''),
                'system'       => (string)($row['system'] ?? ''),
            ];
        }
        return $merged;
    }
}
