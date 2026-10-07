<?php

declare(strict_types=1);

namespace DevopsGluePHPStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * PDO::prepare()/query() 动态返回类型扩展。
 *
 * 全局不变量：本项目所有 PDO 实例（src/Service/Database.php::createPdo()，
 * 含 CLI / bin / 测试）都设置 PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION，
 * 故 prepare()/query() 失败抛 PDOException、成功返回 PDOStatement，不会 false。
 *
 * PHPStan 的 stub 机制不允许把内置 tentative 返回类型 PDOStatement|false
 * 直接窄化为 PDOStatement（会被保护性地加回 false），因此用本扩展显式声明。
 *
 * 若未来引入非异常模式的 PDO，必须删除或调整本扩展。
 */
final class PdoPrepareReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return 'PDO';
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['prepare', 'query'], true);
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): ?Type {
        return new ObjectType('PDOStatement');
    }
}
