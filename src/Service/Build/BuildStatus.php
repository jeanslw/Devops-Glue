<?php

namespace App\Service\Build;

/**
 * 构建状态统一枚举：把 Jenkins / GitLab CI / Gitea Actions / custom_push 各自的原生
 * 状态词归一为项目自己的词汇，供前端 badge 与看板统一使用。
 *
 * 归一后的词汇（终态与中间态）：
 *   success / failed / unstable / canceled / running / pending / manual / skipped / unknown
 *
 * 映射原则：只做等价状态合并（各来源原生状态归并到现有词汇，不新增状态）；未收录的值小写透传，空串归一为 unknown。
 *  - Jenkins UNSTABLE 保留为独立的 unstable（构建成功但有告警或测试失败，不等于 failed）。
 *  - GitLab manual 保留为独立的 manual（等待人工触发，不等于 pending）；scheduled/created/
 *    waiting_for_resource/preparing 归入 pending（等待执行）。
 *  - Jenkins ABORTED / GitLab canceled / Gitea cancelled / custom_push aborted 同义，统一为 canceled。
 *  - GitHub Actions 的 run/job 分离了 status 与 conclusion：status 侧 requested/pending/queued 归 pending，
 *    conclusion 侧 neutral/stale 归 skipped（未失败但非成功）、timed_out/startup_failure/action_required 归 failed。
 */
final class BuildStatus
{
    public const SUCCESS  = 'success';
    public const FAILED   = 'failed';
    public const UNSTABLE = 'unstable';
    public const CANCELED = 'canceled';
    public const RUNNING  = 'running';
    public const PENDING  = 'pending';
    public const MANUAL   = 'manual';
    public const SKIPPED  = 'skipped';
    public const UNKNOWN  = 'unknown';

    private const MAP = [
        // 成功
        'success' => self::SUCCESS,
        // 失败
        'failure' => self::FAILED,
        'failed'  => self::FAILED,
        // 构建成功但告警/测试失败（Jenkins 黄灯）
        'unstable' => self::UNSTABLE,
        // 取消/中止
        'cancelled' => self::CANCELED,
        'canceled'  => self::CANCELED,
        'aborted'   => self::CANCELED,
        // 运行中
        'running'     => self::RUNNING,
        'in_progress' => self::RUNNING, // Gitea Actions job 运行中
        // 排队/等待（含定时）
        'pending'              => self::PENDING,
        'waiting'              => self::PENDING,
        'blocked'              => self::PENDING,
        'created'              => self::PENDING,
        'waiting_for_resource' => self::PENDING,
        'preparing'            => self::PENDING,
        'scheduled'            => self::PENDING,
        'queued'               => self::PENDING, // Gitea Actions job 排队中
        'requested'            => self::PENDING, // GitHub Actions run/job 排队中
        // 等待人工触发（GitLab manual）
        'manual' => self::MANUAL,
        // 跳过/未构建
        'skipped'   => self::SKIPPED,
        'not_built' => self::SKIPPED,
        'neutral'   => self::SKIPPED, // GitHub Actions conclusion：未失败但非成功（条件未命中/中立）
        'stale'     => self::SKIPPED, // GitHub Actions conclusion：被更新的 run 取代
        // GitHub Actions conclusion 的失败族
        'timed_out'        => self::FAILED,
        'startup_failure'  => self::FAILED,
        'action_required'  => self::FAILED,
        // 兜底
        'unknown' => self::UNKNOWN,
    ];

    public static function normalize(string $raw): string
    {
        $s = strtolower(trim($raw));
        return self::MAP[$s] ?? ($s === '' ? self::UNKNOWN : $s);
    }
}
