<?php

namespace App\Service\Git;

interface GitProviderInterface
{
    /**
     * 获取仓库分支列表（自动翻页，上限 2000 条）
     * @param string $repository 仓库标识（格式因平台而异，如 "group/project" 或 "owner/repo"）
     * @return string[] 分支名称数组
     */
    public function getBranches(string $repository): array;

    /**
     * 获取仓库 tag 列表（自动翻页，上限 2000 条）
     * @param string $repository 仓库标识
     * @return string[] tag 名称数组
     */
    public function getTags(string $repository): array;

    /**
     * 回写 commit 状态（Harbor 扫描结果、构建结果等外部检查）
     *
     * 在各 Git 平台的 commit/MR 页面上显示一个状态标记，
     * 对应 GitLab/GitHub 的 Commit Status API。
     *
     * @param string $repository  仓库标识（如 "group/project"）
     * @param string $sha         commit SHA
     * @param string $state       状态: pending / success / failed / error
     * @param string $context     上下文名称（如 "harbor-scan"）
     * @param string $description 描述文本
     * @param string $targetUrl   详情链接（可选）
     * @return array [success => bool, message => string]
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array;

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
