<?php
/**
 * 快速测试脚本（核心接口 + 代表性格式切换）
 * 使用：php test/test_api_html_simp.php [baseUrl]
 * 示例：php test/test_api_html_simp.php http://localhost:8080
 * 生成文件：public/test_report_YYYYMMDD_HHMMSS.html
 */

$baseUrl = $argv[1] ?? getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
$baseUrl = rtrim($baseUrl, '/');

$testJobs       = ['static', 'php/myapp', 'java/registry'];
$harborProject  = 'mycode';
$harborRepo     = 'diagnosis-runtime';

function apiCall($url, $method = 'GET') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POST, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $response, 'error' => $error];
}

$reportFile = __DIR__ . '/../public/test_report_' . date('Ymd_His') . '.html';
$rows = [];

function addRow($module, $label, $url, $result) {
    global $rows;
    $status = ($result['code'] >= 200 && $result['code'] < 400) ? 'pass' : 'fail';
    if ($result['error']) $status = 'fail';
    $preview = htmlspecialchars(substr($result['body'], 0, 200));
    if ($result['error']) {
        $preview = '[CURL错误] ' . htmlspecialchars($result['error']) . ($preview ? ' | ' . $preview : '');
    }
    if ($result['code'] === 0 && !$result['error']) {
        $preview = '[无响应]' . ($preview ? ' | ' . $preview : '');
    }
    $rows[] = [
        'module'  => $module,
        'label'   => $label,
        'url'     => $url,
        'status'  => $status,
        'code'    => $result['code'],
        'preview' => $preview,
        'error'   => $result['error'] ?: '',
    ];
}

echo "Devops-Glue 快速测试 → {$baseUrl}\n\n";

// ====== Infra（健康检查 + 文档 + CORS）======
$res = apiCall("$baseUrl/api/health");
addRow('Infra', '健康检查', "$baseUrl/api/health", $res);

$res = apiCall("$baseUrl/api/docs");
addRow('Infra', 'API 文档(Swagger)', "$baseUrl/api/docs", $res);

$res = apiCall("$baseUrl/api/openapi.json");
addRow('Infra', 'OpenAPI 规范', "$baseUrl/api/openapi.json", $res);

$ch = curl_init("$baseUrl/api/main/jobs/list");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CUSTOMREQUEST  => 'OPTIONS',
    CURLOPT_HTTPHEADER     => ['Origin: http://example.com', 'Access-Control-Request-Method: GET'],
]);
$corsBody = curl_exec($ch);
$corsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
addRow('Infra', 'CORS 预检(OPTIONS)', "$baseUrl/api/main/jobs/list [OPTIONS]", ['code' => $corsCode, 'body' => $corsBody ?: '(空响应)', 'error' => '']);

// ====== Main 模块 ======
$res = apiCall("$baseUrl/api/main/jobs/list");
addRow('Main', 'Job列表（默认）', "$baseUrl/api/main/jobs/list", $res);

$res = apiCall("$baseUrl/api/main/jobs/list?format=json");
addRow('Main', 'Job列表（JSON）', "$baseUrl/api/main/jobs/list?format=json", $res);

$res = apiCall("$baseUrl/api/main/jobs/list?format=xml");
addRow('Main', 'Job列表（XML）', "$baseUrl/api/main/jobs/list?format=xml", $res);

$res = apiCall("$baseUrl/api/main/map/list");
addRow('Main', '三方映射（默认）', "$baseUrl/api/main/map/list", $res);

$res = apiCall("$baseUrl/api/main/git/platforms");
addRow('Main', 'Git平台列表（默认）', "$baseUrl/api/main/git/platforms", $res);

$res = apiCall("$baseUrl/api/main/git/discovery");
addRow('Main', '平台发现（默认）', "$baseUrl/api/main/git/discovery", $res);

// ====== Build 模块（替代旧 Jenkins） ======
foreach ($testJobs as $job) {
    $res = apiCall("$baseUrl/api/build/$job/variables");
    addRow('Build', "$job 参数", "$baseUrl/api/build/$job/variables", $res);

    $res = apiCall("$baseUrl/api/build/$job/pipelines?list=id");
    addRow('Build', "$job 构建ID", "$baseUrl/api/build/$job/pipelines?list=id", $res);

    $buildIds = json_decode($res['body'], true) ?: [];
    if (!empty($buildIds)) {
        $firstId = $buildIds[0];
        $res = apiCall("$baseUrl/api/build/$job/pipelines/$firstId");
        addRow('Build', "$job 状态(#$firstId)", "$baseUrl/api/build/$job/pipelines/$firstId", $res);

        $res = apiCall("$baseUrl/api/build/$job/logs/$firstId");
        addRow('Build', "$job 日志(#$firstId)", "$baseUrl/api/build/$job/logs/$firstId", $res);
    }

    $res = apiCall("$baseUrl/api/build/$job/pipelines?list=build");
    addRow('Build', "$job 构建(#)", "$baseUrl/api/build/$job/pipelines?list=build", $res);

    $res = apiCall("$baseUrl/api/build/$job/pipelines?list=time");
    addRow('Build', "$job 构建(时间)", "$baseUrl/api/build/$job/pipelines?list=time", $res);

    $res = apiCall("$baseUrl/api/git/$job/branches");
    addRow('Git', "$job 分支", "$baseUrl/api/git/$job/branches", $res);
}

// ====== Harbor ======
$res = apiCall("$baseUrl/api/harbor/projects");
addRow('Harbor', '项目列表', "$baseUrl/api/harbor/projects", $res);

// ====== Admin 模块 ======
$loginRes = apiCall("$baseUrl/api/admin/login", 'POST');
$loginData = json_decode($loginRes['body'], true);
$token = $loginData['token'] ?? '';
addRow('Admin', '登录 (POST)', "$baseUrl/api/admin/login", $loginRes);

if ($token) {
    $res = apiCall("$baseUrl/api/admin/job_git_map");
    // Need to add auth header... curl doesn't support that in our simple apiCall
    $ch = curl_init("$baseUrl/api/admin/job_git_map");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    addRow('Admin', '映射列表(认证)', "$baseUrl/api/admin/job_git_map", ['code' => $code, 'body' => $body, 'error' => '']);

    $ch = curl_init("$baseUrl/api/admin/platform_versions");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    addRow('Admin', '对接版本(认证)', "$baseUrl/api/admin/platform_versions", ['code' => $code, 'body' => $body, 'error' => '']);
}

// ====== Build 模块（GitLab CI + Jenkins 统一入口）======
$res = apiCall("$baseUrl/api/build/jobs/list");
addRow('Build', '全量Job列表', "$baseUrl/api/build/jobs/list", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/pipelines");
addRow('Build', 'GitLab CI Pipeline列表(完整)', "$baseUrl/api/build/tools/runner-ci/pipelines", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/pipelines?list=id");
addRow('Build', 'GitLab CI Pipeline列表(ID)', "$baseUrl/api/build/tools/runner-ci/pipelines?list=id", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/pipelines?list=build");
addRow('Build', 'GitLab CI Pipeline列表(#)', "$baseUrl/api/build/tools/runner-ci/pipelines?list=build", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/pipelines?list=time");
addRow('Build', 'GitLab CI Pipeline列表(时间)', "$baseUrl/api/build/tools/runner-ci/pipelines?list=time", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/tag?pipeline=9");
addRow('Build', 'Pipeline→Tag查询', "$baseUrl/api/build/tools/runner-ci/tag?pipeline=9", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/variables");
addRow('Build', 'GitLab CI Variables', "$baseUrl/api/build/tools/runner-ci/variables", $res);

$res = apiCall("$baseUrl/api/build/static/pipelines");
addRow('Build', 'Jenkins Build列表(完整)', "$baseUrl/api/build/static/pipelines", $res);

$res = apiCall("$baseUrl/api/build/static/pipelines?list=build");
addRow('Build', 'Jenkins Build列表(#)', "$baseUrl/api/build/static/pipelines?list=build", $res);

$res = apiCall("$baseUrl/api/build/static/variables");
addRow('Build', 'Jenkins Variables', "$baseUrl/api/build/static/variables", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/pipelines/4");
addRow('Build', 'Pipeline #4 详情', "$baseUrl/api/build/tools/runner-ci/pipelines/4", $res);

$res = apiCall("$baseUrl/api/build/tools/runner-ci/logs/27");
addRow('Build', 'GitLab CI 日志(统一)', "$baseUrl/api/build/tools/runner-ci/logs/27", $res);

$res = apiCall("$baseUrl/api/build/php/myapp/logs/25");
addRow('Build', 'Jenkins 日志(统一)', "$baseUrl/api/build/php/myapp/logs/25", $res);

$res = apiCall("$baseUrl/api/git/tools/runner-ci/branches");
addRow('Build', 'GitLab CI 分支', "$baseUrl/api/git/tools/runner-ci/branches", $res);

$res = apiCall("$baseUrl/api/harbor/$harborProject/repositories");
addRow('Harbor', '仓库列表', "$baseUrl/api/harbor/$harborProject/repositories", $res);

$repoEncoded = str_replace('/', '%2F', $harborRepo);
$tagsRes = apiCall("$baseUrl/api/harbor/$harborProject/repositories/$repoEncoded/tags");
addRow('Harbor', 'Tag列表', "$baseUrl/api/harbor/$harborProject/repositories/$repoEncoded/tags", $tagsRes);

$tags = json_decode($tagsRes['body'], true);
if (!empty($tags) && is_array($tags)) {
    $testTag = $tags[0];
    $scanUrl = "$baseUrl/api/harbor/$harborProject/repositories/$repoEncoded/tags/" . rawurlencode($testTag) . "/scan";
    $res = apiCall($scanUrl, 'POST');
    addRow('Harbor', "触发扫描($testTag)", $scanUrl, $res);

    $res = apiCall($scanUrl, 'GET');
    addRow('Harbor', "扫描报告($testTag)", $scanUrl, $res);
} else {
    addRow('Harbor', '扫描测试', '—', ['code' => 0, 'body' => '无可用tag', 'error' => '']);
}

// ====== 构建触发（默认开启） ======
foreach ($testJobs as $job) {
    $varsRes = apiCall("$baseUrl/api/build/$job/variables");
    $params = json_decode($varsRes['body'], true);
    if (!$params || $varsRes['code'] != 200) {
        addRow('Trigger', "$job 参数获取失败", '', ['code' => 500, 'body' => '', 'error' => '']);
        continue;
    }

    $branchKey = null;
    foreach ($params as $name => $choices) {
        if (stripos($name, 'branch') !== false) { $branchKey = $name; break; }
    }
    if (!$branchKey) $branchKey = array_keys($params)[0] ?? null;
    if (!$branchKey) { addRow('Trigger', "$job 无法识别参数", '', ['code' => 400, 'body' => '', 'error' => '']); continue; }

    $branchValue = is_array($params[$branchKey]) ? reset($params[$branchKey]) : '';
    if (empty($branchValue)) { addRow('Trigger', "$job 无可用分支值", '', ['code' => 400, 'body' => '', 'error' => '']); continue; }

    $url = "$baseUrl/api/build/$job/trigger?$branchKey=$branchValue";
    $res = apiCall($url, 'POST');
    addRow('Trigger', "$job 触发", $url, $res);
}

// ====== 生成 HTML 报告 ======
$html = '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>API 快速测试报告</title>';
$html .= '<style>
body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
h1{color:#333}.summary{margin-bottom:20px;color:#666}
table{border-collapse:collapse;width:100%;margin-bottom:28px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
th,td{border-bottom:1px solid #eee;padding:10px 12px;text-align:left}
th{background:#f8f9fa;font-size:13px;text-transform:uppercase;color:#666}
.pass{color:#16a34a;font-weight:bold}.fail{color:#dc2626;font-weight:bold}
.preview{font-family:monospace;font-size:11px;max-width:420px;word-break:break-all;color:#555}
a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}
.btn{display:inline-block;padding:8px 20px;background:#2563eb;color:#fff!important;text-decoration:none;border-radius:6px;font-size:14px}
</style></head><body>';

$passCount = count(array_filter($rows, fn($r) => $r['status'] === 'pass'));
$total     = count($rows);
$html .= "<h1>API 快速测试报告</h1>";
$html .= "<div class='summary'>服务地址: " . htmlspecialchars($baseUrl) . " &nbsp;|&nbsp; ";
$html .= "测试时间: " . date('Y-m-d H:i:s') . " &nbsp;|&nbsp; ";
$html .= "通过: {$passCount}/{$total}</div>";
$html .= "<p><a class='btn' href='test_api_html_simp.php'>🔄 重新测试</a></p>";

$modules = ['Infra', 'Admin', 'Main', 'Build', 'Git', 'Harbor', 'Trigger'];
foreach ($modules as $mod) {
    $modRows = array_filter($rows, fn($r) => $r['module'] === $mod);
    if (empty($modRows)) continue;
    $html .= "<h2>$mod 模块</h2>";
    $html .= '<table><tr><th>接口描述</th><th>URL</th><th>状态</th><th>HTTP</th><th>响应预览</th></tr>';
    foreach ($modRows as $row) {
        $cls = $row['status'] === 'pass' ? 'pass' : 'fail';
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['label']) . '</td>';
        $html .= '<td><a href="' . htmlspecialchars($row['url']) . '" target="_blank">' . htmlspecialchars($row['url']) . '</a></td>';
        $html .= '<td class="' . $cls . '">' . ($row['status'] === 'pass' ? '✓' : '✗') . '</td>';
        $html .= '<td>' . $row['code'] . '</td>';
        $html .= '<td class="preview">' . $row['preview'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
}

$html .= '</body></html>';
file_put_contents($reportFile, $html);
echo "\n报告已生成: {$reportFile}\n";
