<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Controller\AdminController;
use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * 「后台手动触发 tag 定时任务」的接线与文案回归测试
 *
 * 锁定三件事，任一被改坏都应立刻失败：
 *   1. 路由：POST /api/admin/tag_cleanup → runTagCleanup、POST /api/admin/tag_backfill → runTagBackfill；
 *   2. 控制器：两个公开动作方法存在（后端 guardTagJob 的 409 语义由实现保证）；
 *   3. 界面：两个卡片按钮 + 状态位存在且接到 admin.js 暴露的同名全局函数，
 *      且 JS/后端用到的 i18n key 在 zh_CN / en 两份语言包里都存在且非空。
 *
 * 运行：vendor/bin/phpunit tests/Unit/AdminTagJobTriggerTest.php
 */
class AdminTagJobTriggerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 手动触发按钮 → 控制器动作方法 的期望接线 */
    private const ROUTES = [
        '/tag_cleanup' => 'runTagCleanup',
        '/tag_backfill' => 'runTagBackfill',
    ];

    /** 手动触发相关的 i18n key（前端 + 后端共用，必须双语齐备） */
    private const I18N_KEYS = [
        // 按钮 / 二次确认 / 执行中 / 结果（前端）
        'build.stale_tag_cleanup_run_btn',
        'build.stale_tag_cleanup_run_confirm',
        'build.stale_tag_cleanup_run_note',
        'build.stale_tag_cleanup_running',
        'build.stale_tag_cleanup_done',
        'build.tag_backfill_run_btn',
        'build.tag_backfill_run_confirm',
        'build.tag_backfill_run_note',
        'build.tag_backfill_running',
        'build.tag_backfill_done',
        // 按钮置灰提示（前端）
        'build.tag_cleanup_disabled',
        'build.tag_backfill_disabled',
        // 后端 409 / 500 文案
        'build.harbor_unconfigured',
        'build.stale_tag_cleanup_failed',
        'build.tag_backfill_failed',
        // 操作日志动作名
        'oplog.act_cleanup_pipeline_tags',
        'oplog.act_backfill_pipeline_tags',
    ];

    /** 两个按钮 id → 前端全局函数名 */
    private const BUTTONS = [
        'stale-tag-run-btn' => 'runStaleTagCleanup',
        'backfill-tag-run-btn' => 'runTagBackfill',
    ];

    public function testTagJobRoutesAreWiredToControllerActions(): void
    {
        $routes = (string) file_get_contents(self::ROOT . '/config/routes.php');

        foreach (self::ROUTES as $path => $action) {
            $pattern = "/->map\\(\\['POST'\\], '" . preg_quote($path, '/')
                . "', \\[AdminController::class, '" . $action . "'\\]\\)/";
            $this->assertMatchesRegularExpression(
                $pattern,
                $routes,
                "config/routes.php 必须注册 POST /api/admin{$path} → AdminController::{$action}"
            );
            $this->assertTrue(
                method_exists(AdminController::class, $action),
                "AdminController::{$action} 必须存在且为公开动作方法"
            );
        }
    }

    public function testManualTriggerButtonsExistAndAreBoundInAdminJs(): void
    {
        $adminHtml = (string) file_get_contents(self::ROOT . '/templates/admin.html');
        $adminJs   = (string) file_get_contents(self::ROOT . '/public/assets/admin.js');
        $modeJs    = (string) file_get_contents(self::ROOT . '/public/assets/modules/mode.js');

        foreach (self::BUTTONS as $id => $fn) {
            $this->assertMatchesRegularExpression(
                '/<button id="' . preg_quote($id, '/') . '"[^>]*onclick="' . preg_quote($fn, '/') . '\(\)"/s',
                $adminHtml,
                "templates/admin.html 必须存在按钮 #{$id} 且 onclick 调用 {$fn}()"
            );
            $this->assertMatchesRegularExpression(
                '/<button id="' . preg_quote($id, '/') . '"[^>]*\bdisabled\b/s',
                $adminHtml,
                "按钮 #{$id} 初始必须 disabled（等开关状态同步后再放开）"
            );
            $this->assertStringContainsString(
                $fn,
                $adminJs,
                "public/assets/admin.js 必须把 {$fn} 暴露到全局供 onclick 调用"
            );
            $this->assertMatchesRegularExpression(
                '/export\s+async\s+function\s+' . preg_quote($fn, '/') . '\s*\(/',
                $modeJs,
                "modules/mode.js 必须导出 {$fn}"
            );
            $this->assertStringContainsString(
                "'{$id}'",
                $modeJs,
                "modules/mode.js::syncTagRunButtons 必须覆盖按钮 #{$id}（开关关闭时置灰）"
            );
        }

        $this->assertMatchesRegularExpression(
            '/export\s+function\s+syncTagRunButtons\s*\(/',
            $modeJs,
            'modules/mode.js 必须导出 syncTagRunButtons（开关切换后同步按钮可用性）'
        );
    }

    public function testManualTriggerI18nKeysExistInBothLocales(): void
    {
        $i18n = new I18nService(self::ROOT . '/lang', 'zh_CN');

        foreach (['zh_CN', 'en'] as $locale) {
            $messages = $i18n->getAll($locale);
            $this->assertNotEmpty($messages, "{$locale} 语言包不能为空");
            foreach (self::I18N_KEYS as $key) {
                $this->assertArrayHasKey($key, $messages, "{$locale} 缺少翻译: {$key}");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$locale} 翻译为空: {$key}");
            }
        }
    }

    /** 前端结果文案里的占位符必须与 PipelineTagService 返回的统计键一一对应 */
    public function testDoneMessagesUseServiceStatPlaceholders(): void
    {
        $i18n  = new I18nService(self::ROOT . '/lang', 'zh_CN');
        $cases = [
            'build.stale_tag_cleanup_done' => ['checked', 'deleted', 'unreachable', 'unverifiable'],
            'build.tag_backfill_done' => ['checked', 'promoted', 'skipped', 'unreachable', 'unverifiable'],
        ];

        foreach ($cases as $key => $placeholders) {
            foreach (['zh_CN', 'en'] as $locale) {
                $msg = (string) $i18n->getAll($locale)[$key];
                foreach ($placeholders as $ph) {
                    $this->assertStringContainsString(
                        '{' . $ph . '}',
                        $msg,
                        "{$locale} 的 {$key} 必须包含 {{{$ph}}} 占位符（对应服务层统计键）"
                    );
                }
            }
        }
    }
}
