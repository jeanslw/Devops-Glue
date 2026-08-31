<?php

namespace App\Helper;

/**
 * Git remote URL 解析工具。
 *
 * 与 AutoDiscover::normalizeRemote() 的区别：
 *   - normalizeRemote() 用于**去重比对**，会丢弃 host 并统一转小写，产出 canonical 键；
 *   - 本类用于**回传给平台 API 作项目标识**，必须保持原始大小写（GitLab/Gitea 的
 *     projects API 以路径原文定位项目），且必须保留完整层级。
 * 两者共用同一套协议/scp 形式的解析规则，但输出语义不同，不可互相替代。
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
}
