<?php
namespace App\Service\Git;

interface GitProviderInterface
{
    /**
     * 获取仓库分支列表
     * @param string $repository 仓库标识（格式因平台而异，如 "group/project" 或 "owner/repo"）
     * @return string[] 分支名称数组
     */
    public function getBranches(string $repository): array;

    /**
     * 返回平台唯一标识名
     * @return string 如 'gitlab', 'gitee', 'github', 'gitea'
     */
    public function getName(): string;

    /**
     * 判断给定的 Git remote URL 是否属于该平台
     * @param string $url Git remote URL（如 http://gitlab.example.com/group/project.git）
     * @return bool
     */
    public function matchUrl(string $url): bool;

    /**
     * 返回该平台当前使用的 API 版本
     * @return string 如 'v4', 'v5', 'v3', 'v1'
     */
    public function getApiVersion(): string;
}