<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Exceptions\ApiException;

/**
 * 统一映射层 —— ci_job_git_map 的全部读/写/过滤，以及构建 provider 启用状态判定集中于此。
 *
 * 从 AppConfig 迁入的持久化职责：allMaps/saveMaps/deleteMap（原 getJobGitMap 等）。
 * 构建模式/custom_push 等 DB 开关委托 {@see AppSettingRepository}。
 */
class MappingManager
{
    public function __construct(
        private AppSettingRepository $appSettings,
        private \PDO $pdo
    ) {
    }

    /**
     * 当前启用的拉取式构建 provider 集合（数据库为唯一来源）
     *
     * @return list<string>
     */
    public function activeBuildProviders(): array
    {
        return $this->appSettings->getBuildModes();
    }

    /** 是否启用了某类 Provider */
    public function hasJenkins(): bool
    {
        return in_array(AppConfig::PROVIDER_JENKINS, $this->activeBuildProviders(), true);
    }

    public function hasGitlabCi(): bool
    {
        return in_array(AppConfig::PROVIDER_GITLAB_CI, $this->activeBuildProviders(), true);
    }

    public function hasGiteaCi(): bool
    {
        return in_array(AppConfig::PROVIDER_GITEA_CI, $this->activeBuildProviders(), true);
    }

    public function hasGithubActions(): bool
    {
        return in_array(AppConfig::PROVIDER_GITHUB_ACTIONS, $this->activeBuildProviders(), true);
    }

    public function hasCustomPush(): bool
    {
        return $this->appSettings->getCustomPushEnabled();
    }

    // ── 持久化（原 AppConfig::getJobGitMap/saveJobGitMap/deleteJobGitMap） ──

    /**
     * 全量映射（含 inactive/pending），按 job_name 排序
     *
     * @return array<int,array<string,mixed>>
     */
    public function allMaps(): array
    {
        return $this->pdo
            ->query("SELECT * FROM " . AppConfig::TABLE_JOB_GIT_MAP . " ORDER BY job_name")
            ->fetchAll();
    }

    /**
     * 单行写入：只动这一条映射，不触碰其他行。
     *
     * 管理页的新增/编辑走这里，避免「读全表 → 改一条 → 全量回写」在并发下互相覆盖。
     *
     * @param array<string,mixed> $row
     */
    public function saveMap(array $row): void
    {
        if (empty($row['job_name'])) {
            return;
        }
        $this->pdo
            ->prepare($this->upsertSql())
            ->execute($this->rowValues($row));
    }

    /**
     * 批量覆盖保存：upsert 传入行，并删除 DB 中存在但本次未提交的行。
     *
     * 只用于批量变更（自动发现、provider 关闭时批量降 pending）；单行变更请用 {@see saveMap()}。
     *
     * @param array<int,array<string,mixed>> $data
     */
    public function saveMaps(array $data): void
    {
        $rows = array_values(array_filter($data, fn($row) => !empty($row['job_name'])));
        if ($rows === []) {
            // 空输入一律拒绝：早期实现会据此清空整表
            throw new \InvalidArgumentException('saveMaps: 空数据不做全表清理');
        }

        // 调用方可能已在事务里（如平台接入更新），只在自己开启时才提交/回滚
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare($this->upsertSql());
            foreach ($rows as $row) {
                $stmt->execute($this->rowValues($row));
            }

            $names        = array_column($rows, 'job_name');
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $this->pdo
                ->prepare("DELETE FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE job_name NOT IN ({$placeholders})")
                ->execute($names);

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                try {
                    $this->pdo->rollBack();
                } catch (\Throwable $rollbackError) {
                    // 事务已被数据库自行回滚，忽略
                }
            }
            throw $e;
        }
    }

    private function upsertSql(): string
    {
        $cols = 'job_name,git_platform,build_provider,git_remote,project_id,web_url,'
            . 'current_path,harbor_repository,api_version,status';
        return Database::sqlUpsert(AppConfig::TABLE_JOB_GIT_MAP, $cols, '?,?,?,?,?,?,?,?,?,?');
    }

    /**
     * @param array<string,mixed> $row
     * @return list<mixed>
     */
    private function rowValues(array $row): array
    {
        return [
            $row['job_name'],
            $row['git_platform'] ?? null,
            // 不兜底 jenkins：CI 源已不止一种，「未指定」就该落成 NULL 让 requireProvider() 明确拒绝，
            // 而不是悄悄写成 jenkins（会把构建发到用户没选的 CI 上）。
            $row['build_provider'] ?? null,
            $row['git_remote'] ?? null,
            $row['project_id'] ?? null,
            $row['web_url'] ?? null,
            $row['current_path'] ?? null,
            $row['harbor_repository'] ?? null,
            $row['api_version'] ?? null,
            $row['status'] ?? AppConfig::STATUS_ACTIVE,
        ];
    }

    /** 单条删除映射 */
    public function deleteMap(string $jobName): void
    {
        $this->pdo
            ->prepare("DELETE FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE job_name = ?")
            ->execute([$jobName]);
    }

    // ── 全量查询（过滤禁用 + 模式筛选） ──

    /**
     * 返回当前启用集合下活跃的映射条目（custom_push 独立开关，开启时一并保留）
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeMaps(): array
    {
        $maps = $this->allMaps();
        $maps = array_filter($maps, fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE);

        $enabled = $this->activeBuildProviders();
        $cpEnabled = $this->hasCustomPush();

        $maps = array_filter($maps, function ($m) use ($enabled, $cpEnabled) {
            $bp = (string) ($m['build_provider'] ?? '');
            if ($bp === '') {
                // 未指定构建源：保留在活跃集合里，让界面看得到、能去修。
                // 在这里过滤掉的话，这类行会从 jobsList / 项目下拉里凭空消失，用户无从发现，
                // 前端特意保留空值行也就收不到数据。构建时由 requireProvider() 明确拒绝，不做隐式兜底。
                return true;
            }
            if (in_array($bp, $enabled, true)) {
                return true;
            }
            return $cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH;
        });
        return array_values($maps);
    }

    /**
     * 返回当前模式下的 Job 名称列表
     *
     * @return array<int,mixed>
     */
    public function activeJobNames(): array
    {
        return array_map(fn($m) => $m['job_name'], $this->activeMaps());
    }

    /**
     * 返回活跃条目使用的 Git 平台清单
     *
     * @return array<int,string>
     */
    public function usedGitPlatforms(): array
    {
        $platforms = [];
        foreach ($this->activeMaps() as $m) {
            $p = $m['git_platform'] ?? '';
            if ($p && !in_array($p, $platforms)) {
                $platforms[] = $p;
            }
        }
        return $platforms;
    }

    // ── 单项解析 ──

    /**
     * 按项目路径解析 CI 系统 + 项目 ID
     * @return array{provider: string, projectId: string}
     */
    public function resolveProject(string $projectPath): array
    {
        // 防御：入参/存量数据可能带首尾空白（历史脏数据曾导致 Jenkins `job/ foo` 404），
        // 解析前先归一到 canonical 键，与 buildEntry/saveDiscovered 的 trim 写入对齐。
        $projectPath = trim($projectPath);

        $row = $this->resolveMapRow($projectPath);
        if ($row === null) {
            // 未命中映射：历史上无条件兜底 jenkins（unmapped 直连，JenkinsService 按路径拼 URL）。
            // CI 不止一种后，无条件假定 jenkins 会把任意 path 发到 Jenkins（可能误命中同名 Job）。
            // 仅当 jenkins 确实启用时保留该直连能力——此时界面会合并 Jenkins 实时 Job 供直接构建；
            // 否则无从推断来源，明确拒绝，而不是默认发到某个 CI。
            if (!in_array(AppConfig::PROVIDER_JENKINS, $this->activeBuildProviders(), true)) {
                throw new ApiException('未匹配到任何映射，且 Jenkins 未启用（无法直连），无法确定构建源', 400);
            }
            return ['provider' => AppConfig::PROVIDER_JENKINS, 'projectId' => $projectPath];
        }
        return $this->resolveMap($projectPath, $row);
    }

    /**
     * 按项目路径解析命中的映射行 —— canonical 解析的唯一入口。
     *
     * 凡是「按 path 取映射」的调用方都必须走这里，不要各自遍历 allMaps()：
     * 各自循环容易写成「job_name / current_path 任一命中即取」，与这里的优先级不同，
     * 在跨映射撞名（A.current_path === B.job_name）时会命中不同的行，
     * 导致一次请求里 harbor 仓库取自 A、pipeline 身份取自 B 的错配。
     *
     * @return array<string,mixed>|null 命中的映射行；未命中返回 null
     */
    public function resolveMapRow(string $projectPath): ?array
    {
        // 防御：与 resolveProject 一致，先归一到 canonical 键
        $projectPath = trim($projectPath);

        // Pass 1：按 job_name（canonical 主键）精确匹配，遍历全部映射（含 pending/disabled）。
        // job_name 是各 CI 中的唯一标识，不能被其它 provider 的 current_path 别名占用，
        // 否则 gitea_ci 未启用时，路径 jeanslw/Devops_CD（gitea 的 job_name）会落到 jenkins 的
        // current_path 别名上，拼出 job/jeanslw/... 导致 404（历史 bug）。
        // 命中未启用 provider 时也返回该 provider，让调用方走 registry 的未配置或未启用分支。
        foreach ($this->allMaps() as $m) {
            $job = trim((string) ($m['job_name'] ?? ''));
            if ($job !== '' && $job === $projectPath) {
                return $m;
            }
        }

        // Pass 2：current_path 别名兜底（仅 active 映射，供 custom_push 推 git 路径归一化用）。
        foreach ($this->activeMaps() as $m) {
            $cp = trim((string) ($m['current_path'] ?? ''));
            if ($cp !== '' && $cp === $projectPath) {
                return $m;
            }
        }

        return null;
    }

    /**
     * 将命中的映射行解析为 [provider, projectId]。
     * projectId 按 provider 归一化：gitlab_ci 取数字 project_id；jenkins 取 job_name（Job 路径）；
     * 其余（custom_push / gitea_ci / github_actions）取 job_name（缺失时用 current_path），使 job_name 与 current_path 指向同一条映射。
     *
     * @param array<string,mixed> $m
     * @return array{provider: string, projectId: string}
     */
    private function resolveMap(string $projectPath, array $m): array
    {
        $provider = self::requireProvider($m);

        return ['provider' => $provider, 'projectId' => self::canonicalProjectId($m, $projectPath, $provider)];
    }

    /**
     * 取映射行声明的构建源；未指定（NULL / 空串）一律拒绝，不做任何兜底。
     *
     * 不兜底的理由：CI 源已不止一种，从「空」推断不出用户想用哪条。历史上曾兜底成 jenkins，
     * 那时 jenkins 是唯一 CI；现在它只是可选项之一，静默兜底会把构建发到用户没选的 CI 上。
     * 未指定就是配置缺失，明确报错让调用方补全，好过替他猜一个。
     *
     * @param array<string,mixed> $row 命中的映射行（resolveMapRow 的返回）
     */
    public static function requireProvider(array $row): string
    {
        $provider = trim((string) ($row['build_provider'] ?? ''));
        if ($provider === '') {
            throw new ApiException('该映射未指定 build_provider（构建源），无法确定使用哪个 CI 构建，请在映射中指定后再试', 400);
        }
        return $provider;
    }

    /**
     * 把一条映射行归一成调外部 CI API 用的 project_id —— 归一化规则的唯一定义处。
     *
     * 规则（历史行为，勿在别处重写一遍）：
     *   gitlab_ci：数字 project_id（缺失时回落 fallback）
     *   jenkins  ：job_name（Jenkins Job 路径，不是 git 路径），缺失时回落
     *   其余（custom_push / gitea_ci / github_actions）：job_name，current_path 兜底，最后回落
     *
     * @param array<string,mixed> $row 命中的映射行（resolveMapRow 的返回）
     * @param string $fallback job_name / current_path 都缺失时的兜底（通常传原始 path）
     * @param string $provider 已解析的构建源（requireProvider() 的返回值，保证非空）
     */
    public static function canonicalProjectId(array $row, string $fallback, string $provider): string
    {
        $job = trim((string) ($row['job_name'] ?? ''));
        $cp  = trim((string) ($row['current_path'] ?? ''));

        if ($provider === AppConfig::PROVIDER_GITLAB_CI && !empty($row['project_id'])) {
            // GitLab CI：用数字 project_id 调外部 API
            return (string) $row['project_id'];
        }
        if ($provider === AppConfig::PROVIDER_JENKINS) {
            // Jenkins：projectId 必须是 Jenkins Job 路径（job_name），而非 current_path（git 路径）。
            return $job !== '' ? $job : $fallback;
        }
        // custom_push / gitea_ci / github_actions：以 job_name 为规范键，current_path 兜底。
        return $job !== '' ? $job : ($cp !== '' ? $cp : $fallback);
    }

    /**
     * 将项目路径 + pipeline IID 归一成 Glue 内部唯一 Pipeline Identity。
     * 所有持久化层应优先使用此 identity，而不是自行拼接 provider/project key。
     */
    public function pipelineIdentity(string $projectPath, int $pipelineIid): PipelineIdentity
    {
        $resolved = $this->resolveProject($projectPath);
        return new PipelineIdentity($resolved['provider'], $resolved['projectId'], $pipelineIid);
    }
}
