<?php

namespace App\Service\Registry;

/**
 * 镜像仓库提供方抽象。
 *
 * 仓库标识统一由调用方传入完整仓库名：
 *  - Harbor：project/repo 两段式（如 mycode/runner-ci）；
 *  - 标准 OCI Distribution（registry:2）：仓库名原样使用（如 mycode/runner-ci）。
 */
interface RegistryProviderInterface
{
    /**
     * 提供方类型标识：harbor | registry。
     */
    public function type(): string;

    /**
     * 是否具备漏洞扫描能力。
     */
    public function supportsScanning(): bool;

    /**
     * 仓库 tag 列表。
     *
     * 业务错误（仓库不存在、认证失败等）返回 ['error' => string, 'http_code' => int]；
     * 连接级失败抛 RuntimeException，由调用方按服务不可达处理。
     *
     * @return array<int|string,mixed>
     */
    public function getTags(string $repository): array;

    /**
     * 仓库是否存在于镜像仓库。
     *
     * 连接级失败抛 RuntimeException；业务层 404/空列表返回 false。
     */
    public function repositoryExists(string $repository): bool;

    /**
     * 项目（命名空间）列表。
     *
     * Harbor：Harbor 的项目列表；
     * 标准 OCI Distribution（registry:2）：由 /v2/_catalog 的仓库名首段聚合（该协议没有 project 概念）。
     *
     * 业务错误（列出仓库的接口不可用、认证失败等）返回 ['error' => string, 'http_code' => int]；
     * 连接级失败抛 RuntimeException，由调用方按服务不可达处理。
     *
     * @return array<int|string,mixed>
     */
    public function getProjects(): array;

    /**
     * 指定项目下的仓库名列表（已去掉项目前缀，与 Harbor v2 API 语义一致）。
     *
     * 同上错误约定；项目不存在返回 ['error' => string, 'http_code' => 404]。
     *
     * @return array<int|string,mixed>
     */
    public function getRepositories(string $project): array;

    /**
     * 漏洞扫描报告。
     *
     * 不支持扫描的提供方返回 ['error' => string]。
     *
     * @return array<int|string,mixed>
     */
    public function getScanReport(string $repository, string $tag): array;
}
