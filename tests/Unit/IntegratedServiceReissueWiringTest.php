<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * 集成服务的配套凭据（Token 列表里的 `cd-service`）被单独处理过之后，服务必须仍能自助恢复，
 * 且恢复动作要分对两种语义：
 *
 *   - 禁用（冻结）：契约行未启用、凭据只是被停用（revoke 只改 enabled、摘要未动），
 *     再次启用即恢复同一枚凭据，原明文继续有效，CD 的 CI_API_TOKEN 无需更换；
 *   - 作废（Token 列表撤销 / 删除 / 已过期）：凭据不可用也不该复活，
 *     再次启用补发新凭据（CD 必须换明文）。
 *
 * 两种语义走错不会让任何单测变红，只会在浏览器或生产上卡死或悄悄换掉凭据：前者表现为
 * 每次禁用再启用都重新生成一条 API、CD 要跟着改一次配置，后者表现为接口永久 409 且页面勾选框
 * 始终是勾选态。故在此守住四层：控制器（409 边界 + 恢复/补发两条分支）、服务
 * （restore 只改 enabled，不碰摘要）、前端（勾选态取可用、冻结/作废两套文案、
 * 恢复后不弹明文框）、双语翻译。
 */
class IntegratedServiceReissueWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 恢复 / 补发路径新增的双语翻译键：缺一个页面就会显示 `sys.isvc_xxx` 原文键。 */
    private const I18N_KEYS = [
        'sys.isvc_token_dead',
        'sys.isvc_reissue_hint',
        'sys.isvc_reissue_note',
        'sys.isvc_token_frozen',
        'sys.isvc_reuse_note',
        'sys.isvc_disable_confirm',
        'sys.isvc_disable_step1',
        'sys.isvc_disable_step2',
        'sys.isvc_restored',
    ];

    public function testEnableOnlyRejectsAnAlreadyEnabledServiceWithAHealthyCredential(): void
    {
        $enable = $this->enableBody();

        $this->assertStringContainsString(
            "if (\$state['enabled'] && \$state['token_active']) {",
            $enable,
            '409 必须收紧为「已启用且凭据有效」——否则凭据被撤销后启用接口永久 409，服务无法自助恢复'
        );
        $this->assertStringContainsString("'integrated.already_enabled'", $enable, '重复启用的报错键不能丢');
        $this->assertStringNotContainsString(
            "if (\$state['enabled']) {\n            return \$this->jsonError(\$response, 'integrated.already_enabled', 409);",
            $enable,
            '旧的「只判 enabled」守卫必须已被替换'
        );
    }

    public function testReissueRevokesTheDeadCredentialInsideTheSameTransaction(): void
    {
        $enable = $this->enableBody();
        $repo   = $this->read(self::ROOT . '/src/Service/IntegratedServiceRepository.php');

        $this->assertStringContainsString(
            "\$previousTokenId = \$state['enabled'] ? \$state['token_id'] : null;",
            $enable,
            '补发对象必须只取「行上确实启用着」的旧凭据（禁用过的行留着旧 id，不应被当成补发）'
        );

        
        
        $begin  = strpos($enable, '$this->tx->begin()');
        $revoke = strpos($enable, '$this->apiTokens->revoke($previousTokenId);');
        $commit = strpos($enable, '$this->tx->commit()');
        $this->assertIsInt($begin);
        $this->assertIsInt($revoke);
        $this->assertIsInt($commit);
        $this->assertTrue($begin < $revoke && $revoke < $commit, '旧凭据作废必须与签发 / 绑定同事务');

        $this->assertStringContainsString(
            "'reissued'",
            $enable,
            '响应必须带 reissued，调用方才能分辨「首次启用」与「补发」'
        );
        
        $this->assertStringContainsString(
            'public function enable(string $service, string $provision, int $tokenId, string $operator): void',
            $repo
        );
    }

    /**
     * 禁用（冻结）后再启用应恢复同一枚凭据，不另签一枚。
     */
    public function testFrozenCredentialIsRestoredInsteadOfReissued(): void
    {
        $enable       = $this->enableBody();
        $tokenService = $this->read(self::ROOT . '/src/Service/ApiTokenService.php');

        
        $this->assertStringContainsString("\$reuseTokenId = !\$state['enabled']", $enable);
        $this->assertStringContainsString("\$state['token_exists']", $enable, '必须确认凭据行还在（被删除的恢复不了）');
        $this->assertStringContainsString("!\$state['token_enabled']", $enable, '只恢复「被停用」的凭据');
        $this->assertStringContainsString("!\$state['token_expired']", $enable, '恢复一枚过期凭据毫无意义，必须排除');

        
        $begin   = strpos($enable, '$this->tx->begin()');
        $restore = strpos($enable, '$this->apiTokens->restore($reuseTokenId);');
        $commit  = strpos($enable, '$this->tx->commit()');
        $this->assertIsInt($begin);
        $this->assertIsInt($restore);
        $this->assertIsInt($commit);
        $this->assertTrue($begin < $restore && $restore < $commit, '恢复凭据必须与契约行回写同事务');

        
        $branchStart = strpos($enable, 'if ($reuseTokenId !== null) {');
        $branchEnd   = strpos($enable, '} else {', (int) $branchStart);
        $this->assertIsInt($branchStart);
        $this->assertIsInt($branchEnd);
        $branch = substr($enable, (int) $branchStart, (int) $branchEnd - (int) $branchStart);
        $this->assertStringContainsString('restore($reuseTokenId)', $branch, '恢复分支必须点亮原凭据');
        $this->assertStringContainsString(
            '$this->services->enable($service, $provision, $reuseTokenId',
            $branch,
            '恢复要把契约行绑回同一枚凭据（同一 id 覆盖写，不产生第二枚）'
        );
        $this->assertStringNotContainsString('createToken', $branch, '恢复分支必须复用原凭据，不得另签一枚');

        
        $this->assertStringContainsString("'reused'", $enable);

        
        $this->assertStringContainsString(
            "'UPDATE ' . AppConfig::TABLE_API_TOKENS . ' SET enabled = 1 WHERE id = ?'",
            $tokenService,
            'restore 只能改 enabled —— 顺手改写摘要会让「恢复同一枚」彻底失去意义'
        );
    }

    public function testToggleStateUsesAvailabilityAndExplainsBothPaths(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        $this->assertStringContainsString(
            'const usable = enabled && tokenActive;',
            $systemJs,
            '勾选态必须取「可用」（enabled && 凭据有效），否则凭据作废后勾选框停在勾选位、点不动也补发不了'
        );
        $this->assertStringContainsString('toggle.checked = usable;', $systemJs, '勾选态必须按「可用」渲染');
        $this->assertStringContainsString("__.t('sys.isvc_token_dead')", $systemJs, '故障态必须有醒目的状态文案');
        $this->assertStringContainsString("__.t('sys.isvc_reissue_hint')", $systemJs, '故障态必须告诉用户「再勾一次即补发」');
        $this->assertStringContainsString('_isvcState = d;', $systemJs, '状态响应需缓存，确认弹窗才能分辨三条路径');
        $this->assertStringContainsString(
            'const reissue = st.enabled === true && st.token_active !== true;',
            $systemJs,
            '作废判断必须与后端的故障态定义一致（契约行已启用 + 凭据不可用）'
        );
        $this->assertStringContainsString(
            "__.t('sys.isvc_reissue_note')",
            $systemJs,
            '补发确认弹窗必须说明：本次只补发凭据，旧凭据不会复活，CD 必须换明文'
        );
        
        $this->assertStringContainsString("]).join('\\n');", $systemJs);
    }

    /**
     * 面板与确认弹窗都把冻结（可恢复）与作废（需补发）分开说，否则用户会以为
     * 每次重新启用都会换 Token，或以为被撤销的凭据也能原地恢复。
     */
    public function testPanelAndDialogDistinguishRestoreFromReissue(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        $this->assertStringContainsString(
            'const tokenFrozen = d.token_exists === true && d.token_enabled !== true && d.token_expired !== true;',
            $systemJs,
            '面板必须能识别「凭据只是随服务冻结」'
        );
        $this->assertStringContainsString(
            "esc(__.t('sys.isvc_token_frozen', { token: frozenToken }))",
            $systemJs,
            '冻结态必须写明「重新启用即恢复同一枚，CD 无需换配置」'
        );
        $this->assertStringContainsString(
            'const reuse = st.enabled !== true && st.token_exists === true && st.token_enabled !== true && st.token_expired !== true;',
            $systemJs,
            '确认弹窗的路径判断必须与后端复用条件一字不差地对齐'
        );
        $this->assertStringContainsString(
            "['♻ ' + __.t('sys.isvc_reuse_note')]",
            $systemJs,
            '恢复路径必须在弹窗里说明「复用原凭据、明文不再展示、CD 无需改配置」'
        );

        
        $start = strpos($systemJs, 'if (res.ok && d.reused) {');
        $end   = strpos($systemJs, 'return true;', (int) $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $branch = substr($systemJs, (int) $start, (int) $end - (int) $start);
        $this->assertStringContainsString("toast(__.t('sys.isvc_restored'), true);", $branch);
        $this->assertStringContainsString('hideIntegratedTokenBox();', $branch, '恢复不展示明文，顺手收起上一次的明文框');
        $this->assertStringContainsString('loadIntegratedServices();', $branch);
        $this->assertStringContainsString('loadApiTokens();', $branch, '恢复后同屏列表里那枚 cd-service 要回到「有效」');
    }

    public function testTokenListRefreshesThePanelAfterRevokeOrDelete(): void
    {
        $apiTokens = $this->read(self::ROOT . '/public/assets/modules/apiTokens.js');

        $this->assertStringContainsString('function refreshIntegratedPanel()', $apiTokens);
        $this->assertStringContainsString(
            "if (typeof window.loadIntegratedServices === 'function') window.loadIntegratedServices();",
            $apiTokens,
            '经 window 调用刷新，避免 system.js ↔ apiTokens.js 循环 import'
        );
        $this->assertSame(
            2,
            substr_count($apiTokens, 'loadApiTokens(); refreshIntegratedPanel();'),
            '撤销与删除两条成功分支都必须刷新上方「集成服务」卡片，否则卡片会继续显示「凭据有效」'
        );
    }

    public function testReissueTranslationsExistInBothLocales(): void
    {
        $i18n = new I18nService(self::ROOT . '/lang', 'zh_CN');
        $zh = $i18n->getAll('zh_CN');
        $en = $i18n->getAll('en');

        foreach (self::I18N_KEYS as $key) {
            $this->assertArrayHasKey($key, $zh, 'zh_CN 缺少翻译 ' . $key);
            $this->assertArrayHasKey($key, $en, 'en 缺少翻译 ' . $key);
            $this->assertNotSame('', trim((string) $zh[$key]), 'zh_CN 翻译为空: ' . $key);
            $this->assertNotSame('', trim((string) $en[$key]), 'en 翻译为空: ' . $key);
        }


    }

    /**
     * 取控制器 enable() 的函数体（到 disable() 为止），把断言限定在启用逻辑里。
     */
    /**
     * 两个确认弹窗的排版约束：正文只留一句问句，要点固定走 note 的多行文本，且不复述原因。
     * 弹窗越像说明书，用户越会直接点确定，本次是恢复还是补发、要不要改 CD 配置这类结论反而被淹没。
     */
    public function testDialogsKeepTerseQuestionAndNumberedSteps(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        
        $disable = $this->jsBody($systemJs, 'export async function disableIntegratedService(service)');
        $this->assertStringContainsString('note: note,', $disable, '禁用确认要把要点作为 note 传入');
        $this->assertStringContainsString("'① ' + __.t('sys.isvc_disable_step1')", $disable);
        $this->assertStringContainsString("'② ' + __.t('sys.isvc_disable_step2')", $disable);
        $this->assertStringContainsString(".join('\\n');", $disable, '多行要点的断行符不能丢');
        $this->assertStringContainsString(
            "message: __.t('sys.isvc_disable_confirm', { name: __.t('sys.isvc_cd_name') }),",
            $disable,
            '禁用弹窗正文只留一句问句'
        );

        
        $enable = $this->jsBody($systemJs, 'export async function enableIntegratedService(service)');
        $this->assertStringContainsString("'① ' + __.t('sys.isvc_enable_step1')", $enable);
        $this->assertStringContainsString("'② ' + __.t('sys.isvc_enable_step2')", $enable);
        $this->assertStringNotContainsString('sys.isvc_enable_step3', $systemJs, '启用弹窗只保留两条要点');
        $this->assertStringContainsString('const head = reuse', $enable, '恢复 / 补发只是二选一的首行');
    }

    /**
     * 取某个导出函数的函数体（从签名起，到下一个 `export ` 边界止）。
     */
    private function jsBody(string $js, string $signature): string
    {
        $start = strpos($js, $signature);
        $this->assertNotFalse($start, '未找到函数 ' . $signature);
        $end = strpos($js, "\nexport ", $start + 1);

        return substr($js, $start, ($end === false ? strlen($js) : $end) - $start);
    }

    private function enableBody(): string
    {
        $controller = $this->read(self::ROOT . '/src/Controller/IntegratedServiceController.php');
        $start = strpos($controller, 'public function enable(');
        $this->assertNotFalse($start, '未找到 IntegratedServiceController::enable()');
        $end = strpos($controller, 'public function disable(', $start);
        $this->assertNotFalse($end, '未找到 IntegratedServiceController::disable()');

        return substr($controller, $start, $end - $start);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content, '无法读取 ' . $path);

        return $content;
    }
}
