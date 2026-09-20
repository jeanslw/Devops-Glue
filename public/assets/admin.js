import './core/theme.js';
import { toggleTheme } from './core/theme.js';
import {
    doLogout, setTopUser, toggleTopMenu, refreshPermissions, setSession,
    hasPermission, getRole
} from './core/auth.js';
import { toast, confirmDialog, resolveConfirm } from './core/toast.js';
import { resolveTab } from './core/router.js';
import { renderBreadcrumb } from './breadcrumb.js';

import { loadMonitor } from './modules/monitor.js';
import {
    loadMaps, onFilterChange, switchMapView, showForm, hideForm, submitForm,
    editMap, activateMap, deleteMap, doDiscover, copyPipelineIds, updateDiscoverButton,
    getMapView, getMapPage, setMapPage, getMapTotalPages, setMapTotalPages
} from './modules/mapping.js';
import { loadTopology, renderTopology, getTopoPage, setTopoPage, getTopoTotalPages } from './modules/topology.js';
import { loadSecurityChecks, secOnFilterChange, getSecPage, getSecTotalPages, secSetPage } from './modules/security.js';
import { loadVersions, saveVersions } from './modules/versions.js';
import {
    loadSettings, onBuildModesChange, onCustomPushToggle, onStaleTagCleanupToggle,
    onBackfillTagToggle, onTagLogKeywordChange, renderBuildModeCheckboxes
} from './modules/mode.js';
import {
    loadPullProjects, loadPullRecords, openPullLog, pullShowLog, closePullLog,
    loadPushRecords, startPullAutoRefresh, stopPullAutoRefresh,
    applyBuildRecordsMenuVisibility, resolvePullTag,
    getPullPage, setPullPage, getPushPage, getPushTotalPages, setPushPage
} from './modules/records.js';
import {
    loadUsers, toggleUserStatus, showUserForm, showUserEditForm, hideUserForm,
    submitUserForm, deleteUser, modifyUserPassword, closePasswordModal,
    submitPasswordChange, changePassword
} from './modules/users.js';
import {
    loadRoleList, showRoleForm, hideRoleForm, submitRoleForm, deleteRole, togglePermGroup
} from './modules/roles.js';
import {
    loadPermList, deletePermission, registerPermission,
    loadImpliedRules, showImpliedForm, hideImpliedForm, submitImpliedForm, deleteImpliedRule
} from './modules/permissions.js';
import {
    loadApiTokens, showApiTokenForm, hideApiTokenForm, refreshApiTokenView,
    submitApiTokenForm, copyApiToken, revokeApiToken, deleteApiToken
} from './modules/apiTokens.js';
import { loadOperationLogs, resetOperationLogs } from './modules/oplog.js';
import { loadSystemInfo, doMigrate, doBackup, loadPlatformConfig, toggleSysTables } from './modules/system.js';

const LOGIN_API = '/api/admin/login';

// ═══════════ 菜单显隐 ═══════════
function applyRoleMenuVisibility() {
    const group = document.getElementById('menu-group-users');
    const listItem = document.querySelector('.submenu .menu-item[data-tab="users"]');
    const rolesItem = document.querySelector('.submenu .menu-item[data-tab="roles"]');
    const passwordItem = document.querySelector('.submenu .menu-item[data-tab="password"]');
    if (listItem) listItem.style.display = hasPermission('ci.users.list') ? '' : 'none';
    if (rolesItem) rolesItem.style.display = hasPermission('ci.users.manage_admin') ? '' : 'none';
    if (passwordItem) passwordItem.style.display = hasPermission('ci.users.password') ? '' : 'none';
    const showGroup = hasPermission('ci.users.list') || hasPermission('ci.users.manage_admin') || hasPermission('ci.users.password');
    if (group) group.style.display = showGroup ? '' : 'none';
}
function applyPermMenuVisibility() {
    const group = document.getElementById('menu-group-perms');
    const listItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="perm-list"]');
    const registerItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="perm-register"]');
    const rulesItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="implied-rules"]');
    const listOk = hasPermission('ci.permissions.list');
    const registerOk = hasPermission('ci.permissions.register');
    const rulesOk = hasPermission('ci.permissions.rules');
    if (listItem) listItem.style.display = listOk ? '' : 'none';
    if (registerItem) registerItem.style.display = registerOk ? '' : 'none';
    if (rulesItem) rulesItem.style.display = rulesOk ? '' : 'none';
    const showGroup = listOk || registerOk || rulesOk;
    if (group) group.style.display = showGroup ? '' : 'none';
}
function applyApiTokenMenuVisibility() {
    const item = document.getElementById('menu-api-tokens');
    if (item) item.style.display = (getRole() === 'super_admin') ? '' : 'none';
}
function applyOperationLogMenuVisibility() {
    const item = document.getElementById('menu-operation-logs');
    if (item) item.style.display = hasPermission('ci.operation-logs') ? '' : 'none';
}
function applySystemInfoMenuVisibility() {
    const item = document.getElementById('menu-group-settings');
    if (item) item.style.display = hasPermission('ci.system') ? '' : 'none';
}

function applyAllMenuVisibility() {
    applyRoleMenuVisibility();
    applyPermMenuVisibility();
    applyApiTokenMenuVisibility();
    applyOperationLogMenuVisibility();
    applySystemInfoMenuVisibility();
}

// ═══════════ 登录 / 登出 / 导航 ═══════════
async function doLogin() {
    const user = document.getElementById('login-user').value.trim();
    const pass = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-err');
    errEl.style.display = 'none';
    if (!user || !pass) { errEl.textContent = __.t('auth.please_enter_credentials'); errEl.style.display = 'block'; return; }
    try {
        const res = await fetch(LOGIN_API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username:user,password:pass}) });
        const data = await res.json();
        if (res.ok && data.token) {
            setSession(data);
            await refreshPermissions();
            applyAllMenuVisibility();
            document.getElementById('login-page').style.display = 'none';
            document.getElementById('app-page').style.display = 'block';
            setTopUser();
            doSwitch(resolveTab());
            loadSettings();
        } else {
            errEl.textContent = data.message || __.t('js.login_failed');
            errEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = __.t('js.network_error') + ': ' + e.message;
        errEl.style.display = 'block';
    }
}

function goToDocs() { location.href = '/api/docs'; }

function toggleSidebar() {
    const sb = document.querySelector('.sidebar');
    const ov = document.getElementById('sidebar-overlay');
    if (!sb) return;
    const open = !sb.classList.contains('open');
    sb.classList.toggle('open', open);
    if (ov) ov.classList.toggle('show', open);
}

function toggleUserMenu() { document.getElementById('menu-group-users')?.classList.toggle('expanded'); }
function togglePermMenu() { document.getElementById('menu-group-perms')?.classList.toggle('expanded'); }
function toggleBuildRecordsMenu() { document.getElementById('menu-group-build-records')?.classList.toggle('expanded'); }
function toggleSettingsMenu() { document.getElementById('menu-group-settings')?.classList.toggle('expanded'); }

const TAB_LIST = ['monitor','mapping','security','versions','mode','pull-records','push-records','users','roles','password','perm-list','perm-register','implied-rules','api-tokens','operation-logs','platform-config','system-info'];

function doSwitch(name) {
    stopPullAutoRefresh();
    document.querySelectorAll('.sidebar .menu-item').forEach(el => el.classList.remove('active'));
    const mi = document.querySelector('.menu-item[data-tab="' + name + '"]');
    if (mi) {
        mi.classList.add('active');
        const group = mi.closest('.menu-group');
        if (group && !mi.classList.contains('menu-group-title')) group.classList.add('expanded');
    }
    TAB_LIST.forEach(t => {
        const tabEl = document.getElementById('tab-' + t);
        if (tabEl) tabEl.style.display = name === t ? 'block' : 'none';
    });

    renderBreadcrumb(name);

    if (name === 'monitor') loadMonitor();
    if (name === 'mapping') { if (getMapView() === 'topology') loadTopology(); else loadMaps(); updateDiscoverButton(); }
    if (name === 'security') loadSecurityChecks();
    if (name === 'versions') loadVersions();
    if (name === 'mode') loadSettings();
    if (name === 'pull-records') { loadPullProjects(); startPullAutoRefresh(); }
    if (name === 'push-records') loadPushRecords();
    if (name === 'users') loadUsers();
    if (name === 'roles') loadRoleList();
    if (name === 'perm-list') loadPermList();
    if (name === 'implied-rules') loadImpliedRules();
    if (name === 'api-tokens') loadApiTokens();
    if (name === 'operation-logs') loadOperationLogs(1);
    if (name === 'platform-config') loadPlatformConfig();
    if (name === 'system-info') loadSystemInfo();

    const sb = document.querySelector('.sidebar');
    if (sb && sb.classList.contains('open')) toggleSidebar();
}

function navigate(name) {
    const target = '#/' + name;
    if (location.hash === target) doSwitch(name);
    else location.hash = target;
}
function switchTab(name) { navigate(name); }
window.addEventListener('hashchange', () => doSwitch(resolveTab()));

// ═══════════ 语言切换 ═══════════
document.addEventListener('i18n-changed', function() {
    const sel = document.getElementById('lang-select');
    if (sel) sel.value = __.lang;
    const selLogin = document.getElementById('lang-select-login');
    if (selLogin) selLogin.value = __.lang;

    const activeTab = document.querySelector('.sidebar .menu-item.active');
    if (activeTab) {
        const tabName = activeTab.getAttribute('data-tab');
        if (tabName === 'mapping') { if (getMapView() === 'topology') loadTopology(); else loadMaps(); }
        else if (tabName === 'security') loadSecurityChecks();
        else if (tabName === 'versions') loadVersions();
        else if (tabName === 'mode') loadSettings();
        else if (tabName === 'roles') loadRoleList();
        else if (tabName === 'api-tokens') refreshApiTokenView();
        else if (tabName === 'platform-config') loadPlatformConfig();
        else if (tabName === 'system-info') loadSystemInfo();
    }
    renderBreadcrumb(resolveTab());
});

// ═══════════ 全局挂载（inline onclick 兼容）═══════════
Object.assign(window, {
    doLogin, doLogout, goToDocs, toggleSidebar, toggleTopMenu, toggleTheme,
    toggleUserMenu, togglePermMenu, toggleBuildRecordsMenu, toggleSettingsMenu,
    switchTab, resolveConfirm, toast, confirmDialog,
    loadMaps, onFilterChange, switchMapView, showForm, hideForm, submitForm, editMap, activateMap, deleteMap, doDiscover, copyPipelineIds,
    loadTopology, renderTopology,
    loadSecurityChecks, secOnFilterChange, secSetPage, getSecPage, getSecTotalPages,
    loadVersions, saveVersions,
    loadSettings, onBuildModesChange, onCustomPushToggle, onStaleTagCleanupToggle, onBackfillTagToggle, onTagLogKeywordChange,
    loadPullProjects, loadPullRecords, openPullLog, pullShowLog, closePullLog, loadPushRecords,
    applyBuildRecordsMenuVisibility, resolvePullTag,
    loadUsers, toggleUserStatus, showUserForm, showUserEditForm, hideUserForm, submitUserForm, deleteUser, modifyUserPassword, closePasswordModal, submitPasswordChange, changePassword,
    loadRoleList, showRoleForm, hideRoleForm, submitRoleForm, deleteRole, togglePermGroup,
    loadPermList, deletePermission, registerPermission, loadImpliedRules, showImpliedForm, hideImpliedForm, submitImpliedForm, deleteImpliedRule,
    loadApiTokens, showApiTokenForm, hideApiTokenForm, submitApiTokenForm, copyApiToken, revokeApiToken, deleteApiToken,
    loadOperationLogs, resetOperationLogs,
    loadSystemInfo, doMigrate, doBackup, loadPlatformConfig, toggleSysTables,
    // 分页 / 视图
    getMapView, getMapPage, setMapPage, getMapTotalPages, setMapTotalPages,
    getTopoPage, setTopoPage, getTopoTotalPages,
    getPullPage, setPullPage, getPushPage, getPushTotalPages, setPushPage,
    renderBuildModeCheckboxes,
});

// ═══════════ 启动 ═══════════
(function initLang() {
    let urlLang = new URLSearchParams(location.search).get('lang');
    if (urlLang === 'zh' || urlLang === 'zh_CN' || urlLang === 'zh-CN') urlLang = 'zh-CN';
    else if (urlLang === 'en') urlLang = 'en';
    else urlLang = null;
    if (urlLang) localStorage.setItem('dg_lang', urlLang);
    __.init(urlLang);
})();

document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('lang-select');
    if (sel) sel.value = __.lang;
    const selLogin = document.getElementById('lang-select-login');
    if (selLogin) selLogin.value = __.lang;
});

(async function bootstrap() {
    if (!sessionStorage.getItem('admin_token')) {
        document.getElementById('login-page').style.display = 'flex';
        document.getElementById('app-page').style.display = 'none';
        return;
    }
    document.getElementById('login-page').style.display = 'none';
    document.getElementById('app-page').style.display = 'block';
    setTopUser();
    await refreshPermissions();
    applyAllMenuVisibility();
    doSwitch(resolveTab());
    loadSettings();
})();