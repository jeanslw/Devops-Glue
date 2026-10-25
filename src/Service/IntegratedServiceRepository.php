<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * integrated_services 仓储：集成服务启用契约。
 *
 * 边界约定：
 *   - CI 是本表的唯一写入方（后台 API 管理页的集成服务开关）；
 *   - CD 等集成服务对本表只读（读 enabled/provision 决定是否建表与准入），不写入；
 *   - 未来服务拆库独立部署时，CD 只依赖这一张窄表，接触点只有这一个 SELECT。
 *
 * 状态语义（三种状态，表 / 行 / 标志三级缺失按未启用处理）：
 *   - 表不存在，或行不存在，或 enabled=0：未启用，集成服务不得建表、不得提供服务；
 *   - enabled=1 且 provision=auto：启用，集成服务可在共享库自动建表并迁移；
 *   - enabled=1 且 provision=manual：启用，但集成服务禁止建表，只认运维预建表（CI 手动建库模式）。
 *
 * 关闭（enabled 由 1 改为 0）只冻结服务：集成服务自有表与数据原样保留，重新启用即恢复；
 * 凭据（api_tokens 行）的撤销由调用方在同事务内通过 ApiTokenService 完成；这里刻意保留 api_token_id：
 * 冻结不等于作废，再次启用要靠这个引用把同一枚凭据（连同原明文）原样恢复，CD 无需更换 CI_API_TOKEN。
 */
class IntegratedServiceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 取原始行（不存在返回 null）。
     *
     * @return array<string,mixed>|null
     */
    public function find(string $service): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, service_name, enabled, provision, api_token_id, updated_by, created_at, updated_at'
            . ' FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES . ' WHERE service_name = ?'
        );
        $stmt->execute([$service]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * 归一化状态（JOIN api_tokens 得到凭据是否可用）。行不存在时返回默认的未启用状态，
     * 调用方无需先判空，读到什么就是什么（不做任何种子写入）。
     *
     * 凭据拆成三个字段暴露（token_exists / token_enabled / token_expired），而不是只给一个 token_active：
     * 禁用（冻结）留下的、可原样恢复的凭据与已作废的凭据（撤销 / 删除 / 过期）在 token_active 上
     * 都是 false，但后续动作完全不同：前者再次启用即恢复同一枚（CD 无需换 Token），后者只能补发新凭据。
     *
     * @return array{
     *     enabled: bool,
     *     provision: string,
     *     token_id: int|null,
     *     token_name: string,
     *     token_exists: bool,
     *     token_enabled: bool,
     *     token_expired: bool,
     *     token_active: bool,
     *     updated_by: string,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    public function getState(string $service): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.enabled, s.provision, s.api_token_id, s.updated_by, s.created_at, s.updated_at,'
            . ' t.name AS token_name, t.enabled AS token_enabled, t.expires_at AS token_expires_at'
            . ' FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES . ' s'
            . ' LEFT JOIN ' . AppConfig::TABLE_API_TOKENS . ' t ON t.id = s.api_token_id'
            . ' WHERE s.service_name = ?'
        );
        $stmt->execute([$service]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'enabled'       => false,
                'provision'     => AppConfig::SERVICE_PROVISION_AUTO,
                'token_id'      => null,
                'token_name'    => '',
                'token_exists'  => false,
                'token_enabled' => false,
                'token_expired' => false,
                'token_active'  => false,
                'updated_by'    => '',
                'created_at'    => null,
                'updated_at'    => null,
            ];
        }

        $tokenId = $row['api_token_id'] !== null ? (int) $row['api_token_id'] : null;

        $tokenExists  = $tokenId !== null && $row['token_enabled'] !== null;
        $tokenEnabled = $tokenExists && (int) $row['token_enabled'] === 1;
        $tokenExpired = $tokenExists
            && $row['token_expires_at'] !== null
            && (int) $row['token_expires_at'] <= time();

        $tokenActive = $tokenExists && $tokenEnabled && !$tokenExpired;

        return [
            'enabled'       => (int) $row['enabled'] === 1,
            'provision'     => (string) $row['provision'],
            'token_id'      => $tokenId,
            'token_name'    => (string) ($row['token_name'] ?? ''),
            'token_exists'  => $tokenExists,
            'token_enabled' => $tokenEnabled,
            'token_expired' => $tokenExpired,
            'token_active'  => $tokenActive,
            'updated_by'    => (string) ($row['updated_by'] ?? ''),
            'created_at'    => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at'    => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * 启用：行不存在则插入，存在则切换为启用并绑定凭据。必须在事务内调用
     * （凭据 INSERT / UPDATE 与本行写入须在同一事务提交）。
     *
     * $tokenId 允许传入当前已绑定的同一枚（冻结后恢复）：此时实际只做
     * 把 enabled 置 1 并刷新 provision / updated_by，凭据行与明文不变。
     */
    public function enable(string $service, string $provision, int $tokenId, string $operator): void
    {
        if ($this->find($service) === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . AppConfig::TABLE_INTEGRATED_SERVICES
                . ' (service_name, enabled, provision, api_token_id, updated_by, created_at, updated_at)'
                . ' VALUES (?, 1, ?, ?, ?, ' . Database::sqlNow() . ', ' . Database::sqlNow() . ')'
            );
            $stmt->execute([$service, $provision, $tokenId, $operator]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_INTEGRATED_SERVICES
            . ' SET enabled = 1, provision = ?, api_token_id = ?, updated_by = ?,'
            . ' updated_at = ' . Database::sqlNow()
            . ' WHERE service_name = ?'
        );
        $stmt->execute([$provision, $tokenId, $operator, $service]);
    }

    /**
     * 禁用（冻结）：enabled 置 0，保留行与数据。返回被换下的凭据 id，由调用方在同事务内撤销。
     * 行不存在（从未启用）时返回 null。
     */
    public function disable(string $service, string $operator): ?int
    {
        $row = $this->find($service);
        if ($row === null) {
            return null;
        }
        $oldTokenId = $row['api_token_id'] !== null ? (int) $row['api_token_id'] : null;
        $stmt = $this->pdo->prepare(
            'UPDATE ' . AppConfig::TABLE_INTEGRATED_SERVICES
            . ' SET enabled = 0, updated_by = ?, updated_at = ' . Database::sqlNow()
            . ' WHERE service_name = ?'
        );
        $stmt->execute([$operator, $service]);
        return $oldTokenId;
    }
}
