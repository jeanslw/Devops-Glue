<?php
/**
 * CLI 共享引导：autoload + dotenv 加载 + envVal()。
 * cli/*.php 统一 require 本文件，避免「dotenv 加载顺序 + env 读取」逻辑重复多份。
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * 读取配置：优先 $_ENV（phpdotenv 填充），其次真实环境变量 getenv()。
 * 避免 variables_order 不含 E 时 shell 传入的环境变量丢失。
 */
function envVal(string $key, string $default = ''): string
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    $v = getenv($key);
    return $v === false ? $default : (string)$v;
}

// 加载环境变量（顺序与 Bootstrap 一致：app.env → app.env.{APP_ENV} → app.env.local）
$baseDir = __DIR__ . '/../config';
Dotenv\Dotenv::createImmutable($baseDir, 'app.env')->load();

$appEnv = envVal('APP_ENV', 'production');
$envFile = $baseDir . '/app.env.' . $appEnv;
if (file_exists($envFile)) {
    Dotenv\Dotenv::createUnsafeImmutable($baseDir, 'app.env.' . $appEnv)->load();
}
$localFile = $baseDir . '/app.env.local';
if (file_exists($localFile)) {
    Dotenv\Dotenv::createUnsafeImmutable($baseDir, 'app.env.local')->load();
}
