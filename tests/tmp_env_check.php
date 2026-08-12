<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

echo "ENV keys: ";
var_export(array_key_exists('GITEE_TOKEN', $_ENV) ? 'yes' : 'no');
echo "\n";
echo "_ENV token: ";
var_export($_ENV['GITEE_TOKEN'] ?? null);
echo "\n";
echo "getenv token: ";
var_export(getenv('GITEE_TOKEN'));
echo "\n";

function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}
echo "helper env token: ";
var_export(env('GITEE_TOKEN'));
echo "\n";

echo "settings token: ";
$cfg = require __DIR__ . '/../config/settings.php';
var_export($cfg['git']['gitee']['token'] ?? null);
echo "\n";
