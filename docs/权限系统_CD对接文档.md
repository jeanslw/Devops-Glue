# RBAC 权限系统 — CD 系统对接文档

## 1. 认证流程

### 1.1 登录
```
POST /api/admin/login
Content-Type: application/json

{ "user": "用户名", "password": "密码" }
```

**响应示例：**
```json
{
  "code": 0,
  "data": {
    "token": "abc123def456...",
    "role": "builder",
    "user": "zhangsan",
    "is_root": false
  }
}
```

### 1.2 后续请求
所有请求带 `Authorization: Bearer <token>` 头。Token 有效期 24 小时。

### 1.3 systems 字段校验
登录时会检查 `admin_users.systems` 字段，用户必须包含 `cd` 才能登录 CD 实例，否则返回 403。

---

## 2. 权限表

权限键（`perm_key`）共 23 个，分为 CI 和 CD 两类。**CD 系统只需要关注 CD 相关的 15 个权限键 + 1 个 CI 跨域权限（ci.trigger）**。

### 2.1 CD 一级菜单权限（控制侧边栏显示，共 8 个）

| perm_key | 中文名 | 控制 |
|---|---|---|
| `cd.build-manage` | 构建管理 | 显示"构建管理"菜单 |
| `cd.deploy-manage` | 部署管理 | 显示"部署管理"菜单 |
| `cd.server-manage` | 服务器管理 | 显示"服务器管理"菜单 |
| `cd.webshell` | Web Shell | 显示"Web Shell"菜单 |
| `cd.deploy-record` | 部署记录 | 显示"部署记录"菜单 |
| `cd.image-registry` | 镜像仓库 | 显示"镜像仓库"菜单 |
| `cd.resource-monitor` | 资源监控 | 显示"资源监控"菜单 |
| `cd.notification-manage` | 通知管理 | 显示"通知管理"菜单 |

### 2.2 CD 二级操作权限（控制菜单内的具体操作，共 7 个）

| perm_key | 中文名 | 父权限 | 控制 |
|---|---|---|---|
| `cd.deploy.single` | 部署到单机 | `cd.deploy-manage` | 允许部署到 SSH 单机 |
| `cd.deploy.docker` | 部署到 Docker | `cd.deploy-manage` | 允许部署到 Docker |
| `cd.deploy.k8s` | 部署到 K8S | `cd.deploy-manage` | 允许部署到 K8S |
| `cd.monitor.app` | 应用资源 | `cd.resource-monitor` | 允许查看应用资源监控 |
| `cd.monitor.system` | 系统资源 | `cd.resource-monitor` | 允许查看系统资源监控 |
| `cd.monitor.custom` | 自定义资源 | `cd.resource-monitor` | 允许查看自定义资源监控 |
| `cd.monitor.alert` | 告警规则 | `cd.resource-monitor` | 允许查看/编辑告警规则 |

### 2.3 CI 权限（CD 可能需要跨域检查的 1 个）

| perm_key | 中文名 | 控制 |
|---|---|---|
| `ci.trigger` | 触发构建 | 允许发起 Jenkins/GitLab CI 构建 |

### 2.4 菜单渲染规则

**一级菜单显示条件**：用户拥有对应的一级菜单权限。例如：
- 有 `cd.build-manage` → 显示"构建管理"菜单
- 有 `cd.deploy-manage` → 显示"部署管理"菜单

**二级菜单 / 操作按钮显示条件**：用户同时拥有一级菜单权限和具体操作权限。例如：
- 有 `cd.deploy-manage` + `cd.deploy.single` → 部署管理页显示"部署到单机"按钮
- 有 `cd.deploy-manage` + `cd.deploy.k8s` → 部署管理页显示"部署到 K8S"按钮

---

## 3. 权限隐含规则

管理员在后台分配权限时，后端会自动展开隐含关系并写入 `role_permissions` 表。**你从 DB 或 API 查到的权限列表已经是最终完整列表，不需要再做推导。**

### 3.1 父 → 子
| 勾选 | 自动拥有 |
|---|---|
| `cd.build-manage`（构建管理） | `ci.trigger`（触发构建） |

**含义**：能看到"构建管理"菜单的人，自动具备触发构建的能力。

### 3.2 子 → 父（选了子操作，自动显示父菜单）
| 勾选子权限 | 自动拥有父菜单 |
|---|---|
| `cd.deploy.single` / `cd.deploy.docker` / `cd.deploy.k8s` | `cd.deploy-manage` |
| `cd.monitor.app` / `cd.monitor.system` / `cd.monitor.custom` / `cd.monitor.alert` | `cd.resource-monitor` |

**含义**：只分配"部署到单机"权限，部署管理菜单也会显示，但只能看到单机部署按钮。

### 3.3 super_admin

角色名为 `super_admin` 的用户拥有所有权限。

**在哪里判断**：两种方式都可以：
- **方式 A（推荐）**：查 `role_permissions` 表，`super_admin` 有全部 23 条记录（`DEFAULT_ROLES` 初始化时 `'*'` 已展开写入），所以 `can(permKey)` 自然通过。
- **方式 B（简便）**：判断 `user.role === "super_admin"` 直接放行。两个系统里 `admin_users.role` 都存了角色名，你可以直接用。

**推荐用方式 B**，简单直接。

---

## 4. 权限接口

### 4.1 推荐：`GET /api/admin/me/permissions`（新接口，已实现）

直接用 token 查当前用户权限，**不需要 admin 权限**。

```
GET /api/admin/me/permissions
Authorization: Bearer <token>
```

**响应（普通角色）：**
```json
{
  "role": "builder",
  "permissions": ["cd.build-manage", "ci.trigger"]
}
```

**响应（super_admin）：**
```json
{
  "role": "super_admin",
  "permissions": "*"
}
```

> `permissions` 为 `"*"` 字符串时表示拥有全部权限，直接放行所有操作。

### 4.2 备选：`GET /api/admin/roles`（需要 admin 权限）

```
GET /api/admin/roles
Authorization: Bearer <token>
```

返回所有角色及其权限。根据当前用户的 `role` 名匹配数组中的 `name`，取 `permissions` 数组。

> ⚠️ 此接口需要请求方有 admin 权限（`ci.manage`），普通用户 token 可能返回 403。

---

## 5. 前端权限检查伪代码

```javascript
let userPermissions = null; // null=未加载, "*"=超级管理员, [...] = 权限数组
let userRole = '';

// 登录后调用
async function initPermissions(token) {
    const res = await fetch('/api/admin/me/permissions', {
        headers: { 'Authorization': 'Bearer ' + token }
    });
    const data = await res.json();
    userRole = data.role;
    userPermissions = data.permissions; // 字符串 "*" 或数组 [...]
}

// 检查权限
function can(permKey) {
    if (userPermissions === '*') return true;
    return Array.isArray(userPermissions) && userPermissions.includes(permKey);
}

// 菜单渲染
const menuItems = [
    { key: 'cd.build-manage',    label: '构建管理' },
    { key: 'cd.deploy-manage',   label: '部署管理' },
    { key: 'cd.server-manage',   label: '服务器管理' },
    { key: 'cd.webshell',        label: 'Web Shell' },
    { key: 'cd.deploy-record',   label: '部署记录' },
    { key: 'cd.image-registry',  label: '镜像仓库' },
    { key: 'cd.resource-monitor',label: '资源监控' },
    { key: 'cd.notification-manage', label: '通知管理' },
];

const visibleMenu = menuItems.filter(item => can(item.key));
renderSidebar(visibleMenu);

// 二级按钮
if (can('cd.deploy.single')) showDeployToSingleBtn();
if (can('cd.deploy.docker')) showDeployToDockerBtn();
if (can('cd.deploy.k8s'))    showDeployToK8SBtn();
if (can('ci.trigger'))       showTriggerBuildBtn();
```

---

## 6. 权限键常量（可直接复制到 CD 代码）

```javascript
const PERM = {
    // 一级菜单
    BUILD_MANAGE:     'cd.build-manage',
    DEPLOY_MANAGE:    'cd.deploy-manage',
    SERVER_MANAGE:    'cd.server-manage',
    WEBSHELL:         'cd.webshell',
    DEPLOY_RECORD:    'cd.deploy-record',
    IMAGE_REGISTRY:   'cd.image-registry',
    RESOURCE_MONITOR: 'cd.resource-monitor',
    NOTIFICATION:     'cd.notification-manage',

    // 二级操作
    DEPLOY_SINGLE:    'cd.deploy.single',
    DEPLOY_DOCKER:    'cd.deploy.docker',
    DEPLOY_K8S:       'cd.deploy.k8s',
    MONITOR_APP:      'cd.monitor.app',
    MONITOR_SYSTEM:   'cd.monitor.system',
    MONITOR_CUSTOM:   'cd.monitor.custom',
    MONITOR_ALERT:    'cd.monitor.alert',

    // CI 跨域
    CI_TRIGGER:       'ci.trigger',
};
```

---

## 7. 你的问题答复

### Q1: super_admin 在 role_permissions 表里有没有记录？
**有。** `ensureTables()` 初始化时 `'*'` 已被展开为全部 23 个权限键写入 `role_permissions`。所以你用 `can(permKey)` 查表也行。

但你用 `user.role === "super_admin"` 判断也完全没问题，两个系统 `admin_users.role` 列都存了角色名。推荐这种，简单直接。

### Q2: 隐含规则是否已展开写入 role_permissions？
**是的。** 管理员后台保存角色时，`expandPermissions()` 会把隐含权限一起写入 DB。你从 `me/permissions` 接口或直接读表拿到的就是最终完整列表，无需再做推导。

### Q3: /api/admin/me/permissions 已实现
已新增 `GET /api/admin/me/permissions`，只需要 token 认证，不需要 admin 权限。返回格式见上面第 4.1 节。你可以从直接读库切到这个接口了。
