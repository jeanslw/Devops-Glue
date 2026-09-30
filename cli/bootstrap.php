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
    return App\Support\EnvFileLoader::envVal($key, $default);
}

// 加载环境变量（三层覆盖，与 Bootstrap 同一实现）
App\Support\EnvFileLoader::load(__DIR__ . '/../config');
