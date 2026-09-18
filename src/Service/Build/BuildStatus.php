<?php

namespace App\Service\Build;

/**
 * 构建状态统一枚举：把 Jenkins / GitLab CI / Gitea Actions / custom_push 各自的原生
 * 状态词归一为项目自己的词汇，供前端 badge 与看板统一消费。
 *
 * 归一后的词汇（终态 + 中间态）：
 *   success / failed / unstable / canceled / running / pending / manual / skipped / unknown
 *
 * 映射原则：只做「等价状态合并」，不虚构中间态；未收录的值小写透传，空串归一为 unknown。
 *  - Jenkins UNSTABLE 保留为独立 unstable（构建成功但告警/测试失败，非干净成功，≠ failed）。
 *  - GitLab manual 保留为独立 manual（等待人工触发，≠ pending）；scheduled/created/
 *    waiting_for_resource/preparing 归入 pending（等待执行）。
 *  - Jenkins ABORTED / GitLab canceled / Gitea cancelled / custom_push aborted 同义 → canceled。
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
        // 等待人工触发（GitLab manual）
        'manual' => self::MANUAL,
        // 跳过/未构建
        'skipped'   => self::SKIPPED,
        'not_built' => self::SKIPPED,
        // 兜底
        'unknown' => self::UNKNOWN,
    ];

    public static function normalize(string $raw): string
    {
        $s = strtolower(trim($raw));
        return self::MAP[$s] ?? ($s === '' ? self::UNKNOWN : $s);
    }
}
