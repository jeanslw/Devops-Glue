<?php

namespace App\Helper;

/**
 * Git remote URL 解析工具。
 *
 * 两个静态方法，输出语义不同、不可互相替代：
 *   - normalize()   用于去重比对：保留 host（忽略端口）、统一小写，产出去重键；
 *   - extractPath() 用于回传平台 API 作项目标识：保持原始大小写（GitLab/Gitea 的
 *     projects API 以路径原文定位项目），且保留完整层级。
 * 两者共用同一套协议形式与 scp 形式的解析规则。
 */
class GitRemote
{
    /**
     * 从 Git remote URL 提取完整仓库路径（owner/repo，或 GitLab 子群组 group/sub/repo）。
     *
     * 支持：
     *   https://host/group/repo.git            得到 group/repo
     *   https://host/group/sub/repo.git        得到 group/sub/repo（子群组不被截断）
     *   git@host:group/sub/repo.git            得到 group/sub/repo
     *   ssh://git@host:2222/group/sub/repo.git 得到 group/sub/repo（端口不混入路径）
     *   git://host/group/repo                  得到 group/repo
     *
     * @return string|null 解析失败（空串、无 host 或路径、不足两段）时返回 null，
     *                     由调用方自行决定回退值。
     */
    public static function extractPath(string $remote): ?string
    {
        $r = trim($remote);
        if ($r === '') {
            return null;
        }

        if (preg_match('#^ssh://#i', $r)) {
            $parts = parse_url($r);
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        } else {
            $r = (string) preg_replace('#^(https?|git)://#i', '', $r);
            if (preg_match('#^[^/@]+@([^:/]+):(.+)$#', $r, $m)) {
                $path = $m[2];
            } else {
                $slash = strpos($r, '/');
                $path = $slash === false ? '' : substr($r, $slash + 1);
            }
        }

        $path = trim($path, '/');
        $path = (string)preg_replace('#\.git$#i', '', $path);
        $path = rtrim($path, '/');


        if ($path === '' || strpos($path, '/') === false) {
            return null;
        }

        return $path;
    }

    /**
     * 归一化 Git remote URL 为 host/org/repo 形式的去重键（跨协议去重用）。
     *
     * 规则：只有 host 相同（忽略端口）且路径相同，才视为同一仓库。
     *   - 端口被忽略：同一 Gitea/GitLab 的 SSH(222) 与 HTTP(3000) 指向同一仓库，视为同一仓库；
     *   - host 不同不归一化：两个平台组织与项目名恰好相同时不会被误并为一条。
     *
     * 支持：
     *   git@github.com:org/repo.git          得到 github.com/org/repo
     *   https://github.com/org/repo          得到 github.com/org/repo
     *   ssh://git@host:222/org/repo.git      得到 host/org/repo
     *   https://10.0.0.5:3000/team/repo      得到 10.0.0.5/team/repo
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
            $parts = parse_url($r);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = ltrim((string) ($parts['path'] ?? ''), '/');
        } elseif (preg_match('#^git@([^:]+):(.+)#', $r, $m)) {
            $host = strtolower($m[1]);
            $path = ltrim($m[2], '/');
        } else {
            $path = ltrim($r, '/');
        }

        $path = (string) preg_replace('#\.git$#i', '', $path);
        $path = rtrim($path, '/');
        $path = strtolower($path);

        return ($host !== '' && $path !== '') ? $host . '/' . $path : $path;
    }

    /**
     * 抹掉 Git remote URL 里的 userinfo（用户名/密码），供日志与异常消息使用。
     *
     *   https://user:pass@host/repo 中的 user:pass 被替换为固定掩码
     *   http://token@host/repo 中的 token 被替换为固定掩码
     *
     * scp 形式 git@host:path 的 `git` 是用户名而非凭据，也没有 `://`，不会命中，原样返回。
     */
    public static function maskCredentials(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]+@#i', '$1***@', $url);
    }
}
