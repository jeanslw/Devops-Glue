<?php
namespace App\Service\Git;

use App\Exceptions\ApiException;
use App\Service\Logger;

class ProviderRegistry
{
    /** @var array<string, array{matcher: callable, factory: callable}> */
    private array $providers = [];

    private ?Logger $logger = null;

    /**
     * 注册一个 Git 平台 Provider
     *
     * @param string   $name    平台唯一标识（如 'gitlab', 'gitea'）
     * @param callable $matcher URL 匹配函数，签名 fn(string $url): bool
     * @param callable $factory Provider 工厂函数，签名 fn(): GitProviderInterface
     */
    public function register(string $name, callable $matcher, callable $factory): void
    {
        $this->providers[$name] = [
            'matcher' => $matcher,
            'factory' => $factory,
        ];
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 根据 Git remote URL 检测所属平台
     *
     * @param string $url Git remote URL
     * @return string 平台名称
     * @throws ApiException 无法识别时抛出
     */
    public function detect(string $url): string
    {
        foreach ($this->providers as $name => $def) {
            try {
                if (($def['matcher'])($url)) {
                    $this->logger?->debug("Git 平台检测: {$url} → {$name}");
                    return $name;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning("平台 {$name} 的匹配器抛出异常", [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $supported = $this->getRegisteredNames();
        $names = implode(', ', $supported);
        throw new ApiException(
            "无法识别 Git 平台: {$url}，当前支持: {$names}。" .
            "如需添加自定义平台，请在 config/settings.php 的 git.custom_providers 中配置。",
            400
        );
    }

    /**
     * 根据平台名称创建 Provider 实例
     *
     * @param string $name 平台名称
     * @return GitProviderInterface
     * @throws ApiException 平台未注册时抛出
     */
    public function create(string $name): GitProviderInterface
    {
        if (!isset($this->providers[$name])) {
            $supported = $this->getRegisteredNames();
            $names = implode(', ', $supported);
            throw new ApiException("不支持的 Git 平台: {$name}，当前支持: {$names}", 400);
        }

        try {
            return ($this->providers[$name]['factory'])();
        } catch (\Throwable $e) {
            $this->logger?->error("创建 Git Provider 失败: {$name}", ['error' => $e->getMessage()]);
            throw new ApiException("Git 平台 {$name} 初始化失败: " . $e->getMessage(), 500);
        }
    }

    /**
     * 判断平台是否已注册
     */
    public function isRegistered(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * 返回所有已注册的平台名称列表
     * @return string[]
     */
    public function getRegisteredNames(): array
    {
        return array_keys($this->providers);
    }
}
