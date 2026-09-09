# v2.8.0 数据模型迁移说明

## 这次改了什么

v2.8.0 引入 `ci_pipeline_artifacts` 作为 Pipeline → Primary Artifact 的**唯一**规范事实表，并**删除**旧的 `ci_pipeline_tags` 表。当前阶段仍保持 **1 Pipeline : 1 Primary Artifact**，不会因为这次升级强行引入 1:N。

Pipeline 的规范身份统一为：

```text
(provider, project_id, pipeline_iid)
```

其中 `project_id` 继续遵循现有 provider 归一化规则：

- Jenkins：Job/path
- GitLab CI：数字 `project_id`
- custom_push / 其他 push provider：`job_name`

`ci_pipeline_tags` 表**已删除**。所有读取与写入统一走 `ci_pipeline_artifacts`，不再维护兼容投影。

## 升级方式

默认 `DB_AUTO_MIGRATE=true` 时，应用启动会执行「先迁移、后删除」（migrate-then-drop）：

1. 创建 `ci_pipeline_artifacts`（不存在才创建）；
2. 若存在遗留 `ci_pipeline_tags` 表，将存量数据迁移到 artifact 表；
3. 迁移按 `created_at DESC` 处理同一 canonical identity 的冲突，优先保留较新的旧记录；
4. 迁移过程使用事务，失败不会标记 schema 已完成，下一次启动会继续尝试；
5. 迁移完成后（或遗留表本就为空 / 全新安装无遗留表）删除 `ci_pipeline_tags`。

迁移是幂等的。已经存在 canonical artifact 数据时不会重复扫描整个旧表。

## 部署侧兼容

部署系统（Devops_CD）**不直读** `ci_pipeline_tags`，CI 数据（映射 / tag / pipeline / 构建）全部经 CI HTTP API 获取。请将 Devops_CD 升级到 v1.5.1+（其启动共享库校验已改为 `ci_pipeline_artifacts`）。

`/api/build/{path}/tag`、`/api/build/{path}/tags`、`/api/build/projects` 等读取接口统一走 canonical artifact 数据。

## 事件顺序

Artifact 记录带有 `source_updated_at`。当新事件比已保存事实更旧时，canonical artifact 不会被旧事件覆盖；custom_push 同样不会用更早的 `finished_at` 覆盖较新的构建事实。

## 回滚原则

代码回滚前不要删除 `ci_pipeline_artifacts`。若需回滚到 v2.7.x，须依据升级前的备份恢复 `ci_pipeline_tags` 表——本版本已删除该表，没有自动回滚路径。
