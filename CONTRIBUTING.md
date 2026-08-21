# Contributing Guide

First of all, thank you for considering contributing to Devops-Glue! This guide is designed to help you get involved smoothly, whether you're reporting a bug, proposing a new feature, or submitting code.

## Core Principles

Before we start, please understand the two core philosophies of this project:

1. **Devops-Glue is a "glue layer"**: It's designed to connect and enhance existing CI/CD tools (Jenkins, GitLab CI, Harbor, etc.), not to replace them. Any contribution should follow this principle, keeping the system **lightweight, non-invasive**, and **extensible**.
2. **Custom_Push is the "connect everything" interface**: When designing any feature that interfaces with CI sources, prioritize implementing it through the `Custom_Push` model (push-based, standard API) to maintain orthogonality with pull-based CI and the openness of the system.

---

## How to Report a Bug

If you find a bug, please open a new Issue on GitHub Issues and include the following information as much as possible:

- **Short description**: A clear and concise description of the problem.
- **Steps to reproduce**: Detailed steps, including the version and configuration used.
- **Expected behavior**: What you expected to see.
- **Actual behavior**: What actually happened, including error screenshots or logs if available.
- **Environment information**:
    - Devops-Glue version (or commit hash)
    - PHP version
    - Database type (MySQL / SQLite) and version
    - Relevant CI tool (Jenkins / GitLab CI) and version
    - Harbor version

## How to Propose a New Feature or Improvement

Before proposing a new feature, it's recommended to search existing Issues to avoid duplication. When submitting a feature suggestion, please explain:

- **What pain point does this solve?** Describe the problem you're facing in real scenarios.
- **What is your proposed solution?** Be as specific as possible. If you can, describe the API or UI interaction you envision.
- **Is this feature aligned with the project's "glue layer" positioning?** Explain how it connects or enhances existing tools, rather than reinventing the wheel.

## Code Contribution Workflow

### 1. Communication First
If you plan to implement a major feature or refactor, please **discuss it in an Issue first** to ensure your direction aligns with the maintainer's vision. This will help avoid your efforts being rejected.

### 2. Set Up Development Environment
- Ensure you have **PHP 8.1+** and **Composer** installed.
- Fork this repository and clone your fork locally.
- Run `composer install` in the project root to install dependencies.
- Copy `.env.example` to `.env` and modify the configuration (database, etc.) according to your local environment.
- Use `php -S 0.0.0.0:8080 -t public/` to start the built-in server for development and testing.
- (Optional) If you need to test the CD component, refer to the `Devops_CD` repository documentation.

### 3. Write Code
- **Coding Style**: Please follow the **PSR-12** coding standard. It is recommended to use tools like PHP_CodeSniffer for checking.
- **Testing**: Write appropriate unit tests for new features or fixes (if applicable). Ensure all existing tests pass.
- **Documentation**: Your contribution must include or update relevant documentation. This includes:
    - Updating usage instructions in `README.md` or under `docs/`.
    - If it's a new API endpoint, update the Swagger/OpenAPI documentation.
    - If new configuration items are introduced, update `.env.example` and the administrator manual.

### 4. Commit Message

Please use clear and descriptive commit messages.
Following the **Conventional Commits** specification is highly recommended:

    <type>(optional scope): <short description>

    <optional detailed description>

- **Common types**:
  - `feat`: A new feature
  - `fix`: A bug fix
  - `docs`: Documentation changes
  - `style`: Code formatting (no functional impact)
  - `refactor`: Code refactoring (neither a new feature nor a bug fix)
  - `test`: Adding or modifying tests
  - `chore`: Build process or tooling changes

**Example**:

    feat(custom_push): add validation for custom CI build status
    Now the Custom_Push API validates project_name and tag format,
    and returns clear error messages when they don't match.

### 5. Open a Pull Request (PR)

- Ensure your PR is based on the latest `main` branch.
- In the PR description, clearly explain what problem it solves and link the related Issue (e.g., `Closes #123`).
- Ensure CI (if configured) checks pass and there are no conflicts with the base branch.

## Code of Conduct

Contributors to this project are expected to adhere to the [Contributor Covenant](https://www.contributor-covenant.org/version/2/0/code_of_conduct/). We expect all interactions to be open, inclusive, and respectful.

## Getting Help

If you have any questions during your contribution, feel free to ask in an Issue or contact the maintainer via email (jeanslw@gmail.com).

Thank you again for your contribution! 🎉