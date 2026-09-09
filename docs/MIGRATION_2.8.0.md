# v2.8.0 Data Model Migration Notes

## What changed

v2.8.0 introduces `ci_pipeline_artifacts` as the **single** canonical source of truth for Pipeline → Primary Artifact, and **removes** the legacy `ci_pipeline_tags` table. The current stage still keeps **1 Pipeline : 1 Primary Artifact**; this upgrade does not force a 1:N relationship.

The canonical Pipeline identity is unified as:

```text
(provider, project_id, pipeline_iid)
```

`project_id` continues to follow the existing provider normalization rules:

- Jenkins: Job/path
- GitLab CI: numeric `project_id`
- custom_push / other push providers: `job_name`

The `ci_pipeline_tags` table has been **removed**. All reads and writes go through `ci_pipeline_artifacts`; the compatibility projection is no longer maintained.

## Upgrade path

With the default `DB_AUTO_MIGRATE=true`, app startup runs migrate-then-drop:

1. Create `ci_pipeline_artifacts` (only if missing);
2. If a legacy `ci_pipeline_tags` table exists, migrate its rows to the artifact table;
3. Migration processes conflicts on the same canonical identity by `created_at DESC`, keeping the newer legacy record;
4. Migration runs in a transaction — failure does not mark the schema complete, and the next startup retries;
5. Once migration completes (or the legacy table is empty / a fresh install has no legacy table), `ci_pipeline_tags` is dropped.

Migration is idempotent. When canonical artifact data already exists, the entire legacy table is not re-scanned.

## Deploy-side compatibility

The deployment system (Devops_CD) does **not** read `ci_pipeline_tags` directly — all CI data (mappings / tags / pipelines / builds) comes through the CI HTTP API. Upgrade Devops_CD to v1.5.1+ (its shared-library startup check now targets `ci_pipeline_artifacts`).

Read endpoints such as `/api/build/{path}/tag`, `/api/build/{path}/tags`, and `/api/build/projects` all read canonical artifact data.

## Event ordering

Artifact records carry `source_updated_at`. When a new event is older than the already-stored fact, the canonical artifact is not overwritten by the stale event; custom_push likewise does not overwrite a newer build fact with an earlier `finished_at`.

## Rollback principle

Do not delete `ci_pipeline_artifacts` before rolling back code. To roll back to v2.7.x, restore the `ci_pipeline_tags` table from the pre-upgrade backup — this version has dropped that table and offers no automatic rollback path.
