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
 *
 * 语义约定（4xx 属配置错误、透传上游状态码；只有连接失败 / 5xx 才是故障）：
 *   - 上游 404        → 404（Job / 项目不存在，属配置错误，不是故障）
 *   - 上游 401 / 403  → 401 / 403（凭证无效或权限不足，同样是配置错误。
 *                        归入「服务不可用」会让运维去查 CI 是否宕机，而实际是凭据配错了）
 *   - 连接失败 / 5xx  → 502（CI 服务不可达）—— 与 Jenkins 原有约定一致
 *   - 其余（含本地 DB 故障、解析失败）→ 500，保留原始 message
 *
 * 注：ApiException 继承自 RuntimeException，因此 API token 调用路径（AuthMiddleware）
 * 必须先判 ApiException 再判 RuntimeException，否则上述 404/500 会被一律压成 502。
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
        if ($e instanceof ClientException) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 404) {
                throw new ApiException("{$label} 资源不存在: " . $e->getMessage(), 404);
            }
            if ($status === 401 || $status === 403) {
                // 凭证/权限问题跟 404 同属配置错误，透传上游状态码，不报成「服务不可用」。
                // 也不拼接 $e->getMessage()：凭证类异常消息可能带请求 URL。
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
