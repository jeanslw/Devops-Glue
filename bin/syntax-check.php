#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP 语法检查工具
 * 检查所有 PHP 文件的语法错误
 */

$filesChecked = 0;
$errorsFound = 0;
$errors = [];

// 要检查的文件路径
$filesToCheck = [
    __DIR__ . '/public/index.php',
    __DIR__ . '/config/routes.php',
    __DIR__ . '/config/settings.php',
    __DIR__ . '/config/EnvLoader.php',
    __DIR__ . '/src/Controllers/BaseController.php',
    __DIR__ . '/src/Controllers/JenkinsController.php',
    __DIR__ . '/src/Controllers/HarborController.php',
    __DIR__ . '/src/Controllers/GitController.php',
    __DIR__ . '/src/Services/JenkinsService.php',
    __DIR__ . '/src/Services/HarborService.php',
    __DIR__ . '/src/Services/GitService.php',
    __DIR__ . '/Exceptions/ApiException.php',
];

echo "🔍 PHP 语法检查工具\n";
echo "=====================\n\n";

foreach ($filesToCheck as $file) {
    if (!file_exists($file)) {
        echo "⚠️  文件不存在: $file\n";
        continue;
    }

    $filesChecked++;
    $output = [];
    $returnCode = 0;

    // 使用 php -l 命令检查语法
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        echo "✅ " . basename($file) . "\n";
    } else {
        echo "❌ " . basename($file) . "\n";
        $errorsFound++;
        $errors[$file] = implode("\n   ", $output);
    }
}

echo "\n=====================\n";
echo "检查结果: $filesChecked 个文件\n";

if ($errorsFound > 0) {
    echo "⚠️  发现 $errorsFound 个文件有错误\n\n";
    
    foreach ($errors as $file => $error) {
        echo "❌ $file:\n";
        echo "   $error\n\n";
    }
    
    exit(1);
} else {
    echo "✅ 所有文件语法正确！\n";
    exit(0);
}
