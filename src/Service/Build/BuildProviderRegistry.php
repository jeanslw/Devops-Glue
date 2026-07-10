<?php
namespace App\Service\Build;

use App\Exceptions\ApiException;
use App\Service\Logger;

class BuildProviderRegistry
{
    /** @var array<string, callable> */
    private array $factories = [];
    private ?Logger $logger = null;

    public function setLogger(Logger $logger): void { $this->logger = $logger; }

    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function create(string $name): BuildProviderInterface
    {
        if (!isset($this->factories[$name])) {
            $names = implode(', ', array_keys($this->factories));
            throw new ApiException("不支持 Build 系统: {$name}（已注册: {$names}）", 400);
        }
        try {
            return ($this->factories[$name])();
        } catch (\Throwable $e) {
            $this->logger?->error("创建 Build provider [{$name}] 失败", ['error' => $e->getMessage()]);
            throw new ApiException("Build 系统 [{$name}] 初始化失败: " . $e->getMessage(), 500);
        }
    }

    public function isRegistered(string $name): bool { return isset($this->factories[$name]); }

    /** @return string[] */
    public function getRegisteredNames(): array { return array_keys($this->factories); }
}
