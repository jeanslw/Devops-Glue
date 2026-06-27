#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 部署前检查工具
 * 验证所有必要条件是否满足
 */

$checks = [
    'php_version' => false,
    'composer_installed' => false,
    'env_file_exists' => false,
    'env_not_tracked' => false,
    'env_file_readable' => false,
    'vendor_exists' => false,
    'autoload_exists' => false,
    'config_files_exist' => false,
    'controllers_exist' => false,
    'services_exist' => false,
];

echo "🚀 部署前检查工具\n";
echo "=====================================\n\n";

// 1. PHP 版本检查
echo "1️⃣  PHP 版本检查...\n";
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.4', '>=')) {
    echo "   ✅ PHP $phpVersion (需要 >= 7.4)\n";
    $checks['php_version'] = true;
} else {
    echo "   ❌ PHP $phpVersion (需要 >= 7.4)\n";
}

// 2. Composer 检查
echo "\n2️⃣  Composer 依赖检查...\n";
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "   ✅ Composer 已安装\n";
    $checks['composer_installed'] = true;
} else {
    echo "   ❌ Composer 未安装，运行: composer install\n";
}

// 3. .env 文件检查
echo "\n3️⃣  环境变量文件检查...\n";
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    echo "   ✅ .env 文件存在\n";
    $checks['env_file_exists'] = true;
    
    // 检查权限
    $perms = substr(sprintf('%o', fileperms($envFile)), -3);
    if ($perms === '600') {
        echo "   ✅ .env 权限正确 ($perms)\n";
        $checks['env_file_readable'] = true;
    } else {
        echo "   ⚠️  .env 权限可能不安全 ($perms)，建议修改为 600\n";
        $checks['env_file_readable'] = false;
    }
    
    // 检查是否被 Git 跟踪
    $output = [];
    $returnCode = 0;
    @exec("git check-ignore " . escapeshellarg($envFile) . " 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "   ✅ .env 已被 .gitignore 忽略\n";
        $checks['env_not_tracked'] = true;
    } else {
        echo "   ⚠️  .env 可能被 Git 跟踪，请检查 .gitignore\n";
        $checks['env_not_tracked'] = false;
    }
} else {
    echo "   ❌ .env 文件不存在\n";
    echo "      运行: cp .env.example .env\n";
}

// 4. vendor 目录检查
echo "\n4️⃣  依赖目录检查...\n";
$vendorDir = __DIR__ . '/../vendor';
if (is_dir($vendorDir)) {
    echo "   ✅ vendor 目录存在\n";
    $checks['vendor_exists'] = true;
    
    if (file_exists($vendorDir . '/autoload.php')) {
        echo "   ✅ autoload.php 存在\n";
        $checks['autoload_exists'] = true;
    }
}

// 5. 配置文件检查
echo "\n5️⃣  配置文件检查...\n";
$configFiles = [
    __DIR__ . '/../config/routes.php',
    __DIR__ . '/../config/settings.php',
    __DIR__ . '/../config/EnvLoader.php',
];

$allConfigExist = true;
foreach ($configFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ " . basename($file) . "\n";
    } else {
        echo "   ❌ " . basename($file) . " 缺失\n";
        $allConfigExist = false;
    }
}
$checks['config_files_exist'] = $allConfigExist;

// 6. Controllers 检查
echo "\n6️⃣  控制器检查...\n";
$controllers = [
    __DIR__ . '/../src/Controllers/BaseController.php',
    __DIR__ . '/../src/Controllers/JenkinsController.php',
    __DIR__ . '/../src/Controllers/HarborController.php',
    __DIR__ . '/../src/Controllers/GitController.php',
];

$allControllersExist = true;
foreach ($controllers as $file) {
    if (file_exists($file)) {
        echo "   ✅ " . basename($file) . "\n";
    } else {
        echo "   ❌ " . basename($file) . " 缺失\n";
        $allControllersExist = false;
    }
}
$checks['controllers_exist'] = $allControllersExist;

// 7. Services 检查
echo "\n7️⃣  服务检查...\n";
$services = [
    __DIR__ . '/../src/Services/JenkinsService.php',
    __DIR__ . '/../src/Services/HarborService.php',
    __DIR__ . '/../src/Services/GitService.php',
];

$allServicesExist = true;
foreach ($services as $file) {
    if (file_exists($file)) {
        echo "   ✅ " . basename($file) . "\n";
    } else {
        echo "   ❌ " . basename($file) . " 缺失\n";
        $allServicesExist = false;
    }
}
$checks['services_exist'] = $allServicesExist;

// 8. 环境变量验证
echo "\n8️⃣  环境变量配置检查...\n";
if ($checks['env_file_exists']) {
    $envContent = file_get_contents($envFile);
    $requiredVars = [
        'JENKINS_BASE_URL',
        'JENKINS_USER',
        'JENKINS_TOKEN',
        'GIT_BASE_URL',
        'GIT_TOKEN',
        'HARBOR_BASE_URL',
        'HARBOR_USER',
        'HARBOR_PASSWORD',
    ];
    
    $missingVars = [];
    foreach ($requiredVars as $var) {
        if (strpos($envContent, $var . '=') !== false) {
            $value = trim(getenv($var));
            // 检查值是否为空（只有键存在）
            if (empty($value) || strpos($value, 'your_') !== false) {
                echo "   ⚠️  $var 未配置实际值\n";
            } else {
                echo "   ✅ $var 已配置\n";
            }
        } else {
            $missingVars[] = $var;
        }
    }
    
    if (!empty($missingVars)) {
        echo "   ❌ 缺失配置: " . implode(', ', $missingVars) . "\n";
    }
}

// 总结
echo "\n=====================================\n";
echo "📋 检查总结\n";
echo "=====================================\n\n";

$passedCount = count(array_filter($checks, fn($v) => $v === true));
$totalCount = count($checks);

if ($passedCount === $totalCount) {
    echo "✅ 所有检查通过！可以部署\n";
    echo "\n部署命令:\n";
    echo "   php -S 0.0.0.0:8000 -t public/\n";
    exit(0);
} else {
    echo "⚠️  有 " . ($totalCount - $passedCount) . " 项检查未通过\n";
    echo "\n请修复上述问题后重新运行检查\n";
    exit(1);
}
