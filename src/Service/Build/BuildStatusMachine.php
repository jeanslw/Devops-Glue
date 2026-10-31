<?php

declare(strict_types=1);

namespace App\Service\Build;

/**
 * 构建状态机：集中定义 custom_push 构建结果的状态转移与陈旧判定。
 *
 * 仅用于约束“同一 (project, sha, check_type, context) 的构建结果/审计写入”，
 * 防止：旧状态覆盖新状态、终态被降级。不涉及 commit status / registry-sync 审计
 * （那些来源单调、且需保留重试语义，保持“最新值获胜”）。
 *
 * 转移规则：success 为不可逆终态（已 success 不接受降级，防止与已写入的 artifact tag 冲突）；
 * failed / aborted / error 及中间态之间允许互转，并允许升级到 success（支持重试成功）。
 */
final class BuildStatusMachine
{
    private const STATE_SUCCESS = 'success';

    /**
     * 自定义推送语义下是否允许从 from 转移到 to。
     *
     * - 相同状态：幂等，允许；
     * - success -> 非 success：拒绝（不可逆终态）；
     * - 其余任意（含 -> success）：允许。
     */
    public static function canTransition(string $from, string $to): bool
    {
        if ($from !== $to && $from === self::STATE_SUCCESS) {
            return false;
        }
        return true;
    }

    /**
     * 传入时间是否早于已有时间（用于忽略陈旧上报）。
     * 无法解析为时间戳时退化为字符串比较，保证不抛异常。
     */
    public static function isStale(string $incoming, string $existing): bool
    {
        $a = strtotime($incoming);
        $b = strtotime($existing);
        if ($a !== false && $b !== false) {
            return $a < $b;
        }
        return strcmp($incoming, $existing) < 0;
    }
}
