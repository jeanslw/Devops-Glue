# 贡献指南

首先，感谢你考虑为 Devops-Glue 做出贡献！这份指南旨在帮助你顺利地参与项目，无论你是报告 Bug、提出新功能，还是提交代码。

## 核心原则

在我们开始之前，请了解这个项目的两个核心理念：

1. **Devops-Glue 是一个"胶水层"**：它旨在连接和增强现有的 CI/CD 工具（Jenkins、GitLab CI、Harbor 等），而不是取代它们。任何贡献都应遵循这一原则，保持系统的**轻量、非侵入**和**可扩展**。
2. **Custom_Push 是"连接万物"的接口**：在设计任何与 CI 源对接的功能时，应优先考虑通过 `Custom_Push` 模式（推送式、标准 API）来实现，以保持与拉取式（Pull-based）CI 的正交性和系统的开放性。

---

## 如何报告 Bug

如果发现 Bug，请在 GitHub Issues 中新建一个 Issue，并尽量包含以下信息：

- **简要描述**：清晰简洁地描述问题。
- **复现步骤**：详细的操作步骤，包括使用的版本和配置。
- **预期行为**：你希望看到什么结果。
- **实际行为**：实际发生了什么，如有错误截图或日志请一并贴上。
- **环境信息**：
    - Devops-Glue 版本（或 commit hash）
    - PHP 版本
    - 数据库类型（MySQL / SQLite）及版本
    - 相关的 CI 工具（Jenkins / GitLab CI）及版本
    - Harbor 版本

## 如何提出新功能或改进

在提出新功能前，建议先搜索已有 Issue，避免重复。提交新功能建议时，请说明：

- **这个功能解决了什么痛点？** 描述你在实际场景中遇到的问题。
- **你的建议方案是什么？** 尽量具体，如果可能，描述你设想的 API 或界面交互方式。
- **这个功能是否与项目的"胶水层"定位一致？** 说明它如何连接或增强现有工具，而不是重复造轮子。

## 代码贡献流程

### 1. 沟通先行
如果你打算实现一个较大的功能或重构，请**先在 Issue 中讨论**，确保你的方向与项目维护者一致，避免投入大量精力后方案被拒绝。

### 2. 准备开发环境
- 确保已安装 **PHP 8.1+** 和 **Composer**。
- Fork 本仓库，并将你的 Fork 克隆到本地。
- 在项目根目录运行 `composer install` 安装依赖。
- 复制 `.env.example` 为 `.env`，并根据本地环境修改配置（数据库等）。
- 使用 `php -S 0.0.0.0:8080 -t public/` 启动内置服务器进行开发测试。
- （可选）如需测试 CD 组件，请参考 `Devops_CD` 仓库的文档。

### 3. 编写代码
- **代码风格**：请遵循 **PSR-12** 编码规范。建议使用 PHP_CodeSniffer 等工具进行检查。
- **测试**：为新功能或修复编写相应的单元测试（如适用）。确保现有测试全部通过。
- **文档**：你的贡献必须包含或更新相关文档。这包括：
    - 在 `README.md` 或 `docs/` 下更新使用说明。
    - 如果是新的 API 接口，请更新 Swagger/OpenAPI 文档。
    - 如果引入了新的配置项，请更新 `.env.example` 和管理员手册。

### 4. 提交代码（Commit Message）

请使用清晰、描述性的提交信息。
强烈建议遵循 **Conventional Commits** 规范：

    <类型>(可选范围): <简短描述>

    <可选的详细描述>

- **常用类型**：
  - `feat`: 新功能
  - `fix`: Bug 修复
  - `docs`: 文档变更
  - `style`: 代码格式（不影响功能）
  - `refactor`: 重构（不是新功能也不是修 Bug）
  - `test`: 增加或修改测试
  - `chore`: 构建过程或辅助工具的变动

**示例**：

    feat(custom_push): 增加对自定义 CI 构建状态的校验

    现在 Custom_Push 接口会校验 project_name 和 tag 格式，并在不匹配时返回明确的错误提示。
	
### 5. 版本管理

Devops-Glue 遵循语义化版本规范 (SemVer)。每个版本必须打上唯一的 Git Tag。

#### 版本格式

v<主版本>.<次版本>.<补丁>[-<预发布标识>]

- 主版本：破坏性变更
- 次版本：新功能（向后兼容）
- 补丁：Bug 修复（向后兼容）
- 预发布：-alpha, -beta, -rc, -dev, -preview

#### 递增规则

| 变更类型 | 递增 | 示例 |
|---------|------|------|
| Bug 修复 | 补丁 | v2.7.0 -> v2.7.1 |
| 新功能 | 次版本 | v2.7.0 -> v2.8.0 |
| 破坏性变更 | 主版本 | v2.7.0 -> v3.0.0 |
| 预发布 | 追加后缀 | v2.8.0 -> v2.8.0-alpha |

#### 发布步骤

1. 更新 config/app.php 中的 APP_VERSION
2. 在 docs/CHANGELOG.md 中新增条目：
   ## vX.X.X (YYYY-MM-DD)
   - 变更描述
3. 提交变更
4. 创建并推送 Tag：
   git tag vX.X.X
   git push origin vX.X.X
5. GitHub Actions 自动从 CHANGELOG.md 创建 Release

#### 规则

- 一个版本，一个 Tag（禁止复用 Tag）
- Tag 必须与 APP_VERSION 完全一致
- 打 Tag 前必须在 CHANGELOG.md 中有对应条目
- 禁止强制推送已存在的 Tag

#### 示例

git add config/app.php docs/CHANGELOG.md
git commit -m "chore(release): 版本号升至 v2.7.1"
git tag v2.7.1
git push origin main
git push origin v2.7.1

## 6. 发起 Pull Request (PR)

- 确保你的 PR 基于最新的 `main` 分支。
- 在 PR 描述中，清晰说明解决了什么问题，并关联相关的 Issue（如 `Closes #123`）。
- 确保 CI（如果已配置）检查通过，且分支没有冲突。

## 行为准则

本项目的参与者应遵守 [贡献者公约](https://www.contributor-covenant.org/zh-cn/version/2/0/code_of_conduct/)。我们期望所有互动都是开放、包容和尊重的。

## 获取帮助

如果你在贡献过程中有任何疑问，欢迎在 Issue 中提问，或通过邮件联系维护者（jeanslw@qq.com）。

再次感谢你的贡献！🎉