<?php
// smoke_api_tokens.php - API token 生命周期冒烟测试
// 用法: php tests/smoke_api_tokens.php [base_url] [admin_user] [admin_password]
$base      = $argv[1] ?? 'http://localhost:80';
$adminUser = $argv[2] ?? ($_ENV['ADMIN_USER'] ?? 'admin');
$adminPass = $argv[3] ?? ($_ENV['ADMIN_PASSWORD'] ?? '');

function api($method, $url, $body = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POST, true);
    elseif ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        $json = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $info['http_code'], 'body' => $res, 'error' => $err];
}

echo "Login as {$adminUser}...\n";
$login = api('POST', $base . '/api/admin/login', ['user' => $adminUser, 'password' => $adminPass]);
if ($login['code'] < 200 || $login['code'] >= 300) {
    echo "Login failed: ", $login['code'], " ", $login['body'], "\n";
    exit(1);
}
$ldata      = json_decode($login['body'], true);
$adminToken = $ldata['token'] ?? null;
if (!$adminToken) { echo "No admin token in login response\n"; exit(1); }
echo "Admin token length: " . strlen($adminToken) . "\n";

// 创建 API token（授予 main 只读 scope）
echo "Creating API token (scope: main)...\n";
$create = api('POST', $base . '/api/admin/api_tokens',
    ['name' => 'smoke-test', 'scopes' => ['main'], 'note' => 'smoke'],
    ['Authorization: Bearer ' . $adminToken]);
if ($create['code'] < 200 || $create['code'] >= 300) {
    echo "Create failed: ", $create['code'], " ", $create['body'], "\n";
    exit(1);
}
$cd           = json_decode($create['body'], true);
$createdId    = $cd['id'] ?? null;
$createdToken = $cd['token'] ?? null;
if (!$createdId || !$createdToken) { echo "Create response missing id/token: ", $create['body'], "\n"; exit(1); }
echo "Created token id={$createdId} token_len=" . strlen($createdToken) . "\n";

// 列出 token，确认不含明文
echo "Listing tokens...\n";
$list = api('GET', $base . '/api/admin/api_tokens', null, ['Authorization: Bearer ' . $adminToken]);
if ($list['code'] < 200 || $list['code'] >= 300) { echo "List failed: ", $list['code'], " ", $list['body'], "\n"; exit(1); }
$larr = json_decode($list['body'], true);
$tokens = $larr['tokens'] ?? $larr ?? [];
echo "List count: " . count($tokens) . "\n";
foreach ($tokens as $it) {
    if (array_key_exists('token', $it)) { echo "ERROR: plaintext token present in list!\n"; exit(1); }
}

// 用 token 访问被授予 scope 的接口（/api/main/map/list 需要 main）
echo "Calling /api/main/map/list with created token (should succeed)...\n";
$ok = api('GET', $base . '/api/main/map/list', null, ['Authorization: Bearer ' . $createdToken]);
if ($ok['code'] < 200 || $ok['code'] >= 300) {
    echo "ERROR: token with main scope should access /api/main/map/list, got ", $ok['code'], " ", $ok['body'], "\n";
    exit(1);
}
echo "OK: ", $ok['code'], "\n";

// 用 token 访问未授予 scope 的接口（/api/build/static/trigger 需要 build.write）→ 应 403
echo "Calling /api/build/static/trigger with created token (should be 403)...\n";
$deny = api('POST', $base . '/api/build/static/trigger', [], ['Authorization: Bearer ' . $createdToken]);
if ($deny['code'] !== 403) {
    echo "ERROR: expected 403 for unauthorized scope, got ", $deny['code'], " ", $deny['body'], "\n";
    exit(1);
}
echo "Forbidden as expected: ", $deny['code'], "\n";

// 撤销 token（软禁用，保留记录）
echo "Revoking token id={$createdId}...\n";
$rev = api('POST', $base . '/api/admin/api_tokens/' . $createdId . '/revoke', null, ['Authorization: Bearer ' . $adminToken]);
if ($rev['code'] < 200 || $rev['code'] >= 300) { echo "Revoke failed: ", $rev['code'], " ", $rev['body'], "\n"; exit(1); }

// 撤销后 token 应失效（401）
echo "Calling with revoked token (should fail 401)...\n";
$me2 = api('GET', $base . '/api/main/map/list', null, ['Authorization: Bearer ' . $createdToken]);
if ($me2['code'] >= 200 && $me2['code'] < 300) {
    echo "ERROR: revoked token still works: ", $me2['body'], "\n";
    exit(1);
}
echo "Revoked token rejected as expected: ", $me2['code'], "\n";

// 撤销后记录应仍在列表中（软禁用，enabled=false）
echo "Verifying revoked token still listed (disabled)...\n";
$list2 = api('GET', $base . '/api/admin/api_tokens', null, ['Authorization: Bearer ' . $adminToken]);
$larr2 = json_decode($list2['body'], true);
$foundRevoked = false;
foreach (($larr2['tokens'] ?? []) as $it) {
    if ((int)($it['id'] ?? 0) === (int)$createdId) {
        $foundRevoked = true;
        if (($it['enabled'] ?? true) !== false) { echo "ERROR: revoked token should be disabled in list\n"; exit(1); }
    }
}
if (!$foundRevoked) { echo "ERROR: revoked token should still appear in list\n"; exit(1); }
echo "Revoked token still listed and disabled as expected\n";

// 删除 token（硬删除，记录移除）
echo "Deleting token id={$createdId}...\n";
$del = api('DELETE', $base . '/api/admin/api_tokens/' . $createdId, null, ['Authorization: Bearer ' . $adminToken]);
if ($del['code'] < 200 || $del['code'] >= 300) { echo "Delete failed: ", $del['code'], " ", $del['body'], "\n"; exit(1); }

// 删除后记录应从列表消失
echo "Verifying deleted token gone from list...\n";
$list3 = api('GET', $base . '/api/admin/api_tokens', null, ['Authorization: Bearer ' . $adminToken]);
$larr3 = json_decode($list3['body'], true);
foreach (($larr3['tokens'] ?? []) as $it) {
    if ((int)($it['id'] ?? 0) === (int)$createdId) { echo "ERROR: deleted token still in list\n"; exit(1); }
}
echo "Deleted token removed from list as expected\n";

echo "Smoke API tokens test completed OK\n";
exit(0);
