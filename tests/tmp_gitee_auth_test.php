<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

$token = getenv('GITEE_TOKEN');
$repo = 'lucky-boy1/git_one_app';
$urlBase = 'https://gitee.com/api/v5/repos/' . $repo . '/branches?per_page=1';

echo "TOKEN_PRESENT=" . (!empty($token) ? 'yes' : 'no') . "\n";
echo "TOKEN_PREFIX=" . substr($token, 0, 8) . "...\n";

function testRequest(string $mode, string $url, ?array $headers = null): void
{
    echo "MODE={$mode}\n";
    echo "URL={$url}\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "CODE={$code}\n";
    echo substr($body, 0, 1000) . "\n";
    echo str_repeat('-', 60) . "\n";
}

testRequest('query', $urlBase . '&access_token=' . urlencode($token));
testRequest('header', $urlBase, ['Authorization: token ' . $token]);
