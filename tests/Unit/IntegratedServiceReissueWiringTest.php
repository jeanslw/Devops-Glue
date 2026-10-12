<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * 守卫不变量（v2.9.0）：集成服务的配套凭据（Token 列表里的 `cd-service`）被单独处理过之后，
 * 服务必须仍能自助恢复，且恢复动作要**分对两种语义**：
 *
 *   - 禁用（冻结）：契约行未启用、凭据只是被停用（revoke 只改 enabled、摘要一字未动）→
 *     再次启用即**恢复同一枚**凭据，原明文继续有效，CD 的 CI_API_TOKEN 无需更换；
 *   - 作废（Token 列表 ⛔ 撤销 / 🗑 删除 / 已过期）：凭据不可用也不该复活 →
 *     再次启用**补发**新凭据（CD 必须换明文）。
 *
 * 二者的失败都不会让任何单测变红，只会在浏览器 / 生产上静默卡死或悄悄换掉凭据 —— 前者是
 * 「每次禁用再启用都重新生成一条 API，CD 要跟着改一次配置」，后者是旧的
 * `if ($state['enabled']) return 409`（接口侧永久 409 + 页面侧勾选框按裸 enabled 渲染成勾选态，
 * 点不动也变不了）。故在此显式守住四层：控制器（409 边界 + 恢复/补发两条分支）→ 服务
 * （restore 只改 enabled，绝不碰摘要）→ 前端（勾选态取「可用」、冻结/作废两套文案、
 * 恢复后不弹明文框）→ 双语翻译。
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

        // 顺序即语义：旧凭据作废必须落在 beginTransaction 与 commit 之间（同事务），
        // 否则会出现「新凭据已发但旧凭据还活着」或「旧凭据死了新凭据回滚」的中间态。
        $begin  = strpos($enable, 'beginTransaction()');
        $revoke = strpos($enable, '$this->apiTokens->revoke($previousTokenId);');
        $commit = strpos($enable, '$this->pdo->commit();');
        $this->assertIsInt($begin);
        $this->assertIsInt($revoke);
        $this->assertIsInt($commit);
        $this->assertTrue($begin < $revoke && $revoke < $commit, '旧凭据作废必须与签发 / 绑定同事务');

        $this->assertStringContainsString(
            "'reissued'",
            $enable,
            '响应必须带 reissued，调用方才能分辨「首次启用」与「补发」'
        );
        // 补发靠的是仓储的 upsert 语义（行已存在 → UPDATE 重绑新凭据），不需要新方法
        $this->assertStringContainsString(
            'public function enable(string $service, string $provision, int $tokenId, string $operator): void',
            $repo
        );
    }

    /**
     * 禁用（冻结）后再启用 = **恢复同一枚凭据**，绝不另签一枚（运营的硬要求）。
     */
    public function testFrozenCredentialIsRestoredInsteadOfReissued(): void
    {
        $enable       = $this->enableBody();
        $tokenService = $this->read(self::ROOT . '/src/Service/ApiTokenService.php');

        // 复用判定必须与状态的三态定义一致：行未启用 + 凭据还在 + 只是被停用 + 未过期
        $this->assertStringContainsString("\$reuseTokenId = !\$state['enabled']", $enable);
        $this->assertStringContainsString("\$state['token_exists']", $enable, '必须确认凭据行还在（被删除的恢复不了）');
        $this->assertStringContainsString("!\$state['token_enabled']", $enable, '只恢复「被停用」的凭据');
        $this->assertStringContainsString("!\$state['token_expired']", $enable, '恢复一枚过期凭据毫无意义，必须排除');

        // 恢复分支：restore + 回写契约行同事务（与补发分支同款约束）
        $begin   = strpos($enable, 'beginTransaction()');
        $restore = strpos($enable, '$this->apiTokens->restore($reuseTokenId);');
        $commit  = strpos($enable, '$this->pdo->commit();');
        $this->assertIsInt($begin);
        $this->assertIsInt($restore);
        $this->assertIsInt($commit);
        $this->assertTrue($begin < $restore && $restore < $commit, '恢复凭据必须与契约行回写同事务');

        // 恢复分支里绝不能签发新凭据 —— 那正是运营抱怨的「重新生成一条 API，CD 要改一次配置」
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

        // 响应要让前端能分辨：reused = 复用（无明文），reissued = 补发（有明文）
        $this->assertStringContainsString("'reused'", $enable);

        // restore 与 revoke 对称：只改 enabled 位，绝不能碰 token_hash（动了原明文就永久废了）
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
        // 文案走纯文本 + \n：confirmDialog 用 textContent 渲染，塞 HTML 会把标签原样显示出来
        $this->assertStringContainsString("]).join('\\n');", $systemJs);
    }

    /**
     * 面板与确认弹窗都必须把「冻结（可恢复）」与「作废（需补发）」分开说，否则运营要么以为
     * 每次重新启用都会换 Token（正是本次要解决的问题），要么以为被撤销的凭据也能原地复活。
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

        // 恢复的响应没有明文，绝不能走进「弹出 Token 框」那条分支，也不该谎称「已签发」
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

        // 故障态提示不能再引导用户「先禁用再启用」（旧流程），必须指向「再勾一次即补发」
        $this->assertStringContainsString('勾选', (string) $zh['sys.isvc_reissue_hint']);
        $this->assertStringContainsString('Tick Enable', (string) $en['sys.isvc_reissue_hint']);
        // 冻结态提示必须点明「恢复同一枚 / 无需更换」，否则「禁用再启用」看起来还是会在换 Token
        $this->assertStringContainsString('同一枚', (string) $zh['sys.isvc_token_frozen']);
        $this->assertStringContainsString('same token', (string) $en['sys.isvc_token_frozen']);
        // 弹窗正文只讲要点与结果：恢复首行＝同一枚（「CD 无需换配置」交给冻结态卡片与补发首行承担）
        $this->assertStringContainsString('同一枚', (string) $zh['sys.isvc_reuse_note']);
        $this->assertStringContainsString('CI_API_TOKEN', (string) $zh['sys.isvc_token_frozen']);
        $this->assertStringContainsString('CI_API_TOKEN', (string) $zh['sys.isvc_reissue_note']);
        $this->assertStringContainsString('same token', (string) $en['sys.isvc_reuse_note']);
        $this->assertStringContainsString('CI_API_TOKEN', (string) $en['sys.isvc_token_frozen']);
        $this->assertStringContainsString('CI_API_TOKEN', (string) $en['sys.isvc_reissue_note']);
        // 恢复成功的 toast 必须点明「同一枚继续有效」，而不是「已签发」
        $this->assertStringContainsString('同一枚', (string) $zh['sys.isvc_restored']);
        $this->assertStringContainsString('same token', (string) $en['sys.isvc_restored']);

        // 禁用弹窗只留一句问句：要点与解释都不许再塞回正文（否则又是一屏三行）
        $this->assertStringNotContainsString('；', (string) $zh['sys.isvc_disable_confirm']);
        $this->assertStringNotContainsString('数据表', (string) $zh['sys.isvc_disable_confirm']);
        $this->assertStringNotContainsString(';', (string) $en['sys.isvc_disable_confirm']);
        $this->assertStringNotContainsString('tables', (string) $en['sys.isvc_disable_confirm']);
        // 两条要点必须点到「停用（仅冻结）」与「数据保留、重新启用即恢复」
        $this->assertStringContainsString('冻结', (string) $zh['sys.isvc_disable_step1']);
        $this->assertStringContainsString('frozen', (string) $en['sys.isvc_disable_step1']);
        $this->assertStringContainsString('保留', (string) $zh['sys.isvc_disable_step2']);
        $this->assertStringContainsString('retained', (string) $en['sys.isvc_disable_step2']);
    }

    /**
     * 取控制器 enable() 的函数体（到 disable() 为止），把断言限定在启用逻辑里。
     */
    /**
     * 两个确认弹窗的排版不变量：正文只留一句问句，要点固定走 note 的 ①② 多行，且不复述原因。
     * 破掉的代价很实在 —— 弹窗越长越像说明书，运营只会直接点「确定」，
     * 「本次是恢复还是补发、要不要改 CD 配置」这种必须看见的结论反而被淹没。
     */
    public function testDialogsKeepTerseQuestionAndNumberedSteps(): void
    {
        $systemJs = $this->read(self::ROOT . '/public/assets/modules/system.js');

        // 禁用：要点必须作为 note 传入（此前整段解释都塞在 message 里，一屏三行）
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

        // 启用：两条固定要点（原来的第 ③ 条解释已删），♻ / ⓪ 只是可选首行
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
