<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * 守卫不变量：「集成服务」面板必须挂在「API 管理」页（Token 列表**上方**），
 * 且已从「数据管理」页彻底迁出（v2.9.0 起）。
 *
 * 迁移动机：该面板的动作本质是**授权 + 发凭据**——启用即在事务内签发限定 scope 的
 * 服务 Token、禁用即撤销它；这些 Token 就出现在同一页的下方列表里，同屏才能让
 * 「这枚 token 从哪来 / 为什么不能手改」一目了然。`provision`（建表方式）只是
 * 契约的另一个维度，其取值仍取自「数据管理 → 迁移模式」。
 *
 * 为何要守：这类纯 UI 搬迁最容易「看着搬了其实漏一处」——面板留在原 tab、
 * 加载只在旧 tab 触发（进新 tab 状态永远空白）、inline onclick/onchange 忘记挂 window、
 * 双语只补一半。以上都不会让任何 PHP 单测变红，只会在浏览器里静默失效。
 */
class IntegratedServicePanelPlacementTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 面板自身的 DOM 契约（缺一个就是渲染/交互残缺）。 */
    private const PANEL_IDS = [
        'isvc-loading',
        'isvc-body',
        'isvc-cd-dot',
        'isvc-cd-status',
        'isvc-cd-meta',
        'isvc-cd-toggle-wrap',
        'isvc-cd-toggle',
        'isvc-token-box',
        'isvc-token-value',
    ];

    public function testPanelLivesInsideApiTokensTab(): void
    {
        $apiTab = $this->tabSection('api-tokens', 'operation-logs');

        $this->assertStringContainsString('data-i18n="sys.panel_integrated"', $apiTab, '集成服务面板应在 API 管理页内');

        foreach (self::PANEL_IDS as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $apiTab, '面板缺少 #' . $id);
        }
        foreach ([
            "onchange=\"onIntegratedServiceToggle('cd')\"",
            'onclick="copyIntegratedToken()"',
            'onclick="hideIntegratedTokenBox()"',
        ] as $handler) {
            $this->assertStringContainsString($handler, $apiTab, '面板缺少交互入口 ' . $handler);
        }
    }

    public function testPanelSitsAboveTheTokenList(): void
    {
        $apiTab = $this->tabSection('api-tokens', 'operation-logs');

        $panel = strpos($apiTab, 'isvc-cd-dot');
        $list  = strpos($apiTab, 'api-token-tbody');
        $this->assertIsInt($panel);
        $this->assertIsInt($list);
        $this->assertLessThan(
            $list,
            $panel,
            '集成服务面板必须在 Token 列表（#api-token-tbody）之前 —— 它是列表里那枚 CD token 的来源'
        );
    }

    public function testPanelIsGoneFromDataManagementTab(): void
    {
        $sysTab = $this->tabSection('system-info', null);

        $this->assertStringNotContainsString('sys.panel_integrated', $sysTab, '数据管理页不应再残留集成服务面板');
        $this->assertStringNotContainsString('isvc-', $sysTab, '数据管理页不应再残留任何 isvc-* 节点');
        // 数据管理页仍必须保留数据库卡片（迁移模式是 provision 的取值来源）
        $this->assertStringContainsString('data-i18n="sys.panel_database"', $sysTab);
        $this->assertStringContainsString('data-i18n="sys.migrate_mode"', $sysTab);
    }

    public function testAdminJsLoadsThePanelOnTheApiTokensTab(): void
    {
        $adminJs = $this->read(self::ROOT . '/public/assets/admin.js');

        $this->assertMatchesRegularExpression(
            "/if \(name === 'api-tokens'\) \{[^\n]*loadIntegratedServices\(\);[^\n]*\}/",
            $adminJs,
            '进入 API 管理 tab 必须一并加载集成服务状态，否则面板长期停在「加载中…」'
        );
    }

    public function testSystemInfoNoLongerLoadsThePanel(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        $this->assertStringContainsString(
            'export async function loadIntegratedServices()',
            $systemJs,
            '面板渲染仍由 system.js 提供（admin.js 仅负责触发时机）'
        );
        // 进入「数据管理」页不得再拉集成服务状态（enable/disable 成功后的自我刷新是合法的，见
        // enableIntegratedService / disableIntegratedService），故只检查 loadSystemInfo 的函数体。
        $sysInfo = $this->functionBody($systemJs, 'export async function loadSystemInfo(');
        $this->assertStringNotContainsString(
            'loadIntegratedServices',
            $sysInfo,
            'loadSystemInfo 不应再加载集成服务状态 —— 面板已不属于数据管理页'
        );
    }

    public function testInlineOnclickHandlersAreExposedOnWindow(): void
    {
        $adminJs = $this->read(self::ROOT . '/public/assets/admin.js');
        $this->assertMatchesRegularExpression(
            '/Object\.assign\(window,\s*\{([\s\S]*?)\n\}\);/',
            $adminJs,
            '未找到 window 全局挂载块——inline onclick 全部依赖它'
        );
        preg_match('/Object\.assign\(window,\s*\{([\s\S]*?)\n\}\);/', $adminJs, $m);
        $globals = $m[1];

        foreach ([
            'loadIntegratedServices',
            'enableIntegratedService',
            'disableIntegratedService',
            'onIntegratedServiceToggle',
            'copyIntegratedToken',
            'hideIntegratedTokenBox',
        ] as $fn) {
            $this->assertStringContainsString($fn, $globals, $fn . ' 必须挂到 window，否则按钮 onclick 会静默失效');
        }
    }

    public function testEveryI18nKeyUsedByTheTabExistsInBothLocales(): void
    {
        $apiTab = $this->tabSection('api-tokens', 'operation-logs');
        preg_match_all('/data-i18n(?:-placeholder)?="([^"]+)"/', $apiTab, $m);
        $keys = array_values(array_unique($m[1]));
        $this->assertNotEmpty($keys);

        $i18n = new I18nService(self::ROOT . '/lang', 'zh_CN');
        $zh = $i18n->getAll('zh_CN');
        $en = $i18n->getAll('en');

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $zh, 'zh_CN 缺少翻译 ' . $key);
            $this->assertArrayHasKey($key, $en, 'en 缺少翻译 ' . $key);
            $this->assertNotSame('', trim((string) $zh[$key]), 'zh_CN 翻译为空: ' . $key);
            $this->assertNotSame('', trim((string) $en[$key]), 'en 翻译为空: ' . $key);
        }
    }

    public function testIntroExplainsThatEnablingIssuesAToken(): void
    {
        $i18n = new I18nService(self::ROOT . '/lang', 'zh_CN');

        $zh = (string) $i18n->getAll('zh_CN')['sys.isvc_intro'];
        $en = (string) $i18n->getAll('en')['sys.isvc_intro'];

        // 面板现在与 Token 列表同屏，「启用会签发一枚 Token」必须写在脸上
        $this->assertStringContainsString('Token', $zh);
        $this->assertStringContainsString('token', strtolower($en));
    }

    /**
     * 启停入口必须是「启用」勾选框（而非两个按钮）：勾选框是即时控件，点一下状态就变了，
     * 因此必须依赖 onIntegratedServiceToggle 的「确认弹窗 + 取消回退」语义；退回成裸按钮，
     * 这套回退逻辑就没有任何入口触发，勾选框会与后端状态静默不一致。
     */
    public function testEnableToggleIsACheckboxNotAButton(): void
    {
        $html   = $this->read(self::ROOT . '/templates/admin.html');
        $apiTab = $this->tabSection('api-tokens', 'operation-logs');

        $this->assertStringContainsString(
            '<input type="checkbox" id="isvc-cd-toggle" onchange="onIntegratedServiceToggle(\'cd\')">',
            $apiTab,
            '启停入口应为「启用」勾选框，并绑到 onIntegratedServiceToggle'
        );
        $this->assertStringContainsString('for="isvc-cd-toggle"', $apiTab, 'label 必须 for 到勾选框，否则点文字无效');

        // 旧的两个按钮必须彻底消失，否则同一状态会有两个入口、行为不一致
        $this->assertStringNotContainsString('isvc-cd-enable', $html);
        $this->assertStringNotContainsString('isvc-cd-disable', $html);

        // 勾选框外观必须与「平台管理」各功能开关同源（同一 CSS 规则），避免退化成裸 checkbox
        $this->assertStringContainsString(
            '#isvc-cd-toggle',
            $this->read(self::ROOT . '/public/assets/admin.css'),
            'admin.css 未给 #isvc-cd-toggle 加尺寸 / 主题色'
        );
    }

    /**
     * 勾选态的可信性与回退不变量：
     *   1. 勾选态只由后端状态决定（渲染时写入），不由本地点击结果决定 —— 且取的是「可用」
     *      （enabled 且凭据有效）而非裸 enabled：凭据在下方列表里被撤销/删除后，勾选框必须回到
     *      未勾选，否则故障态既点不动（已勾选）也无法自助补发；
     *   2. 请求期间禁用勾选框（防连点双发）；
     *   3. 用户取消确认 / 请求失败必须把勾选框退回 —— 这依赖 enable/disable 返回布尔值。
     */
    public function testToggleStateFollowsServerAndRevertsOnCancelOrFailure(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        $render = $this->functionBody($systemJs, 'function renderCdService(d)');
        $this->assertStringContainsString(
            'const usable = enabled && tokenActive;',
            $render,
            '「可用」必须同时看契约行 enabled 与凭据活性'
        );
        $this->assertStringContainsString('toggle.checked = usable;', $render, '勾选态必须按「可用」渲染');
        $this->assertStringContainsString('toggle.disabled = !isSuper;', $render, '非 super_admin 必须禁用勾选框');

        $toggle = $this->functionBody($systemJs, 'export async function onIntegratedServiceToggle(service)');
        $this->assertStringContainsString('toggle.disabled = true;', $toggle, '请求期间必须禁用，防连点');
        $this->assertStringContainsString('if (!ok) toggle.checked = !want;', $toggle, '取消 / 失败必须把勾选框退回');

        foreach ([
            'export async function enableIntegratedService(service)',
            'export async function disableIntegratedService(service)',
        ] as $signature) {
            $body = $this->functionBody($systemJs, $signature);
            $this->assertStringContainsString('return true;', $body, $signature . '：成功必须 return true');
            $this->assertStringContainsString(
                '})) return false;',
                $body,
                $signature . '：取消确认必须 return false，否则勾选框会停在错误状态'
            );
            $this->assertStringNotContainsString('})) return;', $body, $signature . '：取消确认不得静默 return');
        }
    }

    /**
     * 取某个 tab 的 HTML 片段（从 `<div id="tab-X"` 到下一个 tab 起始标签）。
     *
     * @param string      $tab      目标 tab 名
     * @param string|null $untilTab 结束边界 tab 名；null 表示取到文件末尾
     */
    private function tabSection(string $tab, ?string $untilTab): string
    {
        $html  = $this->read(self::ROOT . '/templates/admin.html');
        $start = strpos($html, '<div id="tab-' . $tab . '"');
        $this->assertNotFalse($start, 'admin.html 缺少 #tab-' . $tab);

        $end = $untilTab !== null
            ? strpos($html, '<div id="tab-' . $untilTab . '"', $start)
            : false;
        if ($end === false) {
            $end = strlen($html);
        }

        return substr($html, $start, $end - $start);
    }

    /**
     * 取某个导出函数的函数体（从签名起，到下一个 `export ` 边界止）。
     */
    private function functionBody(string $js, string $signature): string
    {
        $start = strpos($js, $signature);
        $this->assertNotFalse($start, '未找到函数 ' . $signature);
        $end = strpos($js, "\nexport ", $start + 1);

        return substr($js, $start, ($end === false ? strlen($js) : $end) - $start);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content, '无法读取 ' . $path);

        return $content;
    }
}
