<?php
/**
 * CLI 共享引导：autoload + 环境变量加载 + envVal()。
 */

require __DIR__ . '/../vendor/autoload.php';

/** 读取配置：优先 $_ENV（phpdotenv 填充），其次 getenv()。 */
function envVal(string $key, string $default = ''): string
{
    return App\Support\EnvFileLoader::envVal($key, $default);
}

App\Support\EnvFileLoader::load(__DIR__ . '/../config');

/**
 * 加密一张表的存量明文敏感列（幂等：非空、无 enc:v1: 前缀才加密）。
 * 表名 / 列名 / 主键须为代码内白名单常量，不接用户输入。
 *
 * @param string[] $cols 需要加密的列名
 * @return int 加密的字段数；未配置 DATA_ENC_KEY 时抛异常
 */
function encryptSensitiveColumns(\PDO $pdo, string $table, string $pk, array $cols): int
{
    $enc = new App\Service\Encryptor();
    $enc->rawKey(); 

    $rows = $pdo->query('SELECT ' . implode(', ', array_merge([$pk], $cols)) . ' FROM ' . $table)
        ->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $updated = 0;
    foreach ($rows as $row) {
        $id = (string)($row[$pk] ?? '');
        if ($id === '') {
            continue;
        }
        foreach ($cols as $col) {
            $value = (string)($row[$col] ?? '');
            if ($value === '' || str_starts_with($value, App\Service\Encryptor::PREFIX)) {
                continue;
            }
            $pdo->prepare("UPDATE {$table} SET {$col} = ? WHERE {$pk} = ?")
                ->execute([$enc->encrypt($value), $id]);
            $updated++;
        }
    }
    return $updated;
}
