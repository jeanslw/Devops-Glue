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
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 创建 API token，返回一次性明文。
     *
     * @param array<string,mixed> $data {
     *     name: string, scopes: array<int,string>|string, expires_at: int|string|null (unix 时间戳),
     *     created_by: int|string|null, note: string|null
     * }
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

        $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== ''
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
     * 编辑已有 token 的 scope 清单（不动明文与摘要，token 无需重新签发，下次请求即生效）。
     *
     * 与 createToken 不同：创建时非法 scope 会被忽略（宽松处理，避免表单误填导致建号失败），
     * 编辑时严格报错且拒绝空集合：scope 直接决定可访问范围，勾选界面上的笔误若被忽略，
     * 就会出现以为已收回权限或以为已授权的误解，故报错让用户重选。
     *
     * @param mixed $scopes 数组或逗号分隔字符串；空串与非标量项会被忽略
     * @return bool false 表示 token 不存在（此时不写入任何内容）
     * @throws \InvalidArgumentException 含目录外的 scope，或过滤后为空集合
     * @throws \PDOException 查询 / 写入失败（ERRMODE_EXCEPTION）
     */
    public function updateScopes(int $id, mixed $scopes): bool
    {
        if ($this->findById($id) === null) {
            return false;
        }

        $items = is_string($scopes) ? explode(',', $scopes) : $scopes;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Scopes must be an array or a comma-separated string');
        }

        $normalized = [];
        foreach ($items as $item) {
            $key = is_scalar($item) ? trim((string)$item) : '';
            if ($key === '') {
                continue;
            }
            if (!isset(AppConfig::API_SCOPES[$key])) {
                throw new \InvalidArgumentException('Unknown scope: ' . $key);
            }
            if (!in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one scope is required');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET scopes = ? WHERE id = ?'
        );
        $stmt->execute([implode(',', $normalized), $id]);
        return true;
    }

    /**
     * 把 scope 并入既有 token 的 scope 清单（只补不删，幂等）。
     *
     * 用于「凭据契约扩充后的存量补齐」：例如 v2.9.0 给 CD 服务凭据新增 git scope（CD 构建页
     * 改走 /api/git/{job}/refs 取 ref 候选值），已签发的存量凭据需要补齐；若改为重新签发，
     * CD 侧必须改 CI_API_TOKEN（运维成本 + 停机风险）。
     *
     * 与 updateScopes 的严格校验刻意不同，这里偏宽松（自愈路径，不该因脏值失败）：
     *   - 目录外的 scope 直接忽略（调用方通常传常量契约，脏值不应让补齐中断）；
     *   - 只做并集，绝不收回管理员手工追加的 scope（有意扩权的 token 不该被自愈悄悄降权）；
     *   - 已是超集时不写库（避免每次启动产生无意义的 UPDATE）。
     *
     * 摘要与明文均不动，故调用方无需更换 token。
     *
     * @param mixed $scopes 数组或逗号分隔字符串
     * @return bool false 表示 token 不存在（此时不写入任何内容）
     * @throws \PDOException 查询 / 写入失败（ERRMODE_EXCEPTION）
     */
    public function addScopes(int $id, mixed $scopes): bool
    {
        $row = $this->findById($id);
        if ($row === null) {
            return false;
        }

        $current    = $this->scopesToArray(isset($row['scopes']) ? (string) $row['scopes'] : null);
        $normalized = array_values(array_unique(array_merge($current, $this->normalizeScopes($scopes))));
        if ($normalized === $current) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET scopes = ? WHERE id = ?'
        );
        $stmt->execute([implode(',', $normalized), $id]);
        return true;
    }

    /**
     * 列出全部 token（不含明文）。
     * 附上 scope 数组与是否过期的派生字段，方便 UI 直接渲染。
     *
     * @return array<int,array<string,mixed>>
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

    /**
     * 按主键查找 token 记录（含摘要列，仅供管理端判断存在性；结果不得回写到 HTTP 响应）。
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . AppConfig::TABLE_API_TOKENS . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 撤销（禁用）token —— 只改 enabled，token_hash 一字不动，故可用 restore 原样恢复（见下） */
    public function revoke(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET enabled = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 恢复（重新启用）被冻结的 token，即 revoke 的逆操作，同样只改 enabled，token_hash 不变。
     *
     * 因此原明文重新生效：已持有该 token 的调用方（如 CD 的 CI_API_TOKEN）无需重新配置。
     * 使用约定（必须与调用方一致）：在集成服务语境里 revoke 表示禁用（冻结），可用 restore 复原；
     * 真要作废一枚凭据请走 delete()（硬删除，摘要一并消失，不可恢复，只能重新签发）。
     *
     * @return bool false = 未命中任何行（id 不存在），或该行本就是启用态
     *              （MySQL 的 rowCount 只计实际发生变更的行）；调用方只在确认处于禁用态时调用
     */
    public function restore(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET enabled = 1 WHERE id = ?');
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

    /**
     * 按 sha256 摘要查找 token 记录（供 TokenService 校验复用）
     *
     * @return array<string,mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . AppConfig::TABLE_API_TOKENS . ' WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 校验并返回 token 元信息：仅当 enabled 且未过期时返回非 null。scopes 已解析为数组。
     *
     * @return array{id:int, name:mixed, scopes:array<int,string>}|null
     */
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
     *
     * @param mixed $scopes 字符串（逗号分隔）或数组；其它类型按空集合处理
     * @return array<int,string>
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

    /**
     * @return array<int,string>
     */
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
     *
     * @param array<int,string> $scopes
     * @return array<int,string>
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
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
