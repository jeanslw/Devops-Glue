<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * API Token 服务
 *
 * 供 CD 系统服务账号 / 第三方调用。token 独立于 RBAC 权限体系，直接携带 scope 清单：
 *   - 生成 `dg_` 前缀 + 64 位十六进制明文，仅存储 sha256 摘要（明文只在创建时返回一次）
 *   - scope 仅在 AppConfig::API_SCOPES 目录内取值
 */
class ApiTokenService
{
    public function __construct(private \PDO $pdo, private AppConfig $config)
    {
    }

    /**
     * 创建 API token，返回一次性明文。
     *
     * @param array $data { name, scopes(array|string), expires_at(?int unix), created_by, note }
     * @return array{id:int, token:string}
     */
    public function createToken(array $data): array
    {
        // 以 dg_ 前缀标识，便于一眼识别为服务账号 token（与管理员登录 token 区分）
        $plain = 'dg_' . bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $name    = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Token name is required');
        }

        $scopes = $this->normalizeScopes($data['scopes'] ?? []);

        $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== '' && $data['expires_at'] !== null
            ? (int)$data['expires_at']
            : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_API_TOKENS
            . ' (name, token_hash, scopes, enabled, expires_at, created_by, note, created_at)'
            . ' VALUES (?, ?, ?, 1, ?, ?, ?, ' . Database::sqlNow() . ')'
        );
        $stmt->execute([
            $name,
            $hash,
            $scopes === [] ? null : implode(',', $scopes),
            $expiresAt,
            $data['created_by'] ?? null,
            $data['note'] ?? null,
        ]);

        return ['id' => (int)$this->pdo->lastInsertId(), 'token' => $plain];
    }

    /**
     * 列出全部 token（不含明文）。
     * 附上 scope 数组与是否过期的派生字段，方便 UI 直接渲染。
     */
    public function listTokens(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name, scopes, enabled, expires_at, created_by, note, created_at'
            . ' FROM ' . AppConfig::TABLE_API_TOKENS . ' ORDER BY id DESC'
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->decorate($row);
        }
        return $result;
    }

    /** 撤销（禁用）token */
    public function revoke(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET enabled = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** 硬删除 token 记录 */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . AppConfig::TABLE_API_TOKENS . ' WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** 按 sha256 摘要查找 token 记录（供 TokenService 校验复用） */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . AppConfig::TABLE_API_TOKENS . ' WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 校验并返回 token 元信息：仅当 enabled 且未过期时返回非 null。scopes 已解析为数组。 */
    public function resolve(string $hash): ?array
    {
        $row = $this->findByHash($hash);
        if (!$row) {
            return null;
        }
        if ((int)$row['enabled'] !== 1) {
            return null;
        }
        if ($row['expires_at'] !== null && (int)$row['expires_at'] <= time()) {
            return null;
        }
        return [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'scopes' => $this->scopesToArray($row['scopes']),
        ];
    }

    /**
     * 校验并规范化 scopes：只接受 AppConfig::API_SCOPES 中存在的 key。
     * 传入字符串（逗号分隔）或数组均可，返回去重后的 key 数组。
     */
    public function normalizeScopes($scopes): array
    {
        if (is_string($scopes)) {
            $scopes = explode(',', $scopes);
        }
        if (!is_array($scopes)) {
            return [];
        }
        $valid = [];
        foreach ($scopes as $s) {
            $key = trim((string)$s);
            if ($key !== '' && isset(AppConfig::API_SCOPES[$key]) && !in_array($key, $valid, true)) {
                $valid[] = $key;
            }
        }
        return $valid;
    }

    private function scopesToArray(?string $scopes): array
    {
        if ($scopes === null || $scopes === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $scopes)), fn($s) => $s !== ''));
    }

    /**
     * 把 scope 数组展开为具体接口能力清单（去重、保持目录定义顺序）。
     * 未在 API_SCOPE_CAPABILITIES 登记时，退化为展示 scope 键本身。
     */
    private function capabilitiesFor(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            $caps = AppConfig::API_SCOPE_CAPABILITIES[$scope] ?? [$scope];
            foreach ($caps as $cap) {
                if (!in_array($cap, $out, true)) {
                    $out[] = $cap;
                }
            }
        }
        return $out;
    }

    private function decorate(array $row): array
    {
        $expiresAt = $row['expires_at'] !== null ? (int)$row['expires_at'] : null;
        $scopes = $this->scopesToArray($row['scopes']);
        return [
            'id'           => (int)$row['id'],
            'name'         => $row['name'],
            'scopes'       => $scopes,
            'capabilities' => $this->capabilitiesFor($scopes),
            'enabled'      => (int)$row['enabled'] === 1,
            'expires_at' => $expiresAt,
            'expired'    => $expiresAt !== null && $expiresAt <= time(),
            'created_by' => $row['created_by'],
            'note'       => $row['note'],
            'created_at' => $row['created_at'],
        ];
    }
}
