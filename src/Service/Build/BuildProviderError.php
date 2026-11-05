<?php

namespace App\Service\Build;

use App\Exceptions\ApiException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

/**
 * 拉取式 CI Provider 的异常映射 —— 把底层异常转成带 HTTP 语义的 ApiException。
 *
 * 这一层存在的理由：全局错误处理器（ErrorHandlerFactory）只对 ApiException 保留
 * message 与状态码，其余异常一律吞成「500 + 通用文案」。因此 provider 直接抛
 * RuntimeException 时，CI 服务器宕机（连接超时 / 上游 5xx）传到前端只剩一句
 * 「服务器内部错误」：既与程序缺陷无法区分，也无法让前端按「服务不可达」引导用户。
 */
final class BuildProviderError
{
    /**
     * @param \Exception $e     provider 捕获到的原始异常
     * @param string     $label CI 服务名（Jenkins / GitLab CI / Gitea CI / GitHub Actions / 自定义推送）
     * @return never
     * @throws ApiException 始终抛出
     */
    public static function throwFor(\Exception $e, string $label): never
    {
        if ($e instanceof ApiException) {
            throw $e;
        }
        if ($e instanceof ClientException) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 404) {
                throw new ApiException("{$label} 资源不存在: " . $e->getMessage(), 404);
            }
            if ($status === 401 || $status === 403) {
                throw new ApiException(
                    "{$label} 凭证无效或权限不足（HTTP {$status}），请检查后台平台连接里的凭据",
                    $status
                );
            }
        }
        if ($e instanceof ConnectException || $e instanceof ServerException) {
            throw new ApiException("{$label} 服务不可达: " . $e->getMessage(), 502);
        }
        throw new ApiException('CI 服务不可用: ' . $e->getMessage(), 500);
    }
}
