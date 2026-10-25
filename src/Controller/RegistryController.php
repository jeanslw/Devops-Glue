<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\I18nService;
use App\Service\Registry\RegistryProviderInterface;

/**
 * 镜像仓库通用列表接口（/api/registry）。
 *
 * 与 /api/harbor 的区别：本模块按全局镜像仓库类型（ci_app_settings：harbor | registry）
 *
 * 状态码约定（与 /api/harbor/* 保持一致，避免同一端点在两种仓库类型下状态码不同）：
 *   - 200：项目/仓库名数组；
 *   - 400：项目名/仓库短名非法（含 `..`、空段等，见 isValidName；/api/harbor 维持既有契约不做该校验）；
 *   - 403：当前用户/Token 无 ci.build-records.pull；
 *   - 409：镜像仓库未配置 url（提供方为 null）；
 *   - 500：提供方业务错误（项目不存在、/v2/_catalog 未开启、认证失败等）；
 *   - 502：连接级失败（提供方抛 RuntimeException，由 AuthMiddleware 统一映射）。
 */
class RegistryController extends BaseController
{
    public function __construct(I18nService $i18n, private ?RegistryProviderInterface $registryProvider = null)
    {
        parent::__construct($i18n);
    }

    /**
     * GET/POST /api/registry/projects —— 当前镜像仓库的项目列表
     *
     * @param array<string,string> $args 路由参数
     */
    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }
        if ($this->registryProvider === null) {
            return $this->jsonError($response, $this->__('build.registry_not_configured'), 409);
        }

        return $this->handleResult($response, $this->registryProvider->getProjects(), $request);
    }

    /**
     * GET/POST /api/registry/{project}/repositories —— 指定项目下的仓库列表
     *
     * 返回值为去掉项目前缀的仓库短名（与 /api/harbor 同口径）。
     *
     * @param array<string,string> $args 路由参数
     */
    public function getRepositoriesList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }
        if ($this->registryProvider === null) {
            return $this->jsonError($response, $this->__('build.registry_not_configured'), 409);
        }

        $project = $args['project'] ?? '';
        if (!$this->isValidName($project, false)) {
            return $this->jsonError($response, $this->__('build.registry_name_invalid', ['name' => $project]), 400);
        }

        return $this->handleResult($response, $this->registryProvider->getRepositories($project), $request);
    }

    /**
     * GET/POST /api/registry/{project}/repositories/{repository}/tags —— 指定仓库的 tag 列表
     *
     * 与 /api/harbor 同口径：repository 为去项目前缀的短名。registry:2 的多段仓库名
     * （如 mycode/a/b 的短名 a/b）原样拼回完整名查询；短名含 `/` 时需双重编码为 %252F
     * （与 Harbor tags 端点同一约定，见 Bootstrap 对 REQUEST_URI 的 %2F 还原）。
     *
     * 编码→取值链路（已用真实分派实测，勿再误判为「$args 里是字面量 a%2Fb」）：
     *   1) Bootstrap 只把 `%2F` 还原成 `/`（`str_replace`），`%252F` 原样保留；
     *   2) Slim\Routing\RouteResolver 对整条路径 rawurldecode 一次：`%252F` → `%2F`（仍是单段，能匹配 `{repository}`）；
     *   3) FastRoute 不再解码，捕获值是 `a%2Fb`；
     *   4) RoutingMiddleware 用默认 urlDecode=true 调 RoutingResults::getRouteArguments()，
     *      对捕获值再 rawurldecode 一次：`a%2Fb` → `a/b`。
     * 所以本方法拿到的 $repository 已经是还原后的短名（`a/b`），直接拼 `project/repository` 即可；
     * 提供方侧（DockerRegistryProvider::tagsPath）按 `/` 分段 rawurlencode，最终请求 `/v2/mycode/a/b/tags/list`。
     * 回归测试：tests/Unit/RegistryTagsRoutingDecodeTest.php。
     *
     * @param array<string,string> $args 路由参数
     */
    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }
        if ($this->registryProvider === null) {
            return $this->jsonError($response, $this->__('build.registry_not_configured'), 409);
        }

        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        if (!$this->isValidName($project, false)) {
            return $this->jsonError($response, $this->__('build.registry_name_invalid', ['name' => $project]), 400);
        }
        if (!$this->isValidName($repository, true)) {
            return $this->jsonError($response, $this->__('build.registry_name_invalid', ['name' => $repository]), 400);
        }

        return $this->handleResult($response, $this->registryProvider->getTags($project . '/' . $repository), $request);
    }

    // ---------- 入参校验 ----------
    /**
     * 名称合法性：逐段校验，段首必须是字母或数字，段内允许 `A-Za-z0-9._-`。
     *
     * 拒绝空段、`.`、`..` 以及任何非名称字符。`..` 会被原样拼进提供方 URL
     * （`v2/{name}/tags/list`、Harbor 的 `projects/{project}/...`），可能被仓库或反向代理
     * 归一成上层路径，从而访问到非预期接口；这里 fail-closed 返回 400。
     * 多段仓库短名（`a/b`）逐段校验通过，不会拦掉本版本支持的「两段以上仓库路径」。
     *
     * @param bool $allowSlash 是否允许 `/` 分段（仓库短名允许；项目名只允许单段）
     */
    private function isValidName(string $name, bool $allowSlash): bool
    {
        if ($name === '' || (!$allowSlash && str_contains($name, '/'))) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $segment)) {
                return false;
            }
        }

        return true;
    }

    // ---------- 统一响应处理 ----------
    /**
     * @param array<int|string,mixed> $data 提供方返回结果（键可能含整数）
     */
    private function handleResult(Response $response, array $data, Request $request): Response
    {
        if (isset($data['error'])) {
            return $this->jsonError($response, $data['error'], 500);
        }
        return $this->output($response, $data, $request);
    }
}
