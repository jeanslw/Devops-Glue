<?php

namespace App\Helper;

/**
 * Git remote URL 解析工具。
 *
 * 两个静态方法，输出语义不同、不可互相替代：
 *   - normalize()  用于**去重比对**：保留 host（忽略端口）、统一小写，产出 canonical 键；
 *   - extractPath() 用于**回传平台 API 作项目标识**：保持原始大小写（GitLab/Gitea 的
 *     projects API 以路径原文定位项目），且保留完整层级。
 * 两者共用同一套协议/scp 形式的解析规则。
 */
class GitRemote
{
    /**
     * 从 Git remote URL 提取完整仓库路径（owner/repo，或 GitLab 子群组 group/sub/repo）。
     *
     * 支持：
     *   https://host/group/repo.git            → group/repo
     *   https://host/group/sub/repo.git        → group/sub/repo   ← 子群组不再被截断
     *   git@host:group/sub/repo.git            → group/sub/repo
     *   ssh://git@host:2222/group/sub/repo.git → group/sub/repo   ← 端口不再混入路径
     *   git://host/group/repo                  → group/repo
     *
     * @return string|null 解析失败（空串、无 host/路径、不足两段）时返回 null，
     *                     由调用方自行决定兜底值。
     */
    public static function extractPath(string $remote): ?string
    {
        $r = trim($remote);
        if ($r === '') {
            return null;
        }

        if (preg_match('#^ssh://#i', $r)) {
            // 显式 ssh:// 形式：交给 parse_url，端口被单独解析，不会混入 path
            $parts = parse_url($r);
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        } else {
            $r = preg_replace('#^(https?|git)://#i', '', $r);
            if (preg_match('#^[^/@]+@([^:/]+):(.+)$#', $r, $m)) {
                // scp 形式 user@host:path —— 注意此形式本身不支持端口，
                // git 自身也会把冒号后的内容整体当作路径，故这里保持同样语义。
                $path = $m[2];
            } else {
                // host/group/sub/repo：去掉第一段 host，其余全部保留
                $slash = strpos($r, '/');
                $path = $slash === false ? '' : substr($r, $slash + 1);
            }
        }

        $path = trim($path, '/');
        $path = (string)preg_replace('#\.git$#i', '', $path);
        $path = rtrim($path, '/');

        // 至少要有 owner/repo 两段才算有效项目路径
        if ($path === '' || strpos($path, '/') === false) {
            return null;
        }

        return $path;
    }

    /**
     * 归一化 Git remote URL 为「host/org/repo」去重键（跨协议去重用）。
     *
     * 规则：只有 host 相同（忽略端口）且路径相同，才视为同一仓库。
     *   - 端口被忽略：同一 Gitea/GitLab 的 SSH(222) 与 HTTP(3000) 指向同一仓库 → 命中；
     *   - host 不同绝不归一化：两个平台「组织/项目名恰好相同」不会被误并成一条。
     *
     * 支持：
     *   git@github.com:org/repo.git          → github.com/org/repo
     *   https://github.com/org/repo          → github.com/org/repo
     *   ssh://git@host:222/org/repo.git      → host/org/repo
     *   https://10.0.0.5:3000/team/repo      → 10.0.0.5/team/repo
     */
    public static function normalize(string $remote): string
    {
        $r = trim($remote);
        if ($r === '') {
            return '';
        }

        $host = '';
        $path = '';

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $r)) {
            // 带协议：ssh:// / https?:// / git:// → parse_url 拆 host（端口自然排除）与 path
            $parts = parse_url($r);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = ltrim((string) ($parts['path'] ?? ''), '/');
        } elseif (preg_match('#^git@([^:]+):(.+)#', $r, $m)) {
            // scp 风格 git@host:path（无端口概念）
            $host = strtolower($m[1]);
            $path = ltrim($m[2], '/');
        } else {
            // 裸路径（无 host）
            $path = ltrim($r, '/');
        }

        $path = (string) preg_replace('#\.git$#i', '', $path);
        $path = rtrim($path, '/');
        $path = strtolower($path);

        return ($host !== '' && $path !== '') ? $host . '/' . $path : $path;
    }
}
