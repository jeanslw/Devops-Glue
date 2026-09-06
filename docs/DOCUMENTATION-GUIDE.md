# Devops-Glue Documentation Contributor Guide

Every user-facing document in this repository is maintained **in a Chinese–English pair**: each topic has two language versions whose filenames map one-to-one. If you change one, you must update the other **in the same commit** — otherwise the change will not be merged.

## Finding the Documents

- The entry points are the two root READMEs:
  - [English README](../README.md) lists the English docs only
  - [Chinese README](../README_ZH-CN.md) lists the Chinese docs only
- Whenever you add or rename a document, update the "Documentation / 文档" table in **both** READMEs.

## Chinese–English Document Mapping

| Chinese | English | Content |
|---|---|---|
| 管理员配置手册.md | ADMIN_MANUAL.md | Installation, configuration and operations (the single "from zero to usable" entry) |
| API_文档.md | API_Documents.md | API endpoints, request / response formats |
| 单点登录.md | Single-Sign-On.md | OAuth2 / OIDC integration |
| 更新日志.md | CHANGELOG.md | Release notes |
| 架构全景图.md | ARCHITECTURE.md | Data flow, component relationships, deployment patterns |
| 技术文档.md | Technical-Guide.md | Architecture & DB design, troubleshooting |
| 常见问题.md | FAQ.md | Common issues and troubleshooting |
| 对接服务版本兼容性说明.md | Integrated-Service-Version-Compatibility.md | Supported versions of integrated services |
| 文档贡献指南.md | DOCUMENTATION-GUIDE.md | This document |

## Which Documents to Update When

Use the following as a rule of thumb for a feature change (e.g. a new setting or a new API):

| Type of change | Documents to update |
|---|---|
| New / changed environment variable or setting | Admin Manual, both the topic section and "Appendix A: Environment Variables Reference" |
| New / changed API endpoint | API Documents for the module, plus the public-endpoint overview table if affected |
| Releasing a new version | Changelog (更新日志 / CHANGELOG.md) + version title and Release badge in both READMEs |
| Behavior changes (auth, error codes, defaults) | Re-check every affected description and code example |
| Documentation-process changes | This guide + the README documentation tables |

## Conventions

1. **Paired commits**: Chinese and English documents ship in the same commit; neither may get ahead of the other.
2. **Matching version titles**: the `vX.Y.Z` in the README and Admin Manual titles must match the version being prepared; bump them when the release is cut.
3. **English style**: write natural, idiomatic English — never translate Chinese word-for-word. When unsure about a term, check established usage (product terms, existing precedent in these docs) instead of inventing a rendering.
4. **Do not translate**: environment variables (`LDAP_ENABLED`), permission keys (`ci.manage`), API paths (`/api/rbac/users`), field names (`provider_uid`), role names, and table names stay verbatim in both languages.
5. **Cross-references**: use relative file links (e.g. `[ADMIN_MANUAL.md](ADMIN_MANUAL.md)`). When renaming a file, search the whole repository for the old filename and update every reference to avoid dead links.
6. **Anchor links**: keep ToC anchors (`#xxx`) in sync with the target headings; when adding headings, follow the anchor style already used in that ToC.
7. **Examples are documentation**: request / response samples must match the real implementation and be updated whenever the behavior changes.
8. Small edits (typo fixes, one-line clarifications) are held to the same standard: update both languages.

## Pre-Commit Checklist

- [ ] Both the Chinese and English documents updated and consistent
- [ ] Documentation tables (README_ZH-CN.md / README.md) and the filename mapping kept in sync
- [ ] Every new link resolves (correct relative path, target file exists)
- [ ] Environment variables, permission keys, and API paths spelled exactly as implemented
- [ ] If this is a release: changelog, version titles, and Release badge versions are in place

## Submitting

- Complete the doc and code changes on a feature branch, and describe the surface area in the commit message (e.g. `docs: add LDAP login section (EN + CN)`).
- Documentation is reviewed with the same rigor as code: factual accuracy, wording, and EN–CN consistency.
