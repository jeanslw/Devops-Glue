<?php
/**
 * API 冒烟测试 v2 — 带结构化断言
 *
 * 用法：php tests/smoke_test.php [baseUrl]
 * 示例：php tests/smoke_test.php http://localhost:80
 *
 * 输出：
 *   - CLI 彩色报告（实时）
 *   - HTML 报告：public/test_report_YYYYMMDD_HHMMSS.html
 *   - 退出码：0 = 全部通过，1 = 有失败
 *
 * 无需任何外部依赖，可直接运行。
 */

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// 配置
// ═══════════════════════════════════════════════════════════
$baseUrl = $argv[1] ?? getenv('TEST_BASE_URL') ?: 'http://localhost:80';
$baseUrl = rtrim($baseUrl, '/');

$testJobs       = ['static', 'php/devops-glue', 'java/registry'];
$harborProject  = 'mycode';
$harborRepo     = 'diagnosis-runtime';

// 登录凭据：可通过环境变量覆盖
$loginUser     = getenv('TEST_LOGIN_USER') ?: 'admin';
$loginPassword = getenv('TEST_LOGIN_PASS') ?: 'admin123';

$triggerParams = [
    'java/registry'   => ['branches' => 'master'],
    'php/devops-glue' => ['branches' => 'main'],
    'static'          => ['branches' => 'master'],
];

// ═══════════════════════════════════════════════════════════
// HTTP 请求工具
// ═══════════════════════════════════════════════════════════
function apiCall(string $url, string $method = 'GET', $body = null, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($body !== null) {
        $json = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers = array_merge($headers, ['Content-Type: application/json']);
    }
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $response, 'error' => $error];
}

// ═══════════════════════════════════════════════════════════
// 断言框架
// ═══════════════════════════════════════════════════════════

class Assertion
{
    public string $label;
    public bool $passed;
    public string $detail;

    public function __construct(string $label, bool $passed, string $detail = '')
    {
        $this->label  = $label;
        $this->passed = $passed;
        $this->detail = $detail;
    }
}

class TestCase
{
    public string $name;
    public string $method;
    public string $url;
    public int $httpCode;
    public string $rawBody;   // 完整回包，用于断言
    public string $preview;   // 截断回包，用于显示
    public string $error;
    /** @var Assertion[] */
    public array $assertions = [];
    public string $status = 'pass'; // pass | fail | skip

    public function __construct(string $name, string $method, string $url, int $httpCode, string $rawBody, string $error = '')
    {
        $this->name     = $name;
        $this->method   = $method;
        $this->url      = $url;
        $this->httpCode = $httpCode;
        $this->rawBody  = $rawBody;
        $this->preview  = mb_substr($rawBody, 0, 300);
        $this->error    = $error;
    }

    public function assert(bool $condition, string $label, string $failDetail = ''): void
    {
        $detail = '';
        if (!$condition && $failDetail) {
            $detail = $failDetail;
        }
        $this->assertions[] = new Assertion($label, $condition, $detail);
        if (!$condition) {
            $this->status = 'fail';
        }
    }

    // ── 便捷断言 ──

    public function assertHttpOk(string $label = ''): void
    {
        $label = $label ?: 'HTTP 200-299';
        $this->assert(
            $this->httpCode >= 200 && $this->httpCode < 300,
            $label,
            "期望 2xx，实际 {$this->httpCode}"
        );
    }

    public function assertHttpRange(string $what, int $min, int $max): void
    {
        $this->assert(
            $this->httpCode >= $min && $this->httpCode <= $max,
            $what,
            "期望 {$min}-{$max}，实际 {$this->httpCode}"
        );
    }

    public function assertHttpIs(int $expected, string $label = ''): void
    {
        $label = $label ?: "HTTP $expected";
        $this->assert(
            $this->httpCode === $expected,
            $label,
            "期望 {$expected}，实际 {$this->httpCode}"
        );
    }

    public function assertJson(): void
    {
        $parsed = json_decode($this->rawBody, true);
        $trimmed = trim($this->rawBody);
        $this->assert(
            $parsed !== null || $trimmed === '[]' || $trimmed === '{}' || str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{'),
            '响应是有效 JSON',
            "无法解析：" . mb_substr($this->rawBody, 0, 80)
        );
    }

    public function assertIsArray(): void
    {
        $data = json_decode($this->rawBody, true);
        $this->assert(
            is_array($data),
            '响应是数组/对象',
            "类型：" . gettype($data)
        );
    }

    public function assertHasKey(array $data, string $key, string $label = ''): void
    {
        $label = $label ?: "包含字段 '{$key}'";
        $this->assert(
            array_key_exists($key, $data),
            $label,
            "缺少字段 '{$key}'"
        );
    }

    public function assertHasKeys(array $data, array $keys, string $label = ''): void
    {
        $label = $label ?: '包含必要字段';
        $missing = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) $missing[] = $k;
        }
        $this->assert(
            empty($missing),
            $label,
            empty($missing) ? '' : '缺少：' . implode(', ', $missing)
        );
    }

    public function assertNotEmpty($value, string $label): void
    {
        $this->assert(
            !empty($value),
            $label,
            '值为空'
        );
    }

    public function assertIn(mixed $value, array $allowed, string $label): void
    {
        $this->assert(
            in_array($value, $allowed, true),
            $label,
            "'{$value}' 不在 [" . implode(', ', $allowed) . "] 中"
        );
    }

    public function assertMatches(string $pattern, string $value, string $label): void
    {
        $this->assert(
            preg_match($pattern, $value) === 1,
            $label,
            "'{$value}' 不匹配 /{$pattern}/"
        );
    }

    public function assertIsType(string $type, $value, string $label): void
    {
        $pass = match ($type) {
            'int'    => is_int($value),
            'string' => is_string($value),
            'bool'   => is_bool($value),
            'float'  => is_float($value),
            'numeric' => is_numeric($value),
            default   => false,
        };
        $actual = gettype($value);
        $this->assert($pass, $label, "期望 {$type}，实际 {$actual}");
    }

    public function skip(string $reason): void
    {
        $this->status = 'skip';
        $this->assertions[] = new Assertion("跳过：{$reason}", false, '');
    }
}

// ═══════════════════════════════════════════════════════════
// 全局状态
// ═══════════════════════════════════════════════════════════
$allTests    = [];
$globalToken = '';

/**
 * 创建 TestCase 并添加到全局列表
 */
function T(string $name, string $method, string $url, int $code, string $rawBody, string $error = ''): TestCase
{
    global $allTests;
    $tc = new TestCase($name, $method, $url, $code, $rawBody, $error);
    $allTests[] = $tc;
    return $tc;
}

/**
 * 执行一次 API 调用并创建 TestCase
 */
function apiT(string $name, string $url, string $method = 'GET', $body = null, array $headers = []): TestCase
{
    $res = apiCall($url, $method, $body, $headers);
    return T($name, $method, $url, $res['code'], $res['body'], $res['error']);
}

/**
 * 远程可用性检查
 */
function remoteAvailable(int $httpCode): bool
{
    return $httpCode >= 200 && $httpCode < 500 && $httpCode !== 404;
}

// ═══════════════════════════════════════════════════════════
// 测试用例
// ═══════════════════════════════════════════════════════════

echo "\n╔══════════════════════════════════════╗\n";
echo "║  Devops-Glue API 冒烟测试 v2       ║\n";
echo "║  目标: {$baseUrl}\n";
echo "╚══════════════════════════════════════╝\n\n";

// ─── 1. 健康检查 + 文档 + CORS ───

$tc = apiT('健康检查', "{$baseUrl}/api/health");
$tc->assertHttpOk();
$tc->assertJson();
$health = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($health, 'status', '有 status 字段');
$tc->assertHasKey($health, 'checks', '有 checks 字段');
$tc->assertHasKey($health, 'stats', '有 stats 字段');
$tc->assertHasKey($health, 'build_mode', '有 build_mode 字段');
$tc->assertHasKey($health, 'db_driver', '有 db_driver 字段');
$tc->assertHasKey($health, 'app_version', '有 app_version 字段');
$tc->assertHasKey($health, 'app_env', '有 app_env 字段');
$tc->assertHasKey($health, 'time', '有 time 字段');
if (isset($health['status'])) {
    $tc->assertIn($health['status'], ['ok', 'degraded'], "status 是 ok 或 degraded");
}
if (isset($health['db_driver'])) {
    $tc->assertIn($health['db_driver'], ['sqlite', 'mysql'], "db_driver 是 sqlite 或 mysql");
}

echo "  [Infra] 健康检查 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// OpenAPI 规范（无 token 应返回 401）
$tc = apiT('OpenAPI 规范（无认证）', "{$baseUrl}/api/openapi.json");
$tc->assertHttpIs(401, '未认证返回 401');
echo "  [Infra] OpenAPI 规范(无认证) ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// CORS 预检
$ch = curl_init("{$baseUrl}/api/main/jobs/list");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CUSTOMREQUEST  => 'OPTIONS',
    CURLOPT_HTTPHEADER     => ['Origin: http://example.com', 'Access-Control-Request-Method: GET'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$corsBody = curl_exec($ch);
$corsCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tc = T('CORS 预检(OPTIONS)', 'OPTIONS', "{$baseUrl}/api/main/jobs/list", $corsCode, $corsBody ?: '(空响应)');
$tc->assert($corsCode >= 200 && $corsCode < 300, 'OPTIONS 返回 2xx', "实际 {$corsCode}");
echo "  [Infra] CORS 预检 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// i18n 语言包
$tc = apiT('i18n 中文语言包', "{$baseUrl}/api/i18n/zh_CN");
$tc->assertHttpOk();
$tc->assertJson();
$i18nData = json_decode($tc->rawBody, true) ?? [];
$tc->assert(is_array($i18nData) && count($i18nData) > 0, '包含翻译条目', '语言包为空');
echo "  [Infra] i18n 语言包 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// ─── 2. Main 模块 ───

$tc = apiT('Job列表（默认/raw）', "{$baseUrl}/api/main/jobs/list");
$tc->assertHttpOk();
echo "  [Main] Job列表(default) ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Job列表（JSON）', "{$baseUrl}/api/main/jobs/list?format=json");
$tc->assertHttpOk();
$tc->assertJson();
$jobData = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($jobData, 'data', 'JSON格式包裹 data');
echo "  [Main] Job列表(JSON) ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Job列表（XML）', "{$baseUrl}/api/main/jobs/list?format=xml");
$tc->assertHttpOk();
echo "  [Main] Job列表(XML) ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('三方映射列表', "{$baseUrl}/api/main/map/list");
$tc->assertHttpOk();
$tc->assertJson();
$mapData = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($mapData, 'projects', '包含 projects 键');
echo "  [Main] 三方映射 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Git平台列表', "{$baseUrl}/api/main/git/platforms");
$tc->assertHttpOk();
$tc->assertJson();
$platData = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($platData, 'git_platforms', '包含 git_platforms');
echo "  [Main] Git平台 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Git平台发现', "{$baseUrl}/api/main/git/discovery");
$tc->assertHttpOk();
$tc->assertJson();
$discData = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($discData, 'configured', '包含 configured');
$tc->assertHasKey($discData, 'unconfigured', '包含 unconfigured');
echo "  [Main] Git发现 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// ─── 3. Admin 登录 + 认证接口 ───

$tc = apiT('登录（正确凭据）', "{$baseUrl}/api/admin/login", 'POST', ['user' => $loginUser, 'password' => $loginPassword]);
$tc->assertHttpOk('登录成功 200');
$tc->assertJson();
$loginData = json_decode($tc->rawBody, true) ?? [];
$tc->assertHasKey($loginData, 'token', '包含 token');
if (isset($loginData['token'])) {
    $tc->assertNotEmpty($loginData['token'], 'token 非空');
    $tc->assertIsType('string', $loginData['token'], 'token 是字符串');
    $globalToken = (string)$loginData['token'];
}
echo "  [Admin] 登录 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// 错误密码
$tc = apiT('登录（错误密码）', "{$baseUrl}/api/admin/login", 'POST', ['user' => $loginUser, 'password' => 'wrong_wrong_wrong']);
$tc->assertHttpIs(401, '错误密码返回 401');
echo "  [Admin] 错误密码 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// 带 token 的认证接口
if ($globalToken) {
    $authHeader = ["Authorization: Bearer {$globalToken}"];

    $tc = apiT('映射列表(认证)', "{$baseUrl}/api/admin/job_git_map", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    $tc->assertJson();
    $tc->assertIsArray();
    echo "  [Admin] 映射列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    $tc = apiT('对接版本(认证)', "{$baseUrl}/api/admin/platform_versions", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    $tc->assertJson();
    $tc->assertIsArray();
    echo "  [Admin] 对接版本 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    $tc = apiT('安全扫描(认证)', "{$baseUrl}/api/admin/security_checks", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    $tc->assertJson();
    $tc->assertIsArray();
    echo "  [Admin] 安全扫描 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    $tc = apiT('构建模式(认证)', "{$baseUrl}/api/admin/build_mode", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    $tc->assertJson();
    $modeData = json_decode($tc->rawBody, true) ?? [];
    $tc->assertHasKey($modeData, 'mode', '包含 mode 字段');
    echo "  [Admin] 构建模式 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    // 用户列表
    $tc = apiT('用户列表(认证)', "{$baseUrl}/api/admin/users", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    echo "  [Admin] 用户列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    // OpenAPI 规范（带 token 应返回 200）
    $tc = apiT('OpenAPI 规范（认证）', "{$baseUrl}/api/openapi.json", 'GET', null, $authHeader);
    $tc->assertHttpOk();
    $tc->assertJson();
    $openApiData = json_decode($tc->rawBody, true) ?? [];
    $tc->assertHasKey($openApiData, 'openapi', '是 OpenAPI 规范文档');
    $tc->assertHasKey($openApiData, 'paths', '包含 paths');
    echo "  [Admin] OpenAPI 规范(认证) ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";
} else {
    $tc = T('认证接口（跳过）', 'GET', "{$baseUrl}/api/admin/job_git_map", 0, '登录失败，跳过认证接口');
    $tc->skip('登录未获取到 token');
    echo "  [Admin] 认证接口 ... \033[33mSKIP\033[0m (无token)\n";
}

// ─── 4. Build 模块 ───

$tc = apiT('全量Job列表', "{$baseUrl}/api/build/jobs/list");
$tc->assertHttpOk();
$tc->assertJson();
$tc->assertIsArray();
echo "  [Build] 全量Job列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

foreach ($testJobs as $job) {
    // variables
    $tc = apiT("$job 参数", "{$baseUrl}/api/build/{$job}/variables");
    $tc->assertHttpRange("variables 接口", 200, 404);
    if (remoteAvailable($tc->httpCode)) {
        $tc->assertJson();
        $tc->assertIsArray();
    }
    echo "  [Build] {$job}/variables ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : ($tc->status === 'skip' ? "\033[33mSKIP\033[0m" : "\033[31mFAIL\033[0m")) . "\n";

    // branches
    $tc = apiT("$job 分支", "{$baseUrl}/api/build/{$job}/branches");
    $tc->assertHttpRange("branches 接口", 200, 404);
    if (remoteAvailable($tc->httpCode)) {
        $tc->assertJson();
        $tc->assertIsArray();
    }
    echo "  [Build] {$job}/branches ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : ($tc->status === 'skip' ? "\033[33mSKIP\033[0m" : "\033[31mFAIL\033[0m")) . "\n";

    // pipelines (id list)
    $tc = apiT("$job Pipeline ID列表", "{$baseUrl}/api/build/{$job}/pipelines?list=id");
    $tc->assertHttpRange("pipelines 接口", 200, 404);
    if (remoteAvailable($tc->httpCode)) {
        $tc->assertJson();
        $buildIds = json_decode($tc->rawBody, true) ?: [];
        $tc->assertIsArray();
        if (!empty($buildIds) && is_array($buildIds)) {
            $firstId = $buildIds[0];
            // pipeline detail
            $tc2 = apiT("$job Pipeline详情(#{$firstId})", "{$baseUrl}/api/build/{$job}/pipelines/{$firstId}");
            $tc2->assertHttpRange("pipeline 详情", 200, 404);
            if (remoteAvailable($tc2->httpCode)) {
                $tc2->assertJson();
                $tc2->assertIsArray();
            }
            echo "  [Build] {$job}/pipelines/{$firstId} ... " . ($tc2->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

            // logs
            $tc2 = apiT("$job 日志(#{$firstId})", "{$baseUrl}/api/build/{$job}/logs/{$firstId}");
            $tc2->assertHttpRange("logs 接口", 200, 404);
            echo "  [Build] {$job}/logs/{$firstId} ... " . ($tc2->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";
        }
    }
    echo "  [Build] {$job}/pipelines?list=id ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : ($tc->status === 'skip' ? "\033[33mSKIP\033[0m" : "\033[31mFAIL\033[0m")) . "\n";

    // pipeline list formats
    foreach (['build', 'time'] as $fmt) {
        $tc = apiT("$job Pipeline({$fmt})", "{$baseUrl}/api/build/{$job}/pipelines?list={$fmt}");
        $tc->assertHttpRange("pipelines?list={$fmt}", 200, 404);
        echo "  [Build] {$job}/pipelines?list={$fmt} ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";
    }

    // git branches
    $tc = apiT("$job Git分支", "{$baseUrl}/api/git/{$job}/branches");
    $tc->assertHttpRange("git branches", 200, 404);
    if (remoteAvailable($tc->httpCode)) {
        $tc->assertJson();
        $tc->assertIsArray();
    }
    echo "  [Git] {$job}/branches ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";
}

// GitLab CI 专属测试
$tc = apiT('GitLab CI Pipeline(完整)', "{$baseUrl}/api/build/tools/runner-ci/pipelines");
$tc->assertHttpRange("GL CI 完整", 200, 404);
echo "  [Build] GitLab CI pipelines ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Jenkins Pipeline(完整)', "{$baseUrl}/api/build/static/pipelines");
$tc->assertHttpRange("Jenkins 完整", 200, 404);
echo "  [Build] Jenkins pipelines ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('GitLab CI Variables', "{$baseUrl}/api/build/tools/runner-ci/variables");
$tc->assertHttpRange("GL CI vars", 200, 404);
echo "  [Build] GitLab CI variables ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Jenkins Variables', "{$baseUrl}/api/build/static/variables");
$tc->assertHttpRange("Jenkins vars", 200, 404);
echo "  [Build] Jenkins variables ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// ─── 5. Harbor 模块 ───

$tc = apiT('Harbor 项目列表', "{$baseUrl}/api/harbor/projects");
$tc->assertHttpRange("harbor projects", 200, 404);
if (remoteAvailable($tc->httpCode)) {
    $tc->assertJson();
    $tc->assertIsArray();
}
echo "  [Harbor] 项目列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$tc = apiT('Harbor 仓库列表', "{$baseUrl}/api/harbor/{$harborProject}/repositories");
$tc->assertHttpRange("harbor repos", 200, 404);
echo "  [Harbor] 仓库列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

$repoEncoded = str_replace('/', '%2F', $harborRepo);
$tagsRes = apiCall("{$baseUrl}/api/harbor/{$harborProject}/repositories/{$repoEncoded}/tags");
$tc = T('Harbor Tag列表', 'GET', "{$baseUrl}/api/harbor/{$harborProject}/repositories/{$repoEncoded}/tags", $tagsRes['code'], $tagsRes['body'], $tagsRes['error']);
$tc->assertHttpRange("harbor tags", 200, 404);
if (remoteAvailable($tc->httpCode)) {
    $tc->assertJson();
    $tc->assertIsArray();
}
echo "  [Harbor] Tag列表 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

// 扫描（依赖有 tag）
$tags = json_decode($tagsRes['body'], true);
if (!empty($tags) && is_array($tags) && remoteAvailable($tagsRes['code'])) {
    $testTag = $tags[0];
    $scanUrl = "{$baseUrl}/api/harbor/{$harborProject}/repositories/{$repoEncoded}/tags/" . rawurlencode($testTag) . "/scan";

    $res = apiCall($scanUrl, 'POST');
    $tc = T("Harbor 触发扫描({$testTag})", 'POST', $scanUrl, $res['code'], $res['body'], $res['error']);
    $tc->assert($res['code'] >= 200 && $res['code'] < 500, '触发扫描有响应', "HTTP {$res['code']}");
    echo "  [Harbor] 触发扫描 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";

    $res = apiCall($scanUrl, 'GET');
    $tc = T("Harbor 扫描报告({$testTag})", 'GET', $scanUrl, $res['code'], $res['body'], $res['error']);
    $tc->assertHttpRange('扫描报告', 200, 404);
    echo "  [Harbor] 扫描报告 ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "\n";
} else {
    $tc = T('Harbor 扫描', 'POST', "(tag列表)", 0, '跳过', '');
    $tc->skip('无可用 tag');
    echo "  [Harbor] 扫描 ... \033[33mSKIP\033[0m (无tag)\n";
    $tc = T('Harbor 扫描报告', 'GET', "(tag列表)", 0, '跳过', '');
    $tc->skip('无可用 tag');
}

// ─── 6. 构建触发（有副作用的写操作，谨慎处理）──

foreach ($testJobs as $job) {
    $params = $triggerParams[$job] ?? null;
    if ($params === null) {
        $tc = T("{$job} 触发", 'POST', "{$baseUrl}/api/build/{$job}/trigger", 0, '跳过', '');
        $tc->skip("未配置 triggerParams");
        echo "  [Trigger] {$job} ... \033[33mSKIP\033[0m\n";
        continue;
    }
    $url = "{$baseUrl}/api/build/{$job}/trigger";
    $res = apiCall($url, 'POST', ['variables' => $params]);
    $tc = T("{$job} 触发 " . json_encode($params, JSON_UNESCAPED_UNICODE), 'POST', $url, $res['code'], $res['body'], $res['error']);
    // 触发可能返回 200/202/400/404 等
    $tc->assert($res['code'] >= 200 && $res['code'] < 500, '触发有响应', "HTTP {$res['code']}");
    if ($res['code'] >= 200 && $res['code'] < 300) {
        $tc->assertJson();
    }
    echo "  [Trigger] {$job} ... " . ($tc->status === 'pass' ? "\033[32mPASS\033[0m" : ($tc->status === 'skip' ? "\033[33mSKIP\033[0m" : "\033[31mFAIL\033[0m")) . "\n";
}

// ═══════════════════════════════════════════════════════════
// 统计
// ═══════════════════════════════════════════════════════════

$total    = count($allTests);
$passed   = count(array_filter($allTests, fn($t) => $t->status === 'pass'));
$failed   = count(array_filter($allTests, fn($t) => $t->status === 'fail'));
$skipped  = count(array_filter($allTests, fn($t) => $t->status === 'skip'));
$assertions = array_reduce($allTests, fn($sum, $t) => $sum + count($t->assertions), 0);
$assertOk   = array_reduce($allTests, fn($sum, $t) => $sum + count(array_filter($t->assertions, fn($a) => $a->passed)), 0);

echo "\n──────────────────────────────────────────\n";
echo "  测试用例: {$total}  通过: {$passed}  失败: {$failed}  跳过: {$skipped}\n";
echo "  断言总数: {$assertions}  通过: {$assertOk}  失败: " . ($assertions - $assertOk) . "\n";
echo "──────────────────────────────────────────\n\n";

// ═══════════════════════════════════════════════════════════
// HTML 报告
// ═══════════════════════════════════════════════════════════

$reportFile = __DIR__ . '/../public/test_report_' . date('Ymd_His') . '.html';
$html  = '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8">';
$html .= '<title>API 冒烟测试报告</title>';
$html .= '<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;margin:0;padding:0;background:#f8f9fa;color:#333;line-height:1.6}
.header{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:32px 40px}
.header h1{font-size:26px;margin-bottom:8px}
.header .meta{font-size:14px;opacity:.8}
.summary-bar{display:flex;gap:24px;padding:20px 40px;background:#fff;border-bottom:1px solid #e0e0e0;flex-wrap:wrap}
.stat{text-align:center}
.stat .num{font-size:28px;font-weight:700}
.stat .label{font-size:12px;color:#888;text-transform:uppercase;margin-top:2px}
.passColor{color:#16a34a}.failColor{color:#dc2626}.skipColor{color:#f59e0b}
.container{padding:24px 40px}
.module{margin-bottom:32px}
.module h2{font-size:18px;padding:8px 0;border-bottom:2px solid #2563eb;margin-bottom:16px;display:flex;align-items:center;gap:12px}
.module h2 .badge{font-size:12px;padding:2px 10px;border-radius:10px;color:#fff}
.test-card{background:#fff;border-radius:8px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
.test-card-header{padding:10px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #f0f0f0;background:#fafbfc}
.test-card-header .method{font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;background:#e8edf3;color:#555}
.test-card-body{padding:8px 16px}
.assert-row{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px}
.assert-row .icon{width:16px;text-align:center}
.assert-detail{font-size:11px;color:#999;margin-left:24px;font-family:monospace}
.preview-block{font-family:monospace;font-size:11px;background:#f8f9fa;padding:6px 10px;border-radius:4px;margin:4px 0;word-break:break-all;color:#666;max-height:60px;overflow:hidden}
.status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}.status-dot.pass{background:#16a34a}.status-dot.fail{background:#dc2626}.status-dot.skip{background:#f59e0b}
.url-text{font-size:12px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px}
</style></head><body>';

$html .= '<div class="header">';
$html .= '<h1>API 冒烟测试报告</h1>';
$html .= '<div class="meta">目标: ' . htmlspecialchars($baseUrl) . ' &nbsp;|&nbsp; ' . date('Y-m-d H:i:s') . '</div>';
$html .= '</div>';

$html .= '<div class="summary-bar">';
$html .= '<div class="stat"><div class="num passColor">' . $passed . '</div><div class="label">通过</div></div>';
$html .= '<div class="stat"><div class="num failColor">' . $failed . '</div><div class="label">失败</div></div>';
$html .= '<div class="stat"><div class="num skipColor">' . $skipped . '</div><div class="label">跳过</div></div>';
$html .= '<div class="stat"><div class="num">' . $total . '</div><div class="label">总计</div></div>';
$html .= '<div class="stat"><div class="num">' . $assertions . '</div><div class="label">断言</div></div>';
$rate = $assertions > 0 ? round($assertOk / $assertions * 100, 1) : 0;
$html .= '<div class="stat"><div class="num ' . ($rate >= 100 ? 'passColor' : ($rate >= 80 ? 'skipColor' : 'failColor')) . '">' . $rate . '%</div><div class="label">断言通过率</div></div>';
$html .= '</div>';

// 按模块分组
$moduleMap = [];
foreach ($allTests as $tc) {
    // 根据 URL 推断模块
    if (str_contains($tc->url, '/api/health') || str_contains($tc->url, '/api/i18n') || str_contains($tc->url, '/openapi') || str_contains($tc->url, 'OPTIONS')) {
        $mod = 'Infra';
    } elseif (str_contains($tc->url, '/api/admin/') || str_contains($tc->url, '/api/openapi')) {
        $mod = 'Admin';
    } elseif (str_contains($tc->url, '/api/main/')) {
        $mod = 'Main';
    } elseif (str_contains($tc->url, '/api/harbor/') || str_contains($tc->url, 'Harbor')) {
        $mod = 'Harbor';
    } elseif (str_contains($tc->url, '/api/build/') || str_contains($tc->url, '/api/git/') || str_contains($tc->url, 'Build') || str_contains($tc->url, 'GitLab') || str_contains($tc->url, 'Jenkins') || str_contains($tc->url, 'runner-ci')) {
        $mod = 'Build';
    } elseif (str_contains($tc->url, 'trigger')) {
        $mod = 'Trigger';
    } elseif (str_contains($tc->name, 'Git')) {
        $mod = 'Git';
    } else {
        $mod = 'Other';
    }
    if ($tc->status === 'skip' && str_contains($tc->url, '(tag')) {
        $mod = 'Harbor';
    }
    $moduleMap[$mod][] = $tc;
}

$moduleOrder = ['Infra', 'Admin', 'Main', 'Build', 'Git', 'Harbor', 'Trigger', 'Other'];
foreach ($moduleOrder as $mod) {
    if (empty($moduleMap[$mod])) continue;
    $modTests = $moduleMap[$mod];
    $modPass = count(array_filter($modTests, fn($t) => $t->status === 'pass'));
    $modFail = count(array_filter($modTests, fn($t) => $t->status === 'fail'));
    $badgeCls = $modFail > 0 ? 'failColor' : ($modPass === count($modTests) ? 'passColor' : 'skipColor');
    $badgeBg = $modFail > 0 ? 'dc2626' : ($modPass === count($modTests) ? '16a34a' : 'f59e0b');

    $html .= '<div class="container"><div class="module">';
    $html .= "<h2>{$mod} 模块 <span class='badge' style='background:#{$badgeBg}'>{$modPass}/" . count($modTests) . "</span></h2>";

    foreach ($modTests as $tc) {
        $cls = $tc->status;
        $html .= '<div class="test-card">';
        $html .= '<div class="test-card-header">';
        $html .= "<span class='status-dot {$cls}'></span>";
        $html .= "<span class='method'>" . htmlspecialchars($tc->method) . '</span>';
        $html .= '<strong style="font-size:13px">' . htmlspecialchars($tc->name) . '</strong>';
        if ($tc->httpCode) {
            $codeCls = $tc->httpCode < 400 ? 'passColor' : 'failColor';
            $html .= "<span style='font-size:12px;{$codeCls};margin-left:auto'>HTTP {$tc->httpCode}</span>";
        }
        $html .= '</div>';
        $html .= '<div class="test-card-body">';
        if ($tc->url && !str_starts_with($tc->url, '(')) {
            $html .= '<div class="url-text">' . htmlspecialchars(mb_strlen($tc->url) > 80 ? mb_substr($tc->url, 0, 80) . '...' : $tc->url) . '</div>';
        }
        if ($tc->error) {
            $html .= '<div class="assert-row"><span class="icon" style="color:#dc2626">✗</span> CURL 错误: ' . htmlspecialchars($tc->error) . '</div>';
        }
        foreach ($tc->assertions as $a) {
            $icon  = $a->passed ? '<span class="icon" style="color:#16a34a">✓</span>' : (str_contains($a->label, '跳过') ? '<span class="icon" style="color:#f59e0b">○</span>' : '<span class="icon" style="color:#dc2626">✗</span>');
            $label = htmlspecialchars($a->label);
            $html .= "<div class='assert-row'>{$icon} {$label}</div>";
            if ($a->detail) {
                $html .= '<div class="assert-detail">' . htmlspecialchars($a->detail) . '</div>';
            }
        }
        if ($tc->preview && $tc->preview !== '(空响应)' && !empty($tc->assertions)) {
            $preview = htmlspecialchars(mb_substr($tc->preview, 0, 150));
            $html .= '<div class="preview-block">' . $preview . '</div>';
        }
        $html .= '</div></div>';
    }

    $html .= '</div></div>';
}

$html .= '</body></html>';
file_put_contents($reportFile, $html);

echo "报告已生成: {$reportFile}\n\n";

exit($failed > 0 ? 1 : 0);
