<?php
/**
 * LDAP 登录流程冒烟测试 —— 用 mock LDAP 服务器端到端验证 LdapService::authenticate()。
 *
 * 前置：
 *   1) 先启动 mock 服务器：cd tests/ldap-mock && npm install && node server.js 1389
 *   2) 再跑本脚本：php tests/ldap_login_smoke.php
 *
 * 覆盖「管理员搜索模式」完整链路：connect → admin bind → search(uid) → user bind。
 * 不需要真实 OpenLDAP，也不需要额外 mock 库 —— 唯一依赖是已安装的 ext-ldap。
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Service\LdapService;

const LDAP_HOST = '127.0.0.1';
const LDAP_PORT = 1389;

$config = new AppConfig([
    'ldap' => [
        'enabled'       => true,
        'host'          => LDAP_HOST,
        'port'          => LDAP_PORT,
        'base_dn'       => 'dc=example,dc=com',
        'bind_dn'       => 'cn=admin,dc=example,dc=com',
        'bind_password' => 'adminpass',
        'user_filter'   => '(uid=%s)',
        'attrs'         => ['uid', 'cn', 'mail', 'dn'],
    ],
]);

$service = new LdapService($config);

$pass = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32m✔\033[0m {$name}\n";
    } else {
        $fail++;
        echo "  \033[31m✘\033[0m {$name}" . ($detail !== '' ? "  → {$detail}" : '') . "\n";
    }
}

echo "LDAP login smoke test (ext-ldap 已加载: " . (extension_loaded('ldap') ? 'yes' : 'no') . ")\n";
echo "目标: ldap://" . LDAP_HOST . ':' . LDAP_PORT . "\n\n";

if (!extension_loaded('ldap')) {
    echo "\033[31m未安装 ext-ldap，无法运行此冒烟测试。\033[0m\n";
    exit(2);
}

// 1) 正确密码 → 登录成功，且返回 DN + 属性
$r = $service->authenticate('alice', 'alicepass');
check('alice 正确密码登录成功', ($r['ok'] ?? false) === true, json_encode($r));
if ($r['ok'] ?? false) {
    check(
        '返回的 DN 正确',
        strtolower((string)($r['dn'] ?? '')) === 'cn=alice,ou=users,dc=example,dc=com',
        $r['dn'] ?? '(none)'
    );
    check(
        '回读 mail 属性',
        ($r['attrs']['mail'] ?? '') === 'alice@example.com',
        var_export($r['attrs'] ?? null, true)
    );
}

// 2) 错误密码 → ldap_bind_failed（不是 user_not_found，说明确实搜到了用户再校验密码）
$r = $service->authenticate('alice', 'wrongpass');
check('alice 错误密码 → ldap_bind_failed', ($r['error'] ?? '') === 'ldap_bind_failed', json_encode($r));

// 3) 不存在的用户 → ldap_user_not_found
$r = $service->authenticate('ghost', 'whatever');
check('ghost 不存在 → ldap_user_not_found', ($r['error'] ?? '') === 'ldap_user_not_found', json_encode($r));

// 4) 空凭据 → empty_credentials
$r = $service->authenticate('', '');
check('空凭据 → empty_credentials', ($r['error'] ?? '') === 'empty_credentials', json_encode($r));

// 5) 第二个用户 bob 也能登录（验证搜索过滤不是写死 alice）
$r = $service->authenticate('bob', 'bobpass');
check('bob 正确密码登录成功', ($r['ok'] ?? false) === true, json_encode($r));

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
