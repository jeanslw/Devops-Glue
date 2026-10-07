<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * 平台 API 版本仓储（ci_platform_versions 表）。
 *
 * 版本号有三个来源（优先级从高到低）：
 *   1. settings.php 显式配置（source=config，UI 只读，由 Settings 传入覆盖值）
 *   2. ci_platform_versions 表（source=database，管理界面可改，本类负责读写）
 *   3. 本类内置默认值（source=default）
 *
 * 从原 AppConfig 迁出：配置读取归 Settings，DB 读写归本仓储。
 */
class PlatformVersionRepository
{
    /** 各平台内置默认 API 版本（DB/配置均缺省时使用） */
    public const DEFAULT_API_VERSIONS = [
        'gitlab' => 'v4',
        'gitee'  => 'v5',
        'github' => 'v3',
        'gitea'  => 'v1',
        'harbor' => 'v2.0',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 合并默认值与 DB 覆盖值（不合并 settings.php 配置，那一层由调用方/Settings 处理）。
     *
     * @return array<string, array{value:string, source:string}>
     */
    public function allWithSource(): array
    {
        $result = [];
        foreach (self::DEFAULT_API_VERSIONS as $name => $default) {
            $result[$name] = ['value' => $default, 'source' => 'default'];
        }
        try {
            $rows = $this->pdo->query(
                "SELECT platform, version FROM " . AppConfig::TABLE_PLATFORM_VERSIONS
            )->fetchAll();
            foreach ($rows as $r) {
                if (isset($result[$r['platform']])) {
                    // 线值沿用 'json'：前端 versions.js 据此渲染「管理界面」徽标
                    $result[$r['platform']] = ['value' => $r['version'], 'source' => 'json'];
                }
            }
        } catch (\Exception $e) {
            // DB 不可用时保持默认
        }
        return $result;
    }

    /** @return array<string,string> platform => version */
    public function all(): array
    {
        $result = [];
        foreach ($this->allWithSource() as $name => $info) {
            $result[$name] = $info['value'];
        }
        return $result;
    }

    /**
     * 保存管理界面提交的版本：与默认值相同的不落库（仅存覆盖值）。
     *
     * @param array<string,string|null> $data
     */
    public function saveAll(array $data): void
    {
        $this->pdo->exec("DELETE FROM " . AppConfig::TABLE_PLATFORM_VERSIONS);
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_PLATFORM_VERSIONS . " (platform, version) VALUES (?, ?)"
        );
        foreach ($data as $name => $ver) {
            $default = self::DEFAULT_API_VERSIONS[$name] ?? null;
            if ($ver !== $default && $ver !== '' && $ver !== null) {
                $stmt->execute([$name, $ver]);
            }
        }
    }
}
