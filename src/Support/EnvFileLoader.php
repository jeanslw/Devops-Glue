<?php

namespace App\Support;

use Dotenv\Dotenv;

/**
 * app.env 三层加载（Web / CLI / 运维脚本共用的唯一实现）
 *
 * 优先级（高 → 低）：
 *   1. `config/app.env.local`     本地覆盖：文件存在时，其定义的键即为最终值（连真实环境变量也覆盖）
 *   2. 真实环境变量（OS 注入）    Docker env_file / shell export / Apache SetEnv / 面板环境变量
 *   3. `config/app.env.{APP_ENV}` 环境覆盖（提交 Git）
 *   4. `config/app.env`           基础配置（兜底缺省值）
 *
 * ⚠️ 为什么覆盖层必须用 Mutable：
 *    phpdotenv 的 Immutable 语义是「变量已存在则不写入」（见 RepositoryBuilder::make() → ImmutableWriter），
 *    而不是「允许覆盖」。若第 2/3 层用 createUnsafeImmutable()，则凡 `app.env` 已定义的键
 *    （DB_DRIVER / HARBOR_* / APP_DEBUG ...）都会被静默丢弃——表现为「改了 app.env.local 不生效」。
 *    第 1 层保持 Immutable，让 OS 真实环境变量优先于 `app.env`。
 *
 * ⚠️ 为什么第 3 步还要显式回写真实环境变量：
 *    ImmutableWriter 判定「已存在」只看 $_ENV / $_SERVER 适配器，不看 getenv()；
 *    当 variables_order 不含 E/S（$_ENV / $_SERVER 不被 PHP 自动填充）或值来自 putenv() 时，
 *    OS 注入的变量会被 `app.env` 的文件值压制。故加载完覆盖层后按 getenv() 快照显式回写一次。
 */
final class EnvFileLoader
{
    /**
     * 按固定优先级加载三层配置（幂等：同一进程重复调用结果一致）。
     *
     * @param string $configDir 存放 app.env 的目录（通常是 <项目根>/config）
     */
    public static function load(string $configDir): void
    {
        $configDir = rtrim($configDir, '/\\');

        // 1. 记录 OS 真实环境变量快照（此刻尚未被任何 .env 写入）
        $osEnv = getenv() ?: [];

        // 2. 基础配置 app.env：Immutable —— OS 环境变量优先，app.env 只补缺省
        $configKeys = self::fileKeys($configDir . '/app.env');
        Dotenv::createImmutable($configDir, 'app.env')->load();

        // 3. 环境覆盖 app.env.{APP_ENV}：Mutable 才能覆盖 app.env（文件不存在则跳过）
        $appEnv = self::envVal('APP_ENV', 'production');
        $appEnv = $appEnv === '' ? 'production' : $appEnv;
        $envFile = $configDir . '/app.env.' . $appEnv;
        if (is_file($envFile)) {
            $configKeys = array_merge($configKeys, self::fileKeys($envFile));
            Dotenv::createUnsafeMutable($configDir, 'app.env.' . $appEnv)->load();
        }

        // 4. 回写真实环境变量：其优先级高于 app.env / app.env.{APP_ENV}
        self::restoreOsValues($configKeys, $osEnv);

        // 5. 本地覆盖 app.env.local：优先级最高，不回写 —— 本文件定义的键完全以该文件为准
        if (is_file($configDir . '/app.env.local')) {
            Dotenv::createUnsafeMutable($configDir, 'app.env.local')->load();
        }
    }

    /**
     * 读取配置：优先 $_ENV（phpdotenv 填充），其次真实环境变量 getenv()。
     * 避免 variables_order 不含 E 时 shell 传入的环境变量丢失。
     */
    public static function envVal(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : (string)$v;
    }

    /**
     * 解析 env 文件中出现的键名。
     * 仅用于限定「真实环境变量回写」的范围，避免把无关 OS 变量灌进 $_ENV / $_SERVER。
     *
     * @return list<string>
     */
    private static function fileKeys(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }
        return array_keys(Dotenv::parse($content));
    }

    /**
     * 把真实环境变量的值重新写回 $_ENV / $_SERVER / putenv（只处理配置文件中出现过的键）。
     *
     * @param list<string>         $configKeys 配置文件里出现过的键
     * @param array<string,string> $osEnv      加载 .env 之前的真实环境变量快照
     */
    private static function restoreOsValues(array $configKeys, array $osEnv): void
    {
        if ($configKeys === [] || $osEnv === []) {
            return;
        }
        foreach (array_unique($configKeys) as $key) {
            if (!isset($osEnv[$key])) {
                continue;
            }
            $value = (string)$osEnv[$key];
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
