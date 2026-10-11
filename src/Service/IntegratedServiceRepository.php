<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * integrated_services 仓储：集成服务启用契约。
 *
 * 边界约定：
 *   - CI 是本表的**唯一写入方**（后台「数据管理 → 集成服务」开关）；
 *   - CD 等集成服务对本表**只读**（读 enabled/provision 决定是否建表/准入），绝不写；
 *   - 未来服务拆库独立部署时，CD 只依赖这一张窄表，接触点仅此一个 SELECT。
 *
 * 状态语义（三态，表/行/标志三级缺失等价处理）：
 *   - 表不存在 / 行不存在 / enabled=0 —— 未启用：集成服务不得建表、不得提供服务；
 *   - enabled=1 + provision=auto   —— 启用，集成服务可在共享库自动建表+迁移；
 *   - enabled=1 + provision=manual —— 启用，但集成服务禁止建表，只认运维预建表（CI 手动建库模式）。
 *
 * 关闭（enabled 1→0）只**冻结**服务：集成服务自有表与数据原样保留，重新启用即恢复；
 * 凭据（api_tokens 行）的撤销由调用方在同事务内通过 ApiTokenService 完成。
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
     * 归一化状态（JOIN api_tokens 派生凭据活性）。行不存在时返回默认「未启用」状态 ——
     * 调用方无需先判空，读到什么就是什么（不做任何种子写入）。
     *
     * @return array{enabled:bool, provision:string, token_id:int|null, token_name:string, token_active:bool, updated_by:string, created_at:string|null, updated_at:string|null}
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
                'enabled'      => false,
                'provision'    => AppConfig::SERVICE_PROVISION_AUTO,
                'token_id'     => null,
                'token_name'   => '',
                'token_active' => false,
                'updated_by'   => '',
                'created_at'   => null,
                'updated_at'   => null,
            ];
        }

        $tokenId = $row['api_token_id'] !== null ? (int) $row['api_token_id'] : null;
        $tokenActive = $tokenId !== null
            && (int) $row['token_enabled'] === 1
            && ($row['token_expires_at'] === null || (int) $row['token_expires_at'] > time());

        return [
            'enabled'      => (int) $row['enabled'] === 1,
            'provision'    => (string) $row['provision'],
            'token_id'     => $tokenId,
            'token_name'   => (string) ($row['token_name'] ?? ''),
            'token_active' => $tokenActive,
            'updated_by'   => (string) ($row['updated_by'] ?? ''),
            'created_at'   => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at'   => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * 启用：行不存在则插入，存在则切换为启用并绑定新凭据。必须在事务内调用
     * （凭据 INSERT 与本行写入须同生共死）。
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
