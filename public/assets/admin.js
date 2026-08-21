const LOGIN_API    = '/api/admin/login';
const MAP_API      = '/api/admin/job_git_map';
const HEALTH_API   = '/api/health';
const MAP_LIST_API  = '/api/main/map/list';
const VERSIONS_API  = '/api/admin/platform_versions';
let platforms = [];
let token = sessionStorage.getItem('admin_token') || '';
let currentUserRole = sessionStorage.getItem('admin_role') || '';
let currentUserName = sessionStorage.getItem('admin_user') || '';
let currentUserIsRoot = sessionStorage.getItem('admin_is_root') === 'true';
let currentPermissions = [];
try {
    var _raw = JSON.parse(sessionStorage.getItem('admin_perms') || '[]');
    // 后端对 super_admin 返回字符串 '*', 普通用户返回数组 ['ci.manage', ...] ，统一归一化为数组
    currentPermissions = Array.isArray(_raw) ? _raw : (_raw === '*' ? ['*'] : []);
} catch(e) { currentPermissions = []; }

/** 判断当前用户是否拥有指定权限（super_admin 的 '*' 通配始终返回 true） */
function hasPermission(permKey) {
    if (currentPermissions === '*') return true;
    if (Array.isArray(currentPermissions) && currentPermissions[0] === '*') return true;
    if (!Array.isArray(currentPermissions)) return false;
    return currentPermissions.includes(permKey);
}

/** 将后端返回的权限数据归一化为数组（super_admin 返回字符串 '*') */
function _normalizePerms(raw) {
    if (Array.isArray(raw)) return raw;
    if (raw === '*') return ['*'];
    return [];
}

// ═══════════ Auth ═══════════
function authHeaders() {
    return token ? { 'Authorization': 'Bearer ' + token } : {};
}

function handle401(res) {
    if (res.status === 401) { doLogout(); return true; }
    return false;
}

function goToDocs() {
    var t = sessionStorage.getItem('admin_token') || token;
    location.href = '/api/docs' + (t ? '?token=' + t : '');
}

function doLogout() {
    // 通知服务端清理 token（异步，不阻塞页面切换）
    if (token) {
        fetch('/api/admin/logout', { method: 'POST', headers: authHeaders() })
            .catch(function() {}); // 忽略网络错误
    }
    token = '';
    currentUserRole = '';
    currentUserName = '';
    currentUserIsRoot = false;
    currentPermissions = [];
    sessionStorage.removeItem('admin_token');
    sessionStorage.removeItem('admin_role');
    sessionStorage.removeItem('admin_user');
    sessionStorage.removeItem('admin_is_root');
    sessionStorage.removeItem('admin_perms');
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}

// 刷新后恢复角色菜单可见性及用户名显示，并加载最新权限
(async function initRoleMenu() {
    currentUserName = sessionStorage.getItem('admin_user') || '';
    currentUserIsRoot = sessionStorage.getItem('admin_is_root') === 'true';
    if (currentUserName) {
        var topUser = document.getElementById('top-user');
        if (topUser) topUser.textContent = '👤 ' + currentUserName;
        // 从后端拉取最新权限（sessionStorage 可能过期）
        try {
            var mr = await fetch('/api/admin/me/permissions', { headers: authHeaders() });
            if (mr.ok) {
                var md = await mr.json();
                currentPermissions = _normalizePerms(md.permissions);
                sessionStorage.setItem('admin_perms', JSON.stringify(currentPermissions));
            }
        } catch(e) {}
    }
    // 按权限显隐：用户管理分组 + 角色管理（独立） + 权限管理分组
    applyRoleMenuVisibility();
    applyPermMenuVisibility();
    applyApiTokenMenuVisibility();
})();

/** 根据权限控制用户管理分组及子菜单显隐 */
function applyRoleMenuVisibility() {
    var group = document.getElementById('menu-group-users');
    var listItem = document.querySelector('.submenu .menu-item[data-tab="users"]');
    var rolesItem = document.querySelector('.submenu .menu-item[data-tab="roles"]');
    var passwordItem = document.querySelector('.submenu .menu-item[data-tab="password"]');
    if (listItem) listItem.style.display = hasPermission('ci.users.list') ? '' : 'none';
    // 角色管理回到上一版：权限为 ci.users.manage_admin（含管理员用户 + 角色CRUD）
    if (rolesItem) rolesItem.style.display = hasPermission('ci.users.manage_admin') ? '' : 'none';
    if (passwordItem) passwordItem.style.display = hasPermission('ci.users.password') ? '' : 'none';
    // 父菜单组可见条件：至少有一个子菜单项可见；任一子权限都会通过 IMPLIED_PERMISSIONS 自动带上 ci.users.manage
    var showGroup = hasPermission('ci.users.list')
        || hasPermission('ci.users.manage_admin')
        || hasPermission('ci.users.password');
    if (group) group.style.display = showGroup ? '' : 'none';
}

/** 根据权限控制「权限管理」分组及子菜单显隐（细粒度，每个子菜单独立权限） */
function applyPermMenuVisibility() {
    var group = document.getElementById('menu-group-perms');
    var listItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="perm-list"]');
    var registerItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="perm-register"]');
    var rulesItem = document.querySelector('#menu-group-perms .submenu .menu-item[data-tab="implied-rules"]');
    var listOk = hasPermission('ci.permissions.list');
    var registerOk = hasPermission('ci.permissions.register');
    var rulesOk = hasPermission('ci.permissions.rules');
    if (listItem) listItem.style.display = listOk ? '' : 'none';
    if (registerItem) registerItem.style.display = registerOk ? '' : 'none';
    if (rulesItem) rulesItem.style.display = rulesOk ? '' : 'none';
    // 父菜单组可见条件：至少有一个子菜单项可见
    var showGroup = listOk || registerOk || rulesOk;
    if (group) group.style.display = showGroup ? '' : 'none';
}

/** API 管理菜单：独立于权限体系，仅 super_admin 可见 */
function applyApiTokenMenuVisibility() {
    var item = document.getElementById('menu-api-tokens');
    if (item) item.style.display = (currentUserRole === 'super_admin') ? '' : 'none';
}

let _discovering = false;
async function doDiscover() {
    if (_discovering) { toast('⏳ ' + __.t('js.scan_in_progress'), true, true); return; }
    if (!confirm(__.t('js.discover_confirm'))) return;
    _discovering = true;
    toast('⏳ ' + __.t('js.scanning'), true, true);
    try {
        const res = await fetch('/api/admin/discover', { method:'POST', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            toast(__.t('map.discover_result', {found: data.found, saved: data.saved}), true, true);
            if (currentMapView === 'topology') loadTopology(); else loadMaps();
        } else {
            toast(data.message || __.t('js.scan_failed'), false, true);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false, true); }
    finally { _discovering = false; }
}

/** 自动发现按钮始终可见（custom_push 模式扫描 Git 平台项目） */
function updateDiscoverButton() {
    const btn = document.querySelector('.btn-discover');
    if (btn) btn.style.display = '';
}

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
            token = data.token;
            currentUserRole = data.role || '';
            currentUserName = data.user || '';
            currentUserIsRoot = data.is_root === true;
            sessionStorage.setItem('admin_token', token);
            if (currentUserRole) sessionStorage.setItem('admin_role', currentUserRole);
            if (currentUserName) sessionStorage.setItem('admin_user', currentUserName);
            sessionStorage.setItem('admin_is_root', currentUserIsRoot ? 'true' : 'false');
            // 拉取权限列表，驱动菜单显隐
            try {
                var pr = await fetch('/api/admin/me/permissions', { headers: authHeaders() });
                if (pr.ok) {
                    var pd = await pr.json();
                    currentPermissions = _normalizePerms(pd.permissions);
                    sessionStorage.setItem('admin_perms', JSON.stringify(currentPermissions));
                }
            } catch(e) {}
            applyRoleMenuVisibility();
            applyPermMenuVisibility();
            applyApiTokenMenuVisibility();
            document.getElementById('login-page').style.display = 'none';
            document.getElementById('app-page').style.display = 'block';
            document.getElementById('top-user').textContent = '👤 ' + currentUserName;
            switchTab('monitor');
        } else {
            errEl.textContent = data.message || __.t('js.login_failed');
            errEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = __.t('js.network_error') + ': ' + e.message;
        errEl.style.display = 'block';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && document.getElementById('login-page').style.display !== 'none') doLogin();
});

function switchTab(name) {
    document.querySelectorAll('.sidebar .menu-item').forEach(el => el.classList.remove('active'));
    var mi = document.querySelector('.menu-item[data-tab="' + name + '"]');
    if (mi) {
        mi.classList.add('active');
        var group = mi.closest('.menu-group');
        if (group) {
            if (mi.classList.contains('menu-group-title')) {
                // 点击标题：由 toggleUserMenu() 控制展开/收起，这里只负责高亮
            } else {
                // 点击子菜单项：强制展开分组
                group.classList.add('expanded');
            }
        }
    }
    ['monitor','mapping','security','versions','mode','push-records','users','roles','password','perm-list','perm-register','implied-rules','api-tokens'].forEach(t => {
        var tabEl = document.getElementById('tab-' + t);
        if (tabEl) tabEl.style.display = name === t ? 'block' : 'none';
    });
    if (name === 'monitor') loadMonitor();
    if (name === 'mapping') { if (currentMapView === 'topology') loadTopology(); else loadMaps(); updateDiscoverButton(); }
    if (name === 'security') loadSecurityChecks();
    if (name === 'versions') loadVersions();
    if (name === 'mode') loadSettings();
    if (name === 'push-records') loadPushRecords();
    if (name === 'users') loadUsers();
    if (name === 'roles') loadRoleList();
    if (name === 'perm-list') loadPermList();
    if (name === 'perm-register') { }
    if (name === 'implied-rules') loadImpliedRules();
    if (name === 'api-tokens') loadApiTokens();
}

function toggleUserMenu() {
    var group = document.getElementById('menu-group-users');
    if (group) group.classList.toggle('expanded');
}

function togglePermMenu() {
    var group = document.getElementById('menu-group-perms');
    if (group) group.classList.toggle('expanded');
}

// ═══════════ Toast ═══════════
function toast(msg, ok, center) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err') + ' show' + (center ? ' toast-center' : '');
    setTimeout(() => el.classList.remove('show'), 2500);
}

// 复制 Pipeline ID 列表到剪贴板（build 接口已加认证，需带 token）
async function copyPipelineIds(jobName) {
    try {
        const res = await fetch('/api/build/' + encodeURIComponent(jobName) + '/pipelines?list=id', { headers: authHeaders() });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        if (!text.trim()) { toast('无 Pipeline 记录', false); return; }
        navigator.clipboard.writeText(text).then(
            () => toast('Pipeline ID 已复制', true),
            () => toast('复制失败，请检查浏览器权限', false)
        );
    } catch (e) {
        toast('获取失败: ' + e.message, false);
    }
}

// ═══════════ 服务监测 ═══════════
async function loadMonitor() {
    const now = new Date().toLocaleString(__.lang === 'en' ? 'en-US' : 'zh-CN');

    // 调用受保护的 /api/health，需要带上 Authorization 头

    function setSvc(iconId, nameId, statId, dotId, ok, ver, label, title) {
        const icon = document.getElementById(iconId);
        const name = document.getElementById(nameId);
        const stat = document.getElementById(statId);
        const dot  = document.getElementById(dotId);
        if (icon) icon.textContent = ok === true ? '✅' : ok === null ? '⚪' : '❌';
        if (stat) {
            stat.textContent = label;
            stat.className = 'svc-stat ' + (ok===true?'ok':ok===null?'off':'err');
            if (title) stat.title = title; else stat.removeAttribute('title');
        }
        if (dot)  { dot.className = 'dot ' + (ok===true?'dot-ok':ok===null?'dot-off':'dot-err'); }
        if (name && ver) name.innerHTML = (name.dataset.base || name.textContent) + ' <span class="svc-ver">' + ver + '</span>';
    }

    try {
        const res = await fetch(HEALTH_API, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const chk = data.checks || {};
        const st  = data.stats || {};

        // 统计卡片
        document.getElementById('stat-total').textContent = st.total_maps ?? '—';
        document.getElementById('stat-active').textContent = st.active_maps ?? '—';
        document.getElementById('stat-platforms').textContent = st.git_platforms ?? '—';
        document.getElementById('stat-repos').textContent = st.harbor_repos ?? '—';

        // 系统监测
        const buildModeMap = {
            'jenkins':    'build.mode_jenkins',
            'gitlab_ci':  'build.mode_gitlab_ci',
            'both':       'build.mode_both'
        };
        const dbMap = {
            'mysql':  'system.db_mysql',
            'sqlite': 'system.db_sqlite'
        };
        const bmKey = buildModeMap[data.build_mode] || 'common.unknown';
        const dbKey = dbMap[(data.db_driver || '').toLowerCase()] || 'common.unknown';
        const sysBuildMode = document.getElementById('sys-build-mode');
        const sysDbType    = document.getElementById('sys-db-type');
        const sysAppVer    = document.getElementById('sys-app-version');
        const sysEnvType   = document.getElementById('sys-env-type');
        const sysTime      = document.getElementById('sys-system-time');
        if (sysBuildMode) sysBuildMode.textContent = __.t(bmKey, null, data.build_mode || '—');
        if (sysDbType)    sysDbType.textContent    = __.t(dbKey, null, data.db_driver || '—');
        if (sysAppVer)    sysAppVer.textContent    = data.app_version ? 'v' + data.app_version : '—';
        if (sysEnvType)   sysEnvType.textContent   = data.app_env || '—';
        if (sysTime)      sysTime.textContent      = (data.time ? String(data.time).substring(0, 16) : '—');

        // Jenkins
        const jRaw = chk.jenkins;
        const jOk  = jRaw === true;
        const jVer = chk.jenkins_version || '';
        const jLabel = jOk ? __.t('common.ok') : jRaw===null ? __.t('common.na') : __.t('common.unreachable');
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', jRaw, jVer ? 'v'+jVer : '', jLabel);

        // Git 平台
        const gitRows = document.getElementById('git-rows');
        const gitData = chk.git;
        const dotGit = document.getElementById('dot-git');
        if (gitData === null || gitData === undefined) {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat off">' + __.t('monitor.git_no_ref') + '</span></div>';
        } else if (Array.isArray(gitData) && gitData.length > 0) {
            dotGit.className = gitData.every(g=>g.reachable) ? 'dot dot-ok' : 'dot dot-err';
            gitRows.innerHTML = gitData.map(g => {
                const ok = g.reachable;
                const label = ok ? __.t('monitor.git_reachable') : __.t('monitor.git_unreachable');
                return '<div class="svc-row child">' +
                    '<span class="svc-icon">' + (ok ? '✅' : '❌') + '</span>' +
                    '<span class="svc-name">' + esc(g.name) + '<span class="svc-ver">' + (g.api_version||'') + '</span></span>' +
                    '<span class="svc-stat ' + (ok?'ok':'err') + '">' + label + '</span>' +
                '</div>';
            }).join('') || '<div class="svc-row child"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('js.no_configured_platform') + '</span></div>';
        } else {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat off">' + __.t('common.unknown') + '</span></div>';
        }

        // Harbor
        const hOkRaw = chk.harbor;
        const hOk = hOkRaw === true;
        const hVer = chk.harbor_version || '';
        const hLabel = hOk ? __.t('common.ok') : hOkRaw===null ? __.t('common.not_configured') : __.t('common.unreachable');
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', hOk, hVer, hLabel, '');

        // Custom_Push
        const cpProviders = data.custom_push_providers || [];
        const cpEnabled = data.custom_push_enabled || false;
        const cpOk = cpEnabled && cpProviders.length > 0;
        const cpLabel = cpEnabled
            ? __.t('common.enabled')
            : __.t('common.disabled');
        setSvc('icon-custom-push', 'name-custom-push', 'stat-custom-push', 'dot-custom-push', cpOk || null, '', cpLabel);
        applyPushRecordsMenuVisibility(cpEnabled);

    } catch(e) {
        const msg = e.name === 'AbortError' ? __.t('js.timeout') : __.t('js.cannot_connect');
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', false, '', msg);
        document.getElementById('dot-git').className = 'dot dot-err';
        document.getElementById('git-rows').innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat err">' + msg + '</span></div>';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', false, '', msg);
        setSvc('icon-custom-push', 'name-custom-push', 'stat-custom-push', 'dot-custom-push', false, '', msg);
    }
}

// ═══════════ 映射管理 ═══════════
let mapPage = 1, mapPerPage = 20, mapTotalPages = 1;
let mapDebounceTimer = null;
let currentMapView = 'table';  // 'table' | 'topology'

function switchMapView(view) {
    currentMapView = view;
    // 更新按钮状态
    document.querySelectorAll('#view-toggle button').forEach(b => b.classList.remove('active'));
    document.querySelector(`#view-toggle [data-view="${view}"]`).classList.add('active');
    // 切换视图
    document.getElementById('table-view').style.display = view === 'table' ? 'block' : 'none';
    document.getElementById('topo-view').style.display = view === 'topology' ? 'block' : 'none';
    // 隐藏表单面板（新增/编辑在表格视图中）
    if (view === 'topology') hideForm();
    // 加载数据
    if (view === 'topology') loadTopology(); else loadMaps();
}

function onFilterChange() {
    clearTimeout(mapDebounceTimer);
    mapDebounceTimer = setTimeout(() => { mapPage = 1; loadMaps(); }, 300);
}

/** 归一化 Git remote URL 为纯路径（org/repo），与后端 AutoDiscover::normalizeRemote 一致 */
function normalizeRemote(r) {
    r = (r || '').trim();
    if (!r) return '';
    // 去掉协议前缀
    r = r.replace(/^(https?|ssh|git):\/\//i, '');
    // git@host:path → host/path
    const m = r.match(/^git@([^:]+):(.+)/);
    if (m) r = m[1] + '/' + m[2];
    // 去尾部 .git 和末尾斜杠
    r = r.replace(/\.git$/i, '');
    r = r.replace(/\/$/, '');
    // 提取路径部分（去掉 host），统一小写
    const slashPos = r.indexOf('/');
    if (slashPos !== -1) r = r.substring(slashPos + 1).toLowerCase();
    else r = r.toLowerCase();
    return r;
}

async function loadMaps() {
    try {
        const search = document.getElementById('map-search')?.value?.trim() || '';
        const platform = document.getElementById('map-platform-filter')?.value || '';
        const provider = document.getElementById('map-provider-filter')?.value || '';
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (platform) params.set('platform', platform);
        if (provider) params.set('provider', provider);
        params.set('page', mapPage);
        params.set('per_page', mapPerPage);
        const url = MAP_API + '?' + params.toString();
        const res = await fetch(url, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        let maps = data.maps || [];

        // 前端去重：归一化 remote 后比对，同一仓库有 active 记录时隐藏其他非 active（与后端逻辑一致）
        const activeRemotes = new Set();
        maps.forEach(m => {
            if ((m.status || 'active') === 'active' && m.git_remote) {
                const nk = normalizeRemote(m.git_remote);
                if (nk) activeRemotes.add(nk);
            }
        });
        maps = maps.filter(m => (m.status || 'active') === 'active' || !activeRemotes.has(normalizeRemote(m.git_remote)));

        // 隐藏非 active 且 provider 不匹配当前模式的记录（无法操作，占地方无意义）
        // 自定义推送式 CI（custom_push 及其他自定义注册名）不参与 jenkins/gitlab_ci 的全局模式筛选，始终显示
        if (currentBuildMode !== 'both') {
            maps = maps.filter(m => {
                const bp = (m.build_provider || 'jenkins');
                if (bp !== 'jenkins' && bp !== 'gitlab_ci') return true;
                return (m.status || 'active') === 'active' || bp === currentBuildMode;
            });
        }

        // 分页以过滤后的实际可见数量为准（API 返回的是原始数据库总数，前端 dedup/provider 过滤后不可见）
        const displayTotal = maps.length;
        mapTotalPages = Math.max(1, Math.ceil(displayTotal / mapPerPage));
        if (mapPage > mapTotalPages) mapPage = mapTotalPages;

        platforms = data.platforms || [];

        // 更新平台下拉（新增/编辑表单和筛选栏）
        [{sel:document.getElementById('f-git_platform'),opt:__.t('js.auto_detect')},
         {sel:document.getElementById('map-platform-filter'),opt:__.t('map.all_platforms')}].forEach(o => {
            if (!o.sel) return;
            const cur = o.sel.value;
            o.sel.innerHTML = `<option value="">${o.opt}</option>`;
            platforms.forEach(p => { o.sel.innerHTML += `<option value="${p}">${p}</option>`; });
            if (platforms.includes(cur)) o.sel.value = cur;
        });

        const tbody = document.getElementById('map-tbody');
        const empty = document.getElementById('empty-msg');
        const tableWrap = document.getElementById('table-wrap');
        const pagination = document.getElementById('map-pagination');
        document.getElementById('loading-map').style.display = 'none';

        if (maps.length === 0) {
            empty.style.display = 'block';
            tableWrap.style.display = 'none';
        } else {
            empty.style.display = 'none';
            tableWrap.style.display = 'block';
            tbody.innerHTML = maps.map(m => {
                const plat = m.git_platform || '—';
                const bp = m.build_provider || 'jenkins';
                const bpLabel = bp === 'jenkins' ? __.t('build.mode_jenkins')
                    : bp === 'gitlab_ci' ? __.t('build.mode_gitlab_ci')
                    : bp === 'custom_push' ? 'Custom_Push'
                    : bp;
                const bpBadge = bp === 'gitlab_ci' ? 'badge-gitlab' : bp === 'custom_push' ? 'badge-cus' : 'badge-default';
                const badgeCls = plat !== '—' && platforms.includes(plat) ? 'badge-' + plat : 'badge-default';
                return `<tr>
                    <td><strong>${esc(m.job_name)}</strong></td>
                    <td><span class="badge ${bpBadge}">${bpLabel}</span></td>
                    <td>${plat !== '—' ? `<span class="badge ${badgeCls}">${esc(plat)}</span>` : '—'}</td>
                    <td class="mono">${esc(m.git_remote || '—')}</td>
                    <td>${esc(m.harbor_repository || '—')}</td>
                    <td>${statusBadge(m.status)}</td>
                    <td style="white-space:nowrap">
                        ${(function(){
                            if ((m.status||'active')==='active') return '';
                            return `<button class="btn btn-sm btn-activate" title="${esc(__.t('js.activate_warn_hide'))}" onclick="activateMap('${escJs(esc(m.job_name))}', ${js(m)})">${__.t('common.enabled')}</button>`;
                        })()}
                        <button class="btn btn-sm btn-edit" onclick='editMap(${js(m)})'>✏️ ${__.t('common.edit')}</button>
                        <button class="btn btn-sm btn-del" onclick="deleteMap('${escJs(esc(m.job_name))}')">🗑 ${__.t('common.delete')}</button>
                        <button class="btn btn-sm" onclick="copyPipelineIds('${escJs(esc(m.job_name))}')" style="color:#4f46e5;font-size:12px;margin-left:6px;border:none;background:none;cursor:pointer;" title="复制 Pipeline ID">📋</button>
                    </td>
                </tr>`;
            }).join('');

            // 分页
            let pagHtml = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: displayTotal}) + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=1;loadMaps()" ' + (mapPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=Math.max(1,mapPage-1);loadMaps()" ' + (mapPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pagHtml += '<span style="color:#374151;font-weight:600;">' + mapPage + ' / ' + mapTotalPages + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=Math.min(mapTotalPages,mapPage+1);loadMaps()" ' + (mapPage>=mapTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=mapTotalPages;loadMaps()" ' + (mapPage>=mapTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            pagination.innerHTML = pagHtml;
        }
    } catch(e) {
        document.getElementById('loading-map').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

// ═══════════ 项目拓扑 ═══════════
let topoEntries = [];   // 已加载的拓扑数据
let topoPage    = 1;    // 当前页
let topoTotalPages = 1; // 总页数（全局，供内联 onclick 使用）
const TOPO_PER_PAGE = 10;
let topoPlatformUrls = {};  // { jenkins_url, harbor_url } 从 /api/main/git/platforms 获取

async function loadTopology() {
    const loading = document.getElementById('topo-loading');
    const grid    = document.getElementById('topo-grid');
    const empty   = document.getElementById('topo-empty');
    const pagination = document.getElementById('topo-pagination');

    loading.style.display = 'block';
    loading.innerHTML = '<p style="font-size:15px;">⏳ ' + __.t('common.loading') + '</p>';

    try {
        const res = await fetch(MAP_LIST_API, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        // 平台 URL 已随 mapList 返回（搭 git_remote 的便车）
        topoPlatformUrls = {
            jenkins_url: (data.jenkins_url || '').replace(/\/+$/, ''),
            harbor_url:  (data.harbor_url || '').replace(/\/+$/, ''),
        };
        processTopoData(data);
    } catch(e) {
        loading.innerHTML = '<p style="color:#dc2626;">⚠️ ' + __.t('js.network_error') + '</p><p style="font-size:13px;color:#9ca3af;margin-top:6px;">' + __.t('js.load_failed') + ': ' + esc(e.message) + '</p>';
    }
}

function processTopoData(data) {
    const loading = document.getElementById('topo-loading');
    const empty   = document.getElementById('topo-empty');

    // 服务端报错（统一 {code, message} 信封；401 已在 loadTopology 经 handle401 处理）
    if (data && data.code) {
        loading.innerHTML = `<p style="color:#dc2626;">⚠️ ${esc(data.message || '')}</p><p style="font-size:13px;color:#9ca3af;margin-top:4px;">HTTP ${esc(data.code)}</p>`;
        return;
    }

    const projects = data.projects || data.data || data;
    topoEntries = Array.isArray(projects) ? projects : Object.entries(projects).map(([k,v]) => ({project:k, ...v}));
    topoPage = 1;

    if (topoEntries.length === 0) {
        loading.style.display = 'none';
        empty.style.display = 'block';
        return;
    }

    renderTopology();
}

function renderTopology() {
    const loading = document.getElementById('topo-loading');
    const grid    = document.getElementById('topo-grid');
    const empty   = document.getElementById('topo-empty');
    const pagination = document.getElementById('topo-pagination');

    loading.style.display = 'none';
    grid.style.display = 'block';
    empty.style.display = 'none';

    const total = topoEntries.length;
    topoTotalPages = Math.max(1, Math.ceil(total / TOPO_PER_PAGE));
    if (topoPage > topoTotalPages) topoPage = topoTotalPages;
    const offset = (topoPage - 1) * TOPO_PER_PAGE;
    const slice  = topoEntries.slice(offset, offset + TOPO_PER_PAGE);

    grid.innerHTML = slice.map((p) => {
            const platform = p.git_platform || '—';
            const source   = p.platform_source || '';
            const method   = p.detection_method || '';
            let detectBadge = '';
            if (source === 'manual') detectBadge = '<span class="badge" style="background:#fef3c7;color:#d97706;">' + __.t('js.topo_manual') + '</span>';
            else if (method === 'fallback') detectBadge = '<span class="badge" style="background:#fef2f2;color:#dc2626;">' + __.t('js.topo_fallback') + '</span>';
            else if (method === 'exact') detectBadge = '<span class="badge" style="background:#ecfdf5;color:#065f46;">' + __.t('js.topo_exact') + '</span>';

            // Git
            const gitUrl = p.git_remote || '';
            const gitDisplay = gitUrl
                ? `<a href="${esc(gitUrl)}" target="_blank" title="${esc(gitUrl)}">${esc(truncateUrl(gitUrl))}</a>`
                : '<span class="topo-empty-field">' + __.t('js.topo_not_configured') + '</span>';

            // Harbor（链接到首页，因为具体仓库路径需要项目 ID，数据库里没有）
            const harbor = p.harbor_repository || '';
            const harborUrl = topoPlatformUrls.harbor_url || '';
            const harborDisplay = harbor
                ? `<a href="${esc(harborUrl + '/harbor')}" target="_blank" title="${esc(__.t('js.topo_open_harbor'))}">${esc(harbor)}</a>`
                : '<span class="topo-empty-field">' + __.t('js.topo_not_linked') + '</span>';

            const build = p.build_provider || 'jenkins';
            const buildLabel = build === 'jenkins' ? __.t('build.mode_jenkins')
                : build === 'gitlab_ci' ? __.t('build.mode_gitlab_ci')
                : build === 'custom_push' ? 'Custom_Push'
                : build;
            const buildIcon = build === 'gitlab_ci' ? '🐺' : build === 'custom_push' ? '📤' : '⚡';
            const buildUrl = topoPlatformUrls.jenkins_url || '';
            const projectPath = (p.project || p.current_path || '').replace(/\/+$/, '');
            const jenkinsPath = projectPath
                ? '/' + projectPath.split('/').map(s => 'job/' + encodeURIComponent(s)).join('/') + '/'
                : '';
            const buildDisplay = buildUrl
                ? `<a href="${esc(buildUrl + jenkinsPath)}" target="_blank" title="${esc(__.t('js.topo_open_jenkins'))}">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</a>`
                : `<span class="node-main">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</span>`;
            const platformCls = platform !== '—' && platforms.includes(platform) ? 'badge-' + platform : 'badge-default';
            const buildBadgeCls = build === 'gitlab_ci' ? 'badge-gitlab' : build === 'custom_push' ? 'badge-cus' : 'badge-default';

            return `<div class="topo-card">
                <div class="topo-header">
                    <span class="topo-project">📦 ${esc(p.project || p.current_path || __.t('js.topo_unnamed_project'))}</span>
                    <div class="topo-meta">
                        <span class="badge ${buildBadgeCls}">${buildLabel}</span>
                        <span class="badge ${platformCls}">${esc(platform)}</span>
                        ${detectBadge}
                    </div>
                </div>
                <div class="topo-flow">
                    <div class="topo-node">
                        <div class="node-label">🔗 ${__.t('js.topo_git_repo')}</div>
                        <div class="node-sub">${gitDisplay}</div>
                    </div>
                    <div class="topo-arrow">→</div>
                    <div class="topo-node">
                        <div class="node-label">${buildIcon} ${__.t('js.topo_build_source')}</div>
                        <div class="node-main">${buildDisplay}</div>
                    </div>
                    <div class="topo-arrow">→</div>
                    <div class="topo-node">
                        <div class="node-label">🐳 ${__.t('js.topo_harbor_image')}</div>
                        <div class="node-sub">${harborDisplay}</div>
                    </div>
                </div>
            </div>`;
        }).join('');

    // 分页控件
    if (topoTotalPages <= 1) {
        pagination.style.display = 'none';
    } else {
        pagination.style.display = 'flex';
        let pagHtml = '<span style="color:#6b7280;">' + __.t('js.topo_total_items', {total: total}) + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=1;renderTopology()" ' + (topoPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=Math.max(1,topoPage-1);renderTopology()" ' + (topoPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
        pagHtml += '<span style="color:#374151;font-weight:600;">' + topoPage + ' / ' + topoTotalPages + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=Math.min(topoTotalPages,topoPage+1);renderTopology()" ' + (topoPage>=topoTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=topoTotalPages;renderTopology()" ' + (topoPage>=topoTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
        pagination.innerHTML = pagHtml;
    }
}

function truncateUrl(url) {
    // 取 URL 中最后一段有意义的部分
    const cleaned = url.replace(/\.git$/, '').replace(/\/+$/, '');
    const parts = cleaned.split('/');
    const last = parts.pop() || '';
    const second = parts.pop() || '';
    if (second) return second + '/' + last;
    return last || url;
}

// ═══════════ API 版本管理 ═══════════
const VER_INFO = {
    gitlab: {label:'GitLab', desc: function(){return __.t('platform.gitlab_desc');}},
    gitee:  {label:'Gitee',  desc: function(){return __.t('platform.gitee_desc');}},
    github: {label:'GitHub', desc: function(){return __.t('platform.github_desc');}},
    gitea:  {label:'Gitea',  desc: function(){return __.t('platform.gitea_desc');}},
    harbor: {label:'Harbor', desc: function(){return __.t('platform.harbor_desc');}},
};
let currentVersions = {};

async function loadVersions() {
    document.getElementById('ver-loading').style.display = 'block';
    document.getElementById('ver-table-wrap').style.display = 'none';
    try {
        const res = await fetch(VERSIONS_API, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const raw = data.versions || {};
        // 兼容旧格式（纯字符串）和新格式（{value, source}）
        currentVersions = {};
        Object.entries(raw).forEach(([k, v]) => {
            currentVersions[k] = typeof v === 'object' ? v : {value: v, source: 'unknown'};
        });
        document.getElementById('ver-loading').style.display = 'none';
        document.getElementById('ver-table-wrap').style.display = 'block';
        const tbody = document.getElementById('ver-tbody');
        tbody.innerHTML = Object.entries(VER_INFO).map(([key, info]) => {
            const entry = currentVersions[key] || {value: '', source: 'default'};
            const val = entry.value || '';
            const src = entry.source;
            const defVer = getDefaultVer(key);
            const isDefault = val === defVer || val === '';

            let srcBadge = '';
            if (src === 'config')  srcBadge = '<span class="badge" style="background:#fde8e8;color:#c81e1e;">' + __.t('js.version_source_config') + '</span>';
            else if (src === 'json') srcBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;">' + __.t('js.version_source_admin') + '</span>';
            else srcBadge = '<span class="badge" style="background:#f3f4f6;color:#6b7280;">' + __.t('js.version_source_default') + '</span>';

            const readonly = src === 'config';
            const displayVal = readonly ? val : (isDefault ? '' : val);
            const ph = readonly ? val : defVer;
            const inputStyle = readonly
                ? 'width:120px;padding:6px 10px;background:#f9fafb;color:#9ca3af;cursor:not-allowed;'
                : (isDefault ? 'width:120px;padding:6px 10px;' : 'width:120px;padding:6px 10px;border-color:#f59e0b;background:#fffbeb;');

            return `<tr>
                <td><strong>${info.label}</strong> <span style="color:#9ca3af;font-size:11px;">(${key})</span></td>
                <td><code style="font-size:12px;color:#6b7280;">${esc(defVer)}</code></td>
                <td>${srcBadge}</td>
                <td><input data-platform="${key}" value="${esc(displayVal)}" placeholder="${esc(ph)}"
                      style="${inputStyle}" ${readonly ? 'readonly title="' + esc(__.t('js.version_config_readonly_title')) + '"' : ''}></td>
                <td style="font-size:12px;color:#6b7280;">${info.desc()}</td>
            </tr>`;
        }).join('');
        renderHarborCompat();
    } catch(e) {
        document.getElementById('ver-loading').innerHTML = '<p style="color:#dc2626;">' + __.t('js.load_failed') + ': ' + esc(e.message) + '</p>';
    }
}

function renderHarborCompat() {
    const el = document.getElementById('harbor-compat');
    if (!el) return;
    const h = currentVersions.harbor || {};
    const ver = h.detected_version || null;
    const support = h.robot_support || 'unknown';
    const isRobot = !!h.robot_account;

    let badge, text;
    if (support === 'supported') {
        badge = '✅'; text = __.t('js.harbor_robot_supported');
    } else if (support === 'unsupported') {
        badge = '⚠️'; text = __.t('js.harbor_robot_unsupported');
    } else {
        badge = '❓'; text = __.t('js.harbor_robot_unknown');
    }
    const verText = ver ? ver : __.t('js.harbor_version_unknown');

    let extra = '';
    if (isRobot && support === 'unsupported') {
        extra = ' <strong style="color:#c81e1e;">' + __.t('js.harbor_robot_warning') + '</strong>';
    }

    el.style.display = 'block';
    el.innerHTML = '<strong>🐳 Harbor</strong> · ' + __.t('js.harbor_detected_version')
        + ': <code>' + esc(verText) + '</code> · ' + badge + ' ' + text + extra;
}

function getDefaultVer(key) {
    const defaults = { gitlab:'v4', gitee:'v5', github:'v3', gitea:'v1', harbor:'v2.0' };
    return defaults[key] || '';
}

async function saveVersions() {
    const versions = {};
    document.querySelectorAll('#ver-tbody input').forEach(inp => {
        const val = inp.value.trim();
        if (val) versions[inp.dataset.platform] = val;
    });
    try {
        const res = await fetch(VERSIONS_API, {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({versions})
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            const st = document.getElementById('ver-status');
            st.style.display = 'inline';
            setTimeout(() => st.style.display = 'none', 2000);
            currentVersions = data.versions || {};
            loadVersions();
        } else {
            toast(data.message || __.t('js.save_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

// ── 构建模式配置 ──
let currentBuildMode = 'both';

/** 把下拉框当前值即时显示到右侧的 code 标签 */
function updateBuildModeValue() {
    const sel = document.getElementById('build-mode-select');
    const valEl = document.getElementById('build-mode-value');
    if (sel && valEl) valEl.textContent = sel.value || '—';
}

async function loadSettings() {
    const display = document.getElementById('mode-display');
    const configPanel = document.getElementById('mode-config');
    const sel = document.getElementById('build-mode-select');
    const cpToggle = document.getElementById('custom-push-toggle');
    const statusEl = document.getElementById('build-mode-status');
    try {
        const res = await fetch('/api/admin/build_mode', { headers: authHeaders() });
        if (res.status === 401) { display.innerHTML = '<span style="color:#9ca3af;">' + __.t('auth.please_login_first') + '</span>'; return; }
        const data = await res.json();
        const mode = data.mode || 'both';
        const hasJenkins = data.has_jenkins;
        const hasGitlab = data.has_gitlab_ci;
        const hasCustom = (data.custom_providers || []).length > 0;
        const cpEnabled = data.custom_push_enabled || false;
        const staleCleanup = data.stale_tag_cleanup_enabled || false;
        const customNames = data.custom_providers || [];
        const source = data.source || 'env';
        currentBuildMode = mode;
        currentCpEnabled = cpEnabled;
        currentStaleCleanupEnabled = staleCleanup;

        // 来源标识
        const srcLabel = source === 'database'
            ? '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_persisted') + '">✓ ' + __.t('js.mode_persisted') + '</span>'
            : '<span class="badge" style="background:#fefce8;color:#ca8a04;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_temp') + '">⚠️ ' + __.t('js.mode_temp') + '</span>';

        // 下拉选项状态：全部可选，后端校验不可用时返回错误
        sel.value = mode;
        updateBuildModeValue();
        // custom_push 开关
        if (cpToggle) {
            cpToggle.checked = cpEnabled;
        }
        const staleToggle = document.getElementById('stale-tag-cleanup-toggle');
        if (staleToggle) {
            staleToggle.checked = staleCleanup;
        }
        applyPushRecordsMenuVisibility(cpEnabled);
        configPanel.style.display = 'block';
        statusEl.style.display = 'none';

        // 状态图（流程卡片式）：拉取式（左）与推送式（右）并排，中间分界线
        let modeBadge = '', flow = '';
        const buildCi = (mode === 'gitlab_ci') ? '🐺 ' + __.t('js.mode_gitlab_ci_name') : '⚡ ' + __.t('js.mode_jenkins_name');
        const buildColor = (mode === 'gitlab_ci') ? '#c81e1e' : '#d97706';
        const buildBg = (mode === 'gitlab_ci') ? '#fce4ec' : '#fff8e1';
        const buildBorder = (mode === 'gitlab_ci') ? '#e91e63' : '#f59e0b';

        const node = (icon, label, bg, border, color, fontSize) =>
            '<div style="background:' + bg + ';border:2px solid ' + border + ';border-radius:10px;padding:14px 20px;text-align:center;min-width:110px;">'
            + '<div style="font-size:' + (fontSize || 24) + 'px;line-height:1;">' + icon + '</div>'
            + '<div style="margin-top:6px;font-size:13px;font-weight:600;color:' + color + ';">' + label + '</div>'
            + '</div>';
        const arrow = '<div style="color:#9ca3af;font-size:22px;line-height:1;">→</div>';
        const split = '<div style="color:#9ca3af;font-size:22px;line-height:1;">/</div>';

        // custom_push 启用状态徽标
        const customBadge = (cpEnabled && hasCustom)
            ? '<span class="badge" style="background:#fef3c7;color:#d97706;font-size:11px;margin-left:6px;">📤 ' + customNames.join(', ') + ' ✓</span>'
            : '';

        // ── 拉取式（左）：Glue 触发 CI 构建，CI 拉取代码并推送镜像到 Harbor ──
        let pullInner = '';
        if (hasJenkins && hasGitlab) {
            if (mode === 'both') {
                modeBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;font-size:13px;">⚡ ' + __.t('js.mode_jenkins_name') + ' + 🐺 ' + __.t('js.mode_gitlab_ci_name') + ' ' + __.t('js.mode_coexist') + '</span>';
                pullInner = node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                    + arrow
                    + node('⚡', __.t('js.mode_jenkins_name'), '#fff8e1', '#f59e0b', '#d97706', 24)
                    + split
                    + node('🐺', __.t('js.mode_gitlab_ci_name'), '#fce4ec', '#e91e63', '#c81e1e', 24)
                    + arrow
                    + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24);
            } else {
                modeBadge = '<span class="badge" style="background:' + buildBg + ';color:' + buildColor + ';font-size:13px;">' + buildCi + ' ' + __.t('js.mode_mode') + '</span>';
                const icon = (mode === 'gitlab_ci') ? '🐺' : '⚡';
                const name = (mode === 'gitlab_ci') ? __.t('js.mode_gitlab_ci_name') : __.t('js.mode_jenkins_name');
                pullInner = node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                    + arrow
                    + node(icon, name, buildBg, buildBorder, buildColor, 24)
                    + arrow
                    + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24);
            }
        } else if (hasGitlab) {
            modeBadge = '<span class="badge" style="background:#fce4ec;color:#c81e1e;font-size:13px;">🐺 ' + __.t('js.mode_gitlab_ci_name') + ' ' + __.t('js.mode_mode') + '</span>';
            pullInner = node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                + arrow
                + node('🐺', __.t('js.mode_gitlab_ci_name'), '#fce4ec', '#e91e63', '#c81e1e', 24)
                + arrow
                + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24);
        } else {
            modeBadge = '<span class="badge" style="background:#fff8e1;color:#d97706;font-size:13px;">⚡ ' + __.t('js.mode_jenkins_name') + ' ' + __.t('js.mode_mode') + '</span>';
            pullInner = node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                + arrow
                + node('⚡', __.t('js.mode_jenkins_name'), '#fff8e1', '#f59e0b', '#d97706', 24)
                + arrow
                + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24);
        }
        const hasPull = hasJenkins || hasGitlab;

        // ── 推送式（右）：用户 CI 自行构建并推送镜像到 Harbor，Glue 只接收回写 ──
        const hasPush = cpEnabled && hasCustom;
        let pushInner = '';
        if (hasPush) {
            pushInner = node('📤', __.t('js.mode_user_ci'), '#fef3c7', '#f59e0b', '#d97706', 24)
                + arrow
                + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24)
                + arrow
                + node('📋', 'Devops-Glue', '#f0fdf4', '#16a34a', '#15803d', 24);
        }

        // ── 左右并排 + 中间分界线 ──
        const side = (title, desc, inner) =>
            '<div style="flex:1;min-width:280px;padding:16px 14px;display:flex;flex-direction:column;align-items:center;gap:10px;">'
            + '<div style="font-size:12px;font-weight:700;letter-spacing:2px;color:#6b7280;">' + title + '</div>'
            + '<div style="font-size:11px;color:#9ca3af;text-align:center;max-width:340px;">' + desc + '</div>'
            + '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:center;">' + inner + '</div>'
            + '</div>';

        if (hasPull && hasPush) {
            flow = '<div style="margin-top:14px;display:flex;align-items:stretch;justify-content:center;flex-wrap:wrap;">'
                + side(__.t('js.mode_pull_title'), __.t('js.mode_pull_desc'), pullInner)
                + '<div style="width:1px;align-self:stretch;background:#e5e7eb;margin:8px 0;"></div>'
                + side(__.t('js.mode_push_title'), __.t('js.mode_push_desc'), pushInner)
                + '</div>';
        } else if (hasPull) {
            flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;">'
                + side(__.t('js.mode_pull_title'), __.t('js.mode_pull_desc'), pullInner)
                + '</div>';
        } else if (hasPush) {
            flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;">'
                + side(__.t('js.mode_push_title'), __.t('js.mode_push_desc'), pushInner)
                + '</div>';
        }

        display.innerHTML = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
            + modeBadge + srcLabel + customBadge
            + '</div>'
            + flow;
        updateDiscoverButton();

    } catch(e) {
        display.innerHTML = '<span style="color:#9ca3af;">' + __.t('js.mode_cannot_detect') + '</span>';
    }
}

let currentCpEnabled = false;
let currentStaleCleanupEnabled = false;

async function onBuildModeChange() {
    const sel = document.getElementById('build-mode-select');
    const newMode = sel.value;
    const oldMode = currentBuildMode;
    updateBuildModeValue(); // 即时显示新选中的值

    // 先回退选择，待确认后再正式切换
    sel.value = oldMode;
    updateBuildModeValue(); // 回退后显示旧值

    const modeLabels = {
        'jenkins': '⚡ ' + __.t('js.mode_label_jenkins'),
        'gitlab_ci': '🐺 ' + __.t('js.mode_label_gitlab_ci'),
        'both': '⚡ ' + __.t('js.mode_label_both')
    };

    if (!confirm(__.t('js.mode_switch_confirm', {mode: modeLabels[newMode] || newMode}))) {
        return;
    }

    // 用户确认，正式切换
    sel.value = newMode;
    updateBuildModeValue();

    const statusEl = document.getElementById('build-mode-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({mode: newMode, custom_push_enabled: currentCpEnabled, stale_tag_cleanup_enabled: currentStaleCleanupEnabled})
        });
        if (handle401(res)) { sel.value = oldMode; currentBuildMode = oldMode; updateBuildModeValue(); return; }
        if (res.ok) {
            currentBuildMode = newMode;
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
            loadSettings();
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            sel.value = oldMode;
            currentBuildMode = oldMode;
            updateBuildModeValue();
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        sel.value = oldMode;
        currentBuildMode = oldMode;
        updateBuildModeValue();
    }
}

async function onCustomPushToggle() {
    const cpToggle = document.getElementById('custom-push-toggle');
    const newEnabled = cpToggle.checked;
    const oldEnabled = currentCpEnabled;

    if (!confirm(newEnabled
        ? __.t('js.mode_label_custom_push')
        : __.t('js.custom_push_disable_confirm'))) {
        cpToggle.checked = oldEnabled;
        return;
    }

    const statusEl = document.getElementById('build-mode-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({mode: currentBuildMode, custom_push_enabled: newEnabled, stale_tag_cleanup_enabled: currentStaleCleanupEnabled})
        });
        if (handle401(res)) { cpToggle.checked = oldEnabled; return; }
        if (res.ok) {
            currentCpEnabled = newEnabled;
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
            loadSettings();
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            cpToggle.checked = oldEnabled;
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        cpToggle.checked = oldEnabled;
    }
}

async function onStaleTagCleanupToggle() {
    const staleToggle = document.getElementById('stale-tag-cleanup-toggle');
    const newEnabled = staleToggle.checked;
    const oldEnabled = currentStaleCleanupEnabled;

    if (!confirm(newEnabled
        ? __.t('js.stale_tag_cleanup_enable_confirm')
        : __.t('js.stale_tag_cleanup_disable_confirm'))) {
        staleToggle.checked = oldEnabled;
        return;
    }

    const statusEl = document.getElementById('build-mode-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({mode: currentBuildMode, custom_push_enabled: currentCpEnabled, stale_tag_cleanup_enabled: newEnabled})
        });
        if (handle401(res)) { staleToggle.checked = oldEnabled; return; }
        if (res.ok) {
            currentStaleCleanupEnabled = newEnabled;
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            staleToggle.checked = oldEnabled;
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        staleToggle.checked = oldEnabled;
    }
}

// ═══════════ push 记录 ═══════════

let pushPage = 1, pushTotalPages = 1;

/** Custom_Push 启用时显示「push 记录」菜单，关闭时隐藏 */
function applyPushRecordsMenuVisibility(cpEnabled) {
    var item = document.getElementById('menu-push-records');
    if (item) item.style.display = cpEnabled ? '' : 'none';
}

async function loadPushRecords() {
    const tbody = document.getElementById('push-records-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#9ca3af;">' + __.t('common.loading') + '</td></tr>';
    try {
        const res = await fetch('/api/admin/custom_builds?page=' + pushPage + '&per_page=20', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const records = data.records || [];
        const total = data.total || 0;
        pushTotalPages = data.total_pages || 1;
        if (pushPage > pushTotalPages) pushPage = pushTotalPages;

        const pagination = document.getElementById('push-pagination');
        if (!records.length) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#9ca3af;">' + __.t('push.no_records') + '</td></tr>';
            if (pagination) pagination.innerHTML = '';
            return;
        }
        const statusBadge = (s) => {
            const st = (s || '').toLowerCase();
            let bg = '#f3f4f6', fg = '#6b7280';
            if (st === 'success') { bg = '#ecfdf5'; fg = '#065f46'; }
            else if (st === 'failed' || st === 'canceled') { bg = '#fef2f2'; fg = '#dc2626'; }
            else if (st === 'running') { bg = '#dbeafe'; fg = '#1d4ed8'; }
            else if (st === 'pending') { bg = '#fef3c7'; fg = '#d97706'; }
            return '<span class="badge" style="background:' + bg + ';color:' + fg + ';">' + esc(s || '—') + '</span>';
        };
        tbody.innerHTML = records.map(r => {
            const vars = r.variables_json || '';
            const logCell = r.log_url
                ? '<a href="' + esc(r.log_url) + '" target="_blank" rel="noopener noreferrer">' + esc(__.t('push.view_log')) + '</a>'
                : '—';
            const webCell = r.web_url
                ? '<a href="' + esc(r.web_url) + '" target="_blank" rel="noopener noreferrer" style="display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;" title="' + esc(r.web_url) + '">' + esc(r.web_url) + '</a>'
                : '—';
            const shaCell = r.sha ? '<code style="font-size:11px;word-break:break-all;">' + esc(r.sha) + '</code>' : '—';
            return '<tr>'
                + '<td>' + esc(r.job_name) + '</td>'
                + '<td>' + esc(String(r.pipeline_iid)) + '</td>'
                + '<td>' + statusBadge(r.status) + '</td>'
                + '<td>' + (r.tag ? '<code style="font-size:11px;">' + esc(r.tag) + '</code>' : '—') + '</td>'
                + '<td>' + shaCell + '</td>'
                + '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (vars ? esc(vars) : '') + '">' + (vars ? esc(vars) : '—') + '</td>'
                + '<td>' + logCell + '</td>'
                + '<td>' + webCell + '</td>'
                + '<td>' + esc(r.finished_at || '') + '</td>'
                + '</tr>';
        }).join('');

        if (pagination) {
            let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: total}) + '</span>';
            pag += '<button class="btn btn-sm" onclick="pushPage=1;loadPushRecords()" ' + (pushPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pag += '<button class="btn btn-sm" onclick="pushPage=Math.max(1,pushPage-1);loadPushRecords()" ' + (pushPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pag += '<span style="color:#374151;font-weight:600;">' + pushPage + ' / ' + pushTotalPages + '</span>';
            pag += '<button class="btn btn-sm" onclick="pushPage=Math.min(pushTotalPages,pushPage+1);loadPushRecords()" ' + (pushPage>=pushTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pag += '<button class="btn btn-sm" onclick="pushPage=pushTotalPages;loadPushRecords()" ' + (pushPage>=pushTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            pagination.innerHTML = pag;
        }
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#dc2626;">' + __.t('js.network_error') + ': ' + esc(e.message) + '</td></tr>';
    }
}

// ── 密码修改 ──
async function changePassword(e) {
    e.preventDefault();
    const oldP = document.getElementById('old-pass').value;
    const newP = document.getElementById('new-pass').value;
    const new2 = document.getElementById('new-pass2').value;
    const msg  = document.getElementById('pwd-msg');
    if (newP !== new2) { msg.textContent = __.t('js.password_mismatch'); msg.style.color = '#dc2626'; return; }
    if (newP.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#dc2626'; return; }
    try {
        const res = await fetch('/api/admin/password', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({old_password: oldP, new_password: newP})
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ ' + __.t('auth.password_updated');
            msg.style.color = '#16a34a';
            setTimeout(() => doLogout(), 1500);
        } else {
            msg.textContent = data.message || __.t('js.modify_failed');
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = __.t('js.network_error'); msg.style.color = '#dc2626'; }
}

// ═══════════ 权限管理 ═══════════
// 权限列表
async function loadPermList() {
    var loading = document.getElementById('perm-list-loading');
    var tableWrap = document.getElementById('perm-list-table-wrap');
    var tbody = document.getElementById('perm-list-tbody');
    var empty = document.getElementById('perm-list-empty');
    loading.style.display = 'block';
    tableWrap.style.display = 'none';
    empty.style.display = 'none';
    try {
        var res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        var perms = data.permissions || [];
        if (perms.length === 0) {
            loading.style.display = 'none';
            empty.style.display = 'block';
            return;
        }
        var canDelete = hasPermission('ci.permissions.register');
        tbody.innerHTML = perms.map(function(p) {
            var type = p.is_builtin
                ? '<span class="badge badge-sys">' + __.t('perm.builtin') + '</span>'
                : '<span class="badge badge-cus">' + __.t('perm.registered') + '</span>';
            var actions;
            if (p.is_builtin) {
                actions = '<span style="color:#9ca3af;font-size:12px;" title="' + __.t('permission.builtin_protected') + '">🔒</span>';
            } else if (canDelete) {
                actions = '<button class="btn btn-sm btn-del" onclick="deletePermission(\'' + p.perm_key + '\')" title="' + __.t('common.delete') + '">🗑</button>';
            } else {
                actions = '<span style="color:#9ca3af;font-size:12px;">—</span>';
            }
            return '<tr>' +
                '<td><code>' + p.perm_key + '</code></td>' +
                '<td>' + esc(p.description || '-') + '</td>' +
                '<td>' + (p.parent_key ? '<code>' + p.parent_key + '</code>' : '-') + '</td>' +
                '<td>' + type + '</td>' +
                '<td style="font-size:12px;color:#6b7280;">' + (p.created_at || '—') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        }).join('');
        loading.style.display = 'none';
        tableWrap.style.display = 'block';
    } catch(e) {
        loading.textContent = __.t('js.network_error') + ': ' + e.message;
    }
}

// 删除已注册权限（内置权限后端已保护，此入口仅对非内置且拥有 ci.permissions.register 者可见）
async function deletePermission(key) {
    if (!confirm(__.t('perm.confirm_delete'))) return;
    try {
        var res = await fetch('/api/admin/permissions/' + encodeURIComponent(key), {
            method: 'DELETE',
            headers: authHeaders()
        });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) {
            toast(__.t('perm.delete_ok'), true);
            _allPerms = null; // 失效角色编辑器的权限缓存
            loadPermList();
            loadRoleList();
        } else {
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

// 权限注册
async function registerPermission(e) {
    e.preventDefault();
    var key = document.getElementById('new-perm-key').value.trim();
    var desc = document.getElementById('new-perm-desc').value.trim();
    var parent = document.getElementById('new-perm-parent').value.trim();
    var msg = document.getElementById('perm-register-msg');
    if (!key) { msg.textContent = __.t('perm.key_required'); msg.style.color = '#dc2626'; return; }
    if (!desc) { msg.textContent = __.t('perm.desc_required'); msg.style.color = '#dc2626'; return; }
    try {
        var res = await fetch('/api/admin/permissions', {
            method: 'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, authHeaders()),
            body: JSON.stringify({ perm_key: key, description: desc || null, parent_key: parent || null })
        });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ ' + __.t('perm.register_ok');
            msg.style.color = '#16a34a';
            document.getElementById('new-perm-key').value = '';
            document.getElementById('new-perm-desc').value = '';
            document.getElementById('new-perm-parent').value = '';
            loadPermList();
            loadRoleList();
        } else {
            msg.textContent = data.message || __.t('js.operation_failed');
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = __.t('js.network_error'); msg.style.color = '#dc2626'; }
}

// 隐含规则
async function loadImpliedRules() {
    var loading = document.getElementById('implied-loading');
    var tableWrap = document.getElementById('implied-table-wrap');
    var tbody = document.getElementById('implied-tbody');
    var empty = document.getElementById('implied-empty');
    loading.style.display = 'block';
    tableWrap.style.display = 'none';
    empty.style.display = 'none';
    try {
        var res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        var implied = data.implied || {};
        var builtin = data.builtin_implied || {};
        var rules = [];
        Object.keys(implied).forEach(function(src) {
            implied[src].forEach(function(tgt) { rules.push({source: src, target: tgt}); });
        });
        if (rules.length === 0) {
            loading.style.display = 'none';
            empty.style.display = 'block';
            return;
        }
        tbody.innerHTML = rules.map(function(r) {
            var isBuiltin = (builtin[r.source] || []).indexOf(r.target) >= 0;
            var type = isBuiltin
                ? '<span class="badge badge-sys">' + __.t('perm.builtin') + '</span>'
                : '<span class="badge badge-cus">' + __.t('perm.registered') + '</span>';
            var actions = isBuiltin
                ? '<span style="color:#9ca3af;font-size:12px;" title="' + __.t('implied.builtin_protected') + '">🔒</span>'
                : '<button class="btn btn-danger btn-sm" onclick="deleteImpliedRule(\'' + r.source + '\',\'' + r.target + '\')">' + __.t('common.delete') + '</button>';
            return '<tr>' +
                '<td><code>' + r.source + '</code></td>' +
                '<td><code>' + r.target + '</code></td>' +
                '<td>' + type + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        }).join('');
        loading.style.display = 'none';
        tableWrap.style.display = 'block';
    } catch(e) {
        loading.textContent = __.t('js.network_error') + ': ' + e.message;
    }
}

async function showImpliedForm() {
    var form = document.getElementById('implied-form');
    var srcSel = document.getElementById('implied-source');
    var tgtSel = document.getElementById('implied-target');
    form.style.display = 'block';
    form.scrollIntoView({behavior: 'smooth'});
    // 加载权限列表到下拉框
    try {
        var res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        var perms = data.permissions || [];
        var opts = perms.map(function(p) { return '<option value="' + p.perm_key + '">' + p.perm_key + '</option>'; }).join('');
        srcSel.innerHTML = '<option value="">' + __.t('common.please_select') + '</option>' + opts;
        tgtSel.innerHTML = '<option value="">' + __.t('common.please_select') + '</option>' + opts;
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

function hideImpliedForm() {
    document.getElementById('implied-form').style.display = 'none';
    document.getElementById('implied-source').value = '';
    document.getElementById('implied-target').value = '';
    document.getElementById('implied-msg').textContent = '';
}

async function submitImpliedForm(e) {
    e.preventDefault();
    var src = document.getElementById('implied-source').value;
    var tgt = document.getElementById('implied-target').value;
    var msg = document.getElementById('implied-msg');
    if (!src || !tgt) { msg.textContent = __.t('implied.both_required'); msg.style.color = '#dc2626'; return; }
    if (src === tgt) { msg.textContent = __.t('implied.cannot_same'); msg.style.color = '#dc2626'; return; }
    try {
        var res = await fetch('/api/admin/implied_rules', {
            method: 'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, authHeaders()),
            body: JSON.stringify({ source_key: src, target_key: tgt })
        });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ ' + __.t('implied.add_ok');
            msg.style.color = '#16a34a';
            hideImpliedForm();
            loadImpliedRules();
        } else {
            msg.textContent = data.message || __.t('js.operation_failed');
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = __.t('js.network_error'); msg.style.color = '#dc2626'; }
}

async function deleteImpliedRule(src, tgt) {
    if (!confirm(__.t('implied.confirm_delete'))) return;
    try {
        var res = await fetch('/api/admin/implied_rules?source_key=' + encodeURIComponent(src) + '&target_key=' + encodeURIComponent(tgt), {
            method: 'DELETE',
            headers: authHeaders()
        });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) {
            toast(__.t('implied.delete_ok'), true);
            loadImpliedRules();
        } else {
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

// ── 表单 ──
function showForm(editData) {
    if (currentMapView !== 'table') switchMapView('table');
    const panel = document.getElementById('form-panel');
    panel.classList.add('show');
    if (editData) {
        document.getElementById('form-title').textContent = __.t('js.edit_mapping') + ': ' + editData.job_name;
        document.getElementById('original-name').value = editData.job_name;
        document.getElementById('f-job_name').value = editData.job_name || '';
        document.getElementById('f-job_name').readOnly = true;
        document.getElementById('f-git_platform').value = editData.git_platform || '';
        document.getElementById('f-build_provider').value = editData.build_provider || '';
        document.getElementById('f-status').value = editData.status || 'active';
        document.getElementById('f-git_remote').value = editData.git_remote || '';
        document.getElementById('f-project_id').value = editData.project_id ?? '';
        document.getElementById('f-web_url').value = editData.web_url || '';
        document.getElementById('f-current_path').value = editData.current_path || '';
        document.getElementById('f-harbor_repository').value = editData.harbor_repository || '';
    } else {
        document.getElementById('form-title').textContent = __.t('map.new');
        document.getElementById('original-name').value = '';
        document.getElementById('f-job_name').readOnly = false;
        document.getElementById('map-form').reset();
    }
    // Custom_Push 未启用时禁用该选项并提示
    const cpOption = document.querySelector('#f-build_provider option[value="custom_push"]');
    const cpHint = document.getElementById('cp-disabled-hint');
    if (cpOption) cpOption.disabled = !currentCpEnabled;
    if (cpHint) cpHint.style.display = currentCpEnabled ? 'none' : '';
    panel.scrollIntoView({behavior:'smooth'});
}

function hideForm() {
    document.getElementById('form-panel').classList.remove('show');
}

async function submitForm(e) {
    e.preventDefault();
    const original = document.getElementById('original-name').value;
    const isEdit = !!original;
    const body = {
        job_name: document.getElementById('f-job_name').value.trim(),
        git_platform: document.getElementById('f-git_platform').value,
        build_provider: document.getElementById('f-build_provider').value,
        status: document.getElementById('f-status').value,
        git_remote: document.getElementById('f-git_remote').value.trim(),
        project_id: document.getElementById('f-project_id').value.trim(),
        web_url: document.getElementById('f-web_url').value.trim(),
        current_path: document.getElementById('f-current_path').value.trim(),
        harbor_repository: document.getElementById('f-harbor_repository').value.trim(),
    };
    if (isEdit) body._original_job_name = original;
    Object.keys(body).forEach(k => { if (body[k] === '') body[k] = null; });

    try {
        const res = await fetch(MAP_API, {
            method: isEdit ? 'PUT' : 'POST',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify(body)
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            if (body.build_provider === 'custom_push' && !currentCpEnabled) {
                toast(__.t('js.cp_saved_as_pending'), true);
            } else {
                toast(isEdit ? __.t('js.already_updated') : __.t('js.already_added'), true);
            }
            hideForm();
            loadMaps();
        } else {
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

function editMap(item) { showForm(item); }

function statusBadge(s) {
    s = s || 'active';
    if (s === 'pending') return '<span class="badge" style="background:#fef3c7;color:#d97706;">' + __.t('common.pending') + '</span>';
    if (s === 'disabled') return '<span class="badge" style="background:#fef2f2;color:#dc2626;">' + __.t('common.disabled') + '</span>';
    return '<button class="btn btn-sm" disabled style="background:#dcfce7;color:#16a34a;border:1px solid #86efac;cursor:default;">✅ ' + __.t('common.enabled') + '</button>';
}

async function activateMap(jobName, item) {
    // 防御：provider 与当前配置模式不匹配时直接拒绝
    const bp = item.build_provider || 'jenkins';
    const isBuiltinBp = bp === 'jenkins' || bp === 'gitlab_ci';
    if (isBuiltinBp && currentBuildMode !== 'both' && bp !== currentBuildMode) {
        const curLabel = currentBuildMode === 'jenkins' ? __.t('js.mode_jenkins_name') : __.t('js.mode_gitlab_ci_name');
        const itemLabel = bp === 'gitlab_ci' ? __.t('js.mode_gitlab_ci_name') : __.t('js.mode_jenkins_name');
        toast(__.t('js.cannot_activate_mode', {mode: curLabel, item: itemLabel}), false);
        return;
    }
    // 防御：Custom_Push 未启用时不允许启用 custom_push 映射
    if (bp === 'custom_push' && !currentCpEnabled) {
        toast(__.t('js.cp_disabled_hint'), false);
        return;
    }
    if (!confirm(__.t('js.activate_confirm') + ' "' + jobName + '"?\n\n' + __.t('js.activate_warn_hide'))) return;
    try {
        item._original_job_name = jobName;
        item.status = 'active';
        // 清理 null 值，避免提交异常
        Object.keys(item).forEach(k => { if (item[k] === '' || item[k] === null) item[k] = null; });
        const res = await fetch(MAP_API, {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify(item)
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            toast(__.t('js.activated') + ' ' + jobName, true);
            loadMaps();
        } else {
            toast(data.message || __.t('js.activate_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

async function deleteMap(jobName) {
    if (!confirm(__.t('js.delete_confirm') + ' "' + jobName + '"?')) return;
    try {
        const res = await fetch(MAP_API + '?job_name=' + encodeURI(jobName), { method:'DELETE', headers:authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
            if (res.ok) { toast(__.t('js.already_deleted') + ' ' + jobName, true); hideForm(); loadMaps(); }
            else { toast(data.message || __.t('js.delete_failed'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

// ═══════════ 安全审计 ═══════════
const SEC_API = '/api/admin/security_checks';
let secPage = 1, secTotalPages = 1, secDebounce = null;
const STATE_ICONS = {success:'✅',failed:'❌',error:'⚠️',pending:'⏳'};
const STATE_LABELS = {success:'security.passed',failed:'security.failed',error:'security.error',pending:'security.pending'};
const WB_ICONS = {success:'✅', failed:'❌', skipped:'⏭️'};
const WB_LABELS = {success:'security.writeback_success', failed:'security.writeback_failed', skipped:'security.writeback_skipped'};

function secOnFilterChange() {
    clearTimeout(secDebounce);
    secDebounce = setTimeout(() => { secPage = 1; loadSecurityChecks(); }, 300);
}

async function loadSecurityChecks() {
    try {
        const project = document.getElementById('sec-search-project')?.value?.trim() || '';
        const checkType = document.getElementById('sec-filter-type')?.value || '';
        const state = document.getElementById('sec-filter-state')?.value || '';
        const writeback = document.getElementById('sec-filter-writeback')?.value || '';
        const params = new URLSearchParams();
        if (project) params.set('project', project);
        if (checkType) params.set('check_type', checkType);
        if (state) params.set('state', state);
        if (writeback) params.set('writeback', writeback);
        params.set('page', secPage);
        params.set('per_page', '20');
        const url = SEC_API + '?' + params.toString();
        const res = await fetch(url, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const checks = data.checks || [];
        const total = data.total || 0;
        secTotalPages = data.total_pages || 1;

        // Ensure page is valid
        if (secPage > secTotalPages) secPage = secTotalPages;

        // 更新类型下拉（合并现有选项）
        if (data.filter_opts?.check_types) {
            const sel = document.getElementById('sec-filter-type');
            const cur = sel.value;
            sel.innerHTML = '<option value="">' + __.t('map.all_types') + '</option>';
            data.filter_opts.check_types.forEach(t => { sel.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`; });
            if (data.filter_opts.check_types.includes(cur)) sel.value = cur;
        }

        document.getElementById('sec-loading').style.display = 'none';
        const tw = document.getElementById('sec-table-wrap');
        const em = document.getElementById('sec-empty');

        if (checks.length === 0) {
            tw.style.display = 'none'; em.style.display = 'block';
        } else {
            em.style.display = 'none'; tw.style.display = 'block';
            document.getElementById('sec-tbody').innerHTML = checks.map(c => {
                const icon = STATE_ICONS[c.state] || '❓';
                const label = (STATE_LABELS[c.state] ? __.t(STATE_LABELS[c.state]) : c.state) || '—';
                const cls = c.state === 'success' ? 'ok' : c.state === 'failed' ? 'err' : 'off';
                const shaShort = (c.sha || '').substring(0, 8);
                const time = (c.created_at || '').replace('T',' ').substring(0, 19);
                const wb = c.writeback_status || '';
                const wbCell = wb
                    ? `<span class="svc-stat ${wb === 'success' ? 'ok' : wb === 'failed' ? 'err' : 'off'}" title="${esc(c.writeback_message || '')}">${WB_ICONS[wb] || ''} ${WB_LABELS[wb] ? __.t(WB_LABELS[wb]) : wb}</span>`
                    : '<span style="color:#9ca3af;">—</span>';
                return `<tr>
                    <td style="font-size:12px;white-space:nowrap;color:#6b7280;">${esc(time)}</td>
                    <td><strong>${esc(c.project)}</strong></td>
                    <td><span class="badge badge-default">${esc(c.check_type || '—')}</span></td>
                    <td><span class="svc-stat ${cls}">${icon} ${label}</span></td>
                    <td>${esc(c.tag || '—')}</td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(c.description||'')}">${esc(c.description || '—')}</td>
                    <td><code style="font-size:11px;color:#6b7280;" title="${esc(c.sha||'')}">${esc(shaShort)}</code></td>
                    <td>${wbCell}</td>
                </tr>`;
            }).join('');

            let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: total}) + '</span>';
            pag += '<button class="btn btn-sm" onclick="secPage=1;loadSecurityChecks()" ' + (secPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pag += '<button class="btn btn-sm" onclick="secPage=Math.max(1,secPage-1);loadSecurityChecks()" ' + (secPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pag += '<span style="color:#374151;font-weight:600;">' + secPage + ' / ' + secTotalPages + '</span>';
            pag += '<button class="btn btn-sm" onclick="secPage=Math.min(secTotalPages,secPage+1);loadSecurityChecks()" ' + (secPage>=secTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pag += '<button class="btn btn-sm" onclick="secPage=secTotalPages;loadSecurityChecks()" ' + (secPage>=secTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            document.getElementById('sec-pagination').innerHTML = pag;
        }
    } catch(e) {
        document.getElementById('sec-loading').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

// ═══════════ 用户管理 ═══════════
function isAdminRole(role) { return role === 'admin' || role === 'super_admin'; }

function roleLabel(user) {
    if (user.role === 'super_admin') return '👑 ' + __.t('user.role_super_admin');
    return __.t('user.role_' + user.role) || user.role;
}

// 角色缓存（动态从后端获取）
var _rolesCache = null;
async function loadRoles() {
    if (_rolesCache) return _rolesCache;
    try {
        var res = await fetch('/api/admin/roles', { headers: authHeaders() });
        if (handle401(res)) return [];
        _rolesCache = await res.json();
        return _rolesCache;
    } catch (e) {
        console.error('loadRoles failed:', e);
        return [];
    }
}

async function loadUsers() {
    document.getElementById('user-msg').textContent = '';
    try {
        const res = await fetch('/api/admin/users', { headers: authHeaders() });
        if (!res.ok && res.status === 403) { alert(__.t('user.cannot_create_admin')); return; }
        if (handle401(res)) return;
        const result = await res.json();
        const allUsers = Array.isArray(result) ? result : (result.data || []);
        const users = isAdminRole(currentUserRole) ? allUsers : allUsers.filter(u => !isAdminRole(u.role));
        const tbody = document.getElementById('user-tbody');
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;">' + __.t('user.no_users') + '</td></tr>';
            return;
        }
        tbody.innerHTML = users.map(u => {
            const time = u.updated_at || '-';
            const isSuperAdmin = u.role === 'super_admin';
            const isAdmin = u.role === 'admin';
            const isSelf = u.username === currentUserName;
            let actions = '';
            // 超级管理员可修改任意用户（deployer/admin/viewer）的密码；根账号（自己）走「修改密码」菜单
            const modPwBtn = (currentUserRole === 'super_admin' && !isSelf)
                ? `<button class="btn btn-sm btn-edit" onclick="modifyUserPassword('${escJs(esc(u.username))}')">🔑 ${__.t('user.modify_password')}</button>`
                : '';
            if (isSuperAdmin) {
                actions = modPwBtn || `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_super_admin')}</span>`;
            } else if (isSelf) {
                actions = `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
            } else if (isAdmin) {
                if (currentUserIsRoot) {
                    actions = modPwBtn + `<button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(esc(u.username))}')">🗑 ${__.t('common.delete')}</button>`;
                } else {
                    actions = modPwBtn || `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
                }
            } else {
                actions = modPwBtn + `<button class="btn btn-sm btn-edit" onclick='showUserEditForm(${js(u)})'>✏️ ${__.t('common.edit')}</button>
                    <button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(esc(u.username))}')">🗑 ${__.t('common.delete')}</button>`;
            }
            return `<tr>
                <td><strong>${esc(u.username)}</strong></td>
                <td>${roleLabel(u)}</td>
                <td>${esc(u.systems || '-')}</td>
                <td style="font-size:12px;color:#6b7280;">${esc(time)}</td>
                <td style="white-space:nowrap">${actions}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        document.getElementById('user-tbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;">' + __.t('js.load_failed') + ': ' + e.message + '</td></tr>';
    }
}

async function showUserForm() {
    document.getElementById('user-form').style.display = 'block';
    document.getElementById('edit-target-username').value = '';
    document.getElementById('user-form-title').textContent = __.t('user.add_user');
    document.getElementById('new-username-wrap').style.display = 'block';
    document.getElementById('new-username').value = '';
    document.getElementById('new-username').required = true;
    document.getElementById('new-password').value = '';
    document.getElementById('new-password').required = true;
    document.getElementById('new-password').placeholder = __.t('form.placeholder_password');
    document.getElementById('new-password-label').textContent = __.t('user.password');
    try {
        await populateRoleSelect('deployer');
    } catch (e) {
        document.getElementById('user-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('user-msg').style.color = '#ef4444';
        return;
    }
    document.getElementById('new-systems-wrap').style.display = 'block';
    populateSystemsSelect('cd');
    document.getElementById('user-msg').textContent = '';
}

async function showUserEditForm(user) {
    document.getElementById('user-form').style.display = 'block';
    document.getElementById('edit-target-username').value = user.username;
    document.getElementById('user-form-title').textContent = __.t('user.edit_user') + ': ' + user.username;
    document.getElementById('new-username-wrap').style.display = 'none';
    document.getElementById('new-username').value = user.username;
    document.getElementById('new-username').required = false;
    document.getElementById('new-password').value = '';
    document.getElementById('new-password').required = false;
    document.getElementById('new-password').placeholder = __.t('user.password_keep_empty');
    document.getElementById('new-password-label').textContent = __.t('user.new_password_optional');
    try {
        await populateRoleSelect(user.role);
    } catch (e) {
        document.getElementById('user-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('user-msg').style.color = '#ef4444';
        return;
    }
    document.getElementById('new-systems-wrap').style.display = 'none';
    document.getElementById('user-msg').textContent = '';
}

// 根据当前用户角色动态填充角色下拉（从后端 roles 表获取）
async function populateRoleSelect(selected) {
    var sel = document.getElementById('new-role');
    sel.innerHTML = '';
    var roles = await loadRoles();
    var canManageAdmin = hasPermission('ci.users.manage_admin');
    roles.forEach(function(r) {
        // super_admin 角色只能由拥有 ci.users.manage_admin 权限的用户分配
        if (r.name === 'super_admin' && !canManageAdmin) return;
        sel.add(new Option(r.description || __.t('user.role_' + r.name) || r.name, r.name));
    });
    // 确保 selected 值在选项中存在，否则选第一个
    var found = Array.from(sel.options).some(function(o) { return o.value === selected; });
    if (found) {
        sel.value = selected;
    } else if (sel.options.length > 0) {
        sel.selectedIndex = 0;
    }
}

// 根据当前用户角色动态填充系统下拉
function populateSystemsSelect(selected) {
    var sel = document.getElementById('new-systems');
    sel.innerHTML = '';
    sel.add(new Option(__.t('user.systems_cd'), 'cd'));
    if (isAdminRole(currentUserRole)) {
        sel.add(new Option(__.t('user.systems_ci'), 'ci'));
        sel.add(new Option(__.t('user.systems_cd_ci'), 'cd,ci'));
    }
    sel.value = selected;
}

function hideUserForm() {
    document.getElementById('user-form').style.display = 'none';
    document.getElementById('edit-target-username').value = '';
    document.getElementById('new-password').required = true;
}

async function submitUserForm(e) {
    e.preventDefault();
    const targetUser = document.getElementById('edit-target-username').value;
    const isEdit = !!targetUser;
    const username = document.getElementById('new-username').value.trim();
    const password = document.getElementById('new-password').value;
    const role = document.getElementById('new-role').value;
    const systems = document.getElementById('new-systems').value;
    const msg = document.getElementById('user-msg');

    if (!isEdit && password.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }
    if (!isEdit && !username) { msg.textContent = __.t('js.username_required'); msg.style.color = '#ef4444'; return; }
    if (isEdit && password && password.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }

    // 防止重复提交
    const saveBtn = document.querySelector('#user-form button[type="submit"]');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const url = isEdit ? '/api/admin/users/' + encodeURIComponent(targetUser) : '/api/admin/users';
        const body = isEdit
            ? { ...(password ? { password } : {}), role }
            : { username, password, role, systems };

        const res = await fetch(url, {
            method: isEdit ? 'PUT' : 'POST',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        if (handle401(res)) { if (saveBtn) saveBtn.disabled = false; return; }
        const data = await res.json();
        if (res.ok) {
            hideUserForm();
            await loadUsers();
            toast(isEdit ? __.t('user.updated') : __.t('user.created'), true);
        } else {
            msg.textContent = data.message || __.t('common.failed');
            msg.style.color = '#ef4444';
        }
    } catch (e) {
        msg.textContent = e.message;
        msg.style.color = '#ef4444';
    }
    if (saveBtn) saveBtn.disabled = false;
}

async function deleteUser(username) {
    if (!confirm(__.t('user.confirm_delete') + ' ' + username + ' ?')) return;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username), { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        if (res.ok) {
            await loadUsers();
            toast(__.t('user.deleted'), true);
        } else {
            const data = await res.json();
            alert(data.message || __.t('common.failed'));
        }
    } catch (e) {
        alert(e.message);
    }
}

var _pwdTargetUser = '';

/** 打开「修改密码」模态框（替代原生 prompt） */
function modifyUserPassword(username) {
    _pwdTargetUser = username;
    document.getElementById('pwd-modal-hint').innerHTML =
        __.t('user.modify_password_hint').replace('{user}', function() { return '<b>' + esc(username) + '</b>'; });
    document.getElementById('pwd-new').value = '';
    document.getElementById('pwd-confirm').value = '';
    document.getElementById('pwd-msg').textContent = '';
    document.getElementById('pwd-modal').style.display = 'flex';
    setTimeout(function() { document.getElementById('pwd-new').focus(); }, 60);
}

function closePasswordModal() {
    document.getElementById('pwd-modal').style.display = 'none';
    _pwdTargetUser = '';
}

async function submitPasswordChange(e) {
    if (e) e.preventDefault();
    const username = _pwdTargetUser;
    const newPass = document.getElementById('pwd-new').value;
    const confirmPass = document.getElementById('pwd-confirm').value;
    const msg = document.getElementById('pwd-msg');
    const submitBtn = document.getElementById('pwd-submit');

    msg.textContent = '';
    if (newPass.length < 8) { msg.textContent = __.t('auth.new_password_short'); return; }
    if (newPass !== confirmPass) { msg.textContent = __.t('user.modify_password_mismatch'); return; }

    if (submitBtn) submitBtn.disabled = true;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username) + '/password', {
            method: 'PUT',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ new_password: newPass })
        });
        if (handle401(res)) { if (submitBtn) submitBtn.disabled = false; return; }
        if (res.ok) {
            closePasswordModal();
            await loadUsers();
            toast(__.t('user.password_updated') + ': ' + username, true);
        } else {
            const data = await res.json();
            msg.textContent = data.message || __.t('common.failed');
        }
    } catch (err) {
        msg.textContent = err.message;
    }
    if (submitBtn) submitBtn.disabled = false;
}

// ═══════════ 角色管理（数据驱动，权限从 API 动态加载） ═══════════
var _allPerms = null;
var _permDescMap = {}; // perm_key -> description，用于 i18n 兜底
var IMPLIED_PERMISSIONS = {}; // 启动时从 API 拉取，不再硬编码

async function loadAllPerms() {
    if (_allPerms) return _allPerms;
    try {
        var res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return [];
        var data = await res.json();
        // 兼容旧格式（纯数组）和新格式（{permissions, implied}）
        if (Array.isArray(data)) {
            _allPerms = data;
        } else {
            _allPerms = data.permissions || [];
            // 隐含规则数据驱动：API 返回什么用什么
            IMPLIED_PERMISSIONS = data.implied || {};
        }
        _permDescMap = {};
        _allPerms.forEach(function(p) { _permDescMap[p.perm_key] = p.description || ''; });
        return _allPerms;
    } catch (e) { return []; }
}

function permLabel(key) {
    var tkey = 'role.perm_' + key.replace(/\./g, '_');
    var translated = __.t(tkey);
    // i18n 兜底：找不到翻译时优先显示 description（数据驱动），最后回退到 key 本身
    if (translated && translated !== tkey) return translated;
    return _permDescMap[key] || key;
}

/** 勾选/取消权限时联动其隐含目标权限（数据驱动，IMPLIED_PERMISSIONS 启动时从 API 加载） */

/** 勾选/取消权限时联动其隐含目标权限 */
function cascadeImpliedCheck(sourceKey, checked, rootContainer) {
    var targets = IMPLIED_PERMISSIONS[sourceKey];
    if (!targets) return;
    targets.forEach(function(targetKey) {
        // 取消时检查是否有其它源仍需要这个目标；若有则保留
        if (!checked) {
            var hasOtherSource = Object.keys(IMPLIED_PERMISSIONS).some(function(otherKey) {
                if (otherKey === sourceKey) return false;
                if (IMPLIED_PERMISSIONS[otherKey].indexOf(targetKey) < 0) return false;
                var otherCb = rootContainer.querySelector('input[value="' + otherKey + '"]');
                return otherCb && otherCb.checked;
            });
            if (hasOtherSource) return;
        }
        var targetCb = rootContainer.querySelector('input[value="' + targetKey + '"]');
        if (!targetCb) return;
        targetCb.checked = checked;
        var label = targetCb.closest('.perm-check');
        if (label) label.classList.toggle('checked', checked);
    });
}

/** 将权限列表分组为 CI 一级/CD 一级 + 各自子分组 */
function groupPermissions(allPerms) {
    var ciTop = [], ciChildMap = {}, cdTop = [], cdChildMap = {};
    allPerms.forEach(function(p) {
        var k = p.perm_key;
        if (k.indexOf('ci.') === 0) {
            if (p.parent_key) {
                if (!ciChildMap[p.parent_key]) ciChildMap[p.parent_key] = [];
                ciChildMap[p.parent_key].push(k);
            } else {
                ciTop.push(k);
            }
        } else if (k.indexOf('cd.') === 0) {
            if (p.parent_key) {
                if (!cdChildMap[p.parent_key]) cdChildMap[p.parent_key] = [];
                cdChildMap[p.parent_key].push(k);
            } else {
                cdTop.push(k);
            }
        }
    });
    var ciSubs = [];
    for (var pk in ciChildMap) {
        ciSubs.push({ id: pk, label: permLabel(pk), perms: ciChildMap[pk] });
    }
    var cdSubs = [];
    for (var pk in cdChildMap) {
        cdSubs.push({ id: pk, label: permLabel(pk), perms: cdChildMap[pk] });
    }
    return [
        { id: 'ci', label: __.t('role.perm_ci_group'), perms: ciTop, subGroups: ciSubs },
        { id: 'cd', label: __.t('role.perm_cd_group'), perms: cdTop, subGroups: cdSubs }
    ];
}

function getAllCiKeys(allPerms) { return allPerms.filter(function(p) { return p.perm_key.indexOf('ci.') === 0; }).map(function(p) { return p.perm_key; }); }
function getAllCdKeys(allPerms) { return allPerms.filter(function(p) { return p.perm_key.indexOf('cd.') === 0; }).map(function(p) { return p.perm_key; }); }

function permTags(allPerms, userPerms) {
    var html = '';
    allPerms.forEach(function(p) {
        var has = userPerms.indexOf(p) >= 0;
        html += '<span class="perm-tag' + (has ? ' on' : '') + '">' + esc(permLabel(p)) + '</span>';
    });
    return '<div class="perm-list">' + html + '</div>';
}

function togglePermGroup(containerId, btn) {
    var container = document.getElementById(containerId);
    var rootContainer = document.getElementById('perm-groups');
    var cbs = container.querySelectorAll('input[type="checkbox"]');
    var allChecked = Array.from(cbs).every(function(cb) { return cb.checked; });
    var target = !allChecked;
    cbs.forEach(function(cb) {
        cb.checked = target;
        cascadeImpliedCheck(cb.value, target, rootContainer || container);
    });
    container.querySelectorAll('.perm-check').forEach(function(el) {
        el.classList.toggle('checked', target);
    });
    // 联动可能影响了其它分区的 label（如 CI 的触发构建），统一刷新一次
    if (rootContainer) {
        rootContainer.querySelectorAll('.perm-check input').forEach(function(cb) {
            var label = cb.closest('.perm-check');
            if (label) label.classList.toggle('checked', cb.checked);
        });
    }
    btn.textContent = target ? __.t('role.deselect_all') : __.t('role.select_all');
}

async function loadRoleList() {
    var tbody = document.getElementById('role-tbody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">' + __.t('common.loading') + '</td></tr>';
    try {
        var [rolesRes, perms] = await Promise.all([
            fetch('/api/admin/roles', { headers: authHeaders() }),
            loadAllPerms()
        ]);
        if (handle401(rolesRes)) return;
        var roles = await rolesRes.json();
        if (!roles || roles.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">' + __.t('role.no_roles') + '</td></tr>';
            return;
        }
        var ciKeys = getAllCiKeys(perms);
        var cdKeys = getAllCdKeys(perms);
        var html = '';
        roles.forEach(function(r) {
            var rPerms = r.permissions || [];
            var typeBadge = r.is_system
                ? '<span class="badge badge-sys">' + __.t('role.system_role') + '</span>'
                : '<span class="badge badge-cus">' + __.t('role.custom_role') + '</span>';
            var actions = '';
            if (r.is_system) {
                actions = '<span style="color:#9ca3af;font-size:12px;" title="' + __.t('role.sys_cannot_edit') + '">' + __.t('role.locked') + '</span>';
            } else {
                actions = '<button class="btn btn-sm btn-edit" onclick="showRoleForm(' + r.id + ',\'' + escJs(esc(r.name)) + '\',\'' + escJs(esc(r.description||'')) + '\',' + js(rPerms) + ')" data-i18n-title="map.edit" title="编辑">✏️</button> '
                    + '<button class="btn btn-sm btn-del" onclick="deleteRole(' + r.id + ',\'' + escJs(esc(r.name)) + '\')" data-i18n-title="map.delete" title="删除">🗑</button>';
            }
            var displayName = r.description || __.t('user.role_' + r.name) || r.name;
            html += '<tr>'
                + '<td class="mono">' + esc(r.name) + '</td>'
                + '<td>' + esc(displayName) + '</td>'
                + '<td>' + typeBadge + '</td>'
                + '<td class="perm-col-ci">' + permTags(ciKeys, rPerms) + '</td>'
                + '<td class="perm-col-cd">' + permTags(cdKeys, rPerms) + '</td>'
                + '<td>' + actions + '</td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#ef4444;">' + __.t('js.network_error') + '</td></tr>';
    }
}

async function showRoleForm(id, name, desc, perms) {
    var isEdit = !!id;
    document.getElementById('role-form').style.display = 'block';
    document.getElementById('edit-role-id').value = id || '';
    document.getElementById('role-name').value = name || '';
    document.getElementById('role-name').disabled = isEdit;
    document.getElementById('role-desc').value = desc || '';
    document.getElementById('role-form-title').textContent = isEdit ? __.t('role.edit_role') + ': ' + name : __.t('role.add_role');
    document.getElementById('role-msg').textContent = '';

    var selected = perms || [];
    var allPerms;
    try {
        allPerms = await loadAllPerms();
    } catch (e) {
        document.getElementById('role-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('role-msg').style.color = '#ef4444';
        return;
    }
    var groups = groupPermissions(allPerms);
    var container = document.getElementById('perm-groups');
    var html = '';

    groups.forEach(function(g) {
        html += '<div class="perm-sect">';
        html += '<div class="perm-sect-hd"><span>' + esc(g.label) + '</span><a href="javascript:void(0)" onclick="togglePermGroup(\'' + g.id + '-perms\',this)" data-i18n="role.select_all">' + __.t('role.select_all') + '</a></div>';
        html += '<div class="perm-sect-body" id="' + g.id + '-perms">';
        // 一级权限
        g.perms.forEach(function(key) {
            var chk = selected.indexOf(key) >= 0;
            html += '<label class="perm-check' + (chk ? ' checked' : '') + '">'
                + '<input type="checkbox" value="' + esc(key) + '"' + (chk ? ' checked' : '') + '>'
                + permLabel(key) + '</label>';
        });
        // 二级分组
        g.subGroups.forEach(function(sg) {
            html += '<div class="perm-sub">';
            html += '<div class="perm-sub-hd">▸ ' + esc(sg.label) + '</div>';
            html += '<div class="perm-sub-body">';
            sg.perms.forEach(function(key) {
                var chk = selected.indexOf(key) >= 0;
                html += '<label class="perm-check perm-check-sub' + (chk ? ' checked' : '') + '">'
                    + '<input type="checkbox" value="' + esc(key) + '"' + (chk ? ' checked' : '') + '>'
                    + permLabel(key) + '</label>';
            });
            html += '</div></div>';
        });
        html += '</div></div>';
    });

    container.innerHTML = html;

    // 点击 .perm-check 时切换 checkbox（联动隐含权限）
    container.querySelectorAll('.perm-check').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            e.preventDefault(); // 阻止 label 原生行为导致的二次 toggle
            var cb = this.querySelector('input');
            cb.checked = !cb.checked;
            this.classList.toggle('checked', cb.checked);
            // 联动隐含权限
            cascadeImpliedCheck(cb.value, cb.checked, container);
        });
    });
    // checkbox 原生 change 也要联动（点击 input 本身时）
    container.querySelectorAll('.perm-check input').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var label = this.closest('.perm-check');
            if (label) label.classList.toggle('checked', this.checked);
            cascadeImpliedCheck(this.value, this.checked, container);
        });
    });
}

function hideRoleForm() {
    document.getElementById('role-form').style.display = 'none';
    document.getElementById('edit-role-id').value = '';
    document.getElementById('role-name').disabled = false;
    document.getElementById('role-msg').textContent = '';
}

async function submitRoleForm(e) {
    e.preventDefault();
    var id = document.getElementById('edit-role-id').value;
    var isEdit = !!id;
    var name = document.getElementById('role-name').value.trim();
    var desc = document.getElementById('role-desc').value.trim();
    var cbs = document.querySelectorAll('#perm-groups input:checked');
    var perms = Array.from(cbs).map(function(cb) { return cb.value; });
    var msg = document.getElementById('role-msg');

    if (!name) { msg.textContent = __.t('role.name_required'); msg.style.color = '#ef4444'; return; }

    // 防止重复提交
    var saveBtn = document.querySelector('#role-form button[type="submit"]');
    var saveBtnText = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) saveBtn.disabled = true;

    try {
        var url = isEdit ? '/api/admin/roles/' + id : '/api/admin/roles';
        var method = isEdit ? 'PUT' : 'POST';
        var body = { name: name, permissions: perms };
        if (desc) body.description = desc;
        var res = await fetch(url, { method: method, headers: Object.assign({}, authHeaders(), {'Content-Type':'application/json'}), body: JSON.stringify(body) });
        if (handle401(res)) { if (saveBtn) saveBtn.disabled = false; return; }
        var data = await res.json();
        if (res.ok) {
            _rolesCache = null;
            hideRoleForm();
            await loadRoleList();
            toast(data.message || __.t('common.success'), true);
        } else {
            msg.textContent = data.message || __.t('js.operation_failed');
            msg.style.color = '#ef4444';
        }
    } catch (e) {
        msg.textContent = __.t('js.network_error') + ': ' + e.message;
        msg.style.color = '#ef4444';
    }
    if (saveBtn) saveBtn.disabled = false;
}

async function deleteRole(id, name) {
    if (!confirm(__.t('role.delete_confirm'))) return;
    try {
        var res = await fetch('/api/admin/roles/' + id, { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        if (res.ok) {
            _rolesCache = null;
            await loadRoleList();
            toast(__.t('role.deleted'), true);
        } else {
            var data = await res.json();
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

// ═══════════ API Token 管理 ═══════════
let _apiScopes = [];
let _apiTokenCreated = '';

// 过期日期统一按 yyyy/mm/dd 显示（时间戳 → 本地时区 yyyy/mm/dd）
function fmtYmd(ts) {
    var d = new Date(ts * 1000);
    var p = function(n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate());
}

async function loadApiTokenScopes() {
    if (_apiScopes.length) return;
    try {
        var res = await fetch('/api/admin/api_tokens/scopes', { headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        _apiScopes = data.scopes || [];
    } catch(e) {}
}

async function showApiTokenForm() {
    document.getElementById('api-token-form').style.display = 'block';
    document.getElementById('api-token-created').style.display = 'none';
    document.getElementById('api-token-name').value = '';
    document.getElementById('api-token-expires').value = '';
    document.getElementById('api-token-note').value = '';
    document.getElementById('api-token-msg').textContent = '';
    await loadApiTokenScopes();
    var box = document.getElementById('api-token-scopes');
    box.innerHTML = _apiScopes.map(function(s) {
        return '<label style="display:grid; grid-template-columns:1fr 9fr;gap:5px;font-size:10px;padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">' +
            '<input type="checkbox" value="' + esc(s.key) + '" data-scope>' +
            '<span>' + esc(s.label) + '</span>' +
        '</label>';
    }).join('');
}

function hideApiTokenForm() {
    document.getElementById('api-token-form').style.display = 'none';
}

async function submitApiTokenForm(e) {
    e.preventDefault();
    var name = document.getElementById('api-token-name').value.trim();
    var expires = document.getElementById('api-token-expires').value.trim(); // yyyy/mm/dd（兼容 yyyy-mm-dd）
    var note = document.getElementById('api-token-note').value.trim();
    var scopes = Array.from(document.querySelectorAll('#api-token-scopes input[data-scope]:checked')).map(function(i){ return i.value; });
    var msg = document.getElementById('api-token-msg');
    msg.textContent = '';
    if (!name) { msg.textContent = __.t('api_token.name_required'); msg.style.color = '#dc2626'; return; }

    var expiresAt = null;
    if (expires) {
        var iso = expires.replace(/\//g, '-'); // yyyy/mm/dd → yyyy-mm-dd 再解析
        var d = new Date(iso + 'T23:59:59');
        if (isNaN(d.getTime())) {
            msg.textContent = __.t('api_token.expires_invalid');
            msg.style.color = '#dc2626';
            return;
        }
        expiresAt = Math.floor(d.getTime() / 1000);
    }

    try {
        var res = await fetch('/api/admin/api_tokens', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: JSON.stringify({ name: name, scopes: scopes, expires_at: expiresAt, note: note })
        });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok && data.token) {
            _apiTokenCreated = data.token;
            document.getElementById('api-token-form').style.display = 'none';
            var created = document.getElementById('api-token-created');
            created.style.display = 'block';
            document.getElementById('api-token-value').textContent = data.token;
            loadApiTokens();
        } else {
            msg.textContent = data.message || __.t('js.network_error');
            msg.style.color = '#dc2626';
        }
    } catch(err) {
        msg.textContent = __.t('js.network_error') + ': ' + err.message;
        msg.style.color = '#dc2626';
    }
}

function copyApiToken() {
    if (!_apiTokenCreated) return;
    navigator.clipboard.writeText(_apiTokenCreated).then(
        function(){ toast(__.t('api_token.copied'), true); },
        function(){ toast(__.t('api_token.copy_failed'), false); }
    );
}

async function revokeApiToken(id) {
    if (!confirm(__.t('api_token.confirm_revoke'))) return;
    try {
        var res = await fetch('/api/admin/api_tokens/' + id + '/revoke', { method: 'POST', headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) { toast(__.t('api_token.revoked'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

async function deleteApiToken(id) {
    if (!confirm(__.t('api_token.confirm_delete'))) return;
    try {
        var res = await fetch('/api/admin/api_tokens/' + id, { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        if (res.ok) { toast(__.t('api_token.deleted'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

async function loadApiTokens() {
    var loading = document.getElementById('api-token-loading');
    var wrap = document.getElementById('api-token-table-wrap');
    var empty = document.getElementById('api-token-empty');
    loading.style.display = 'block'; wrap.style.display = 'none'; empty.style.display = 'none';
    try {
        var res = await fetch('/api/admin/api_tokens', { headers: authHeaders() });
        if (handle401(res)) return;
        var data = await res.json();
        var rows = data.tokens || [];
        var tbody = document.getElementById('api-token-tbody');
        if (!rows.length) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = rows.map(function(t) {
                var scopes = (t.scopes || []).map(esc).join(', ');
                var exp = t.expires_at ? fmtYmd(t.expires_at) : esc(__.t('api_token.never'));
                var status = t.enabled === false
                    ? '<span style="color:#9ca3af;">🚫 ' + esc(__.t('api_token.disabled')) + '</span>'
                    : (t.expired
                        ? '<span style="color:#dc2626;">⏳ ' + esc(__.t('api_token.expired')) + '</span>'
                        : '<span style="color:#16a34a;">✅ ' + esc(__.t('api_token.active')) + '</span>');
                var actions = (t.enabled === false ? '' :
                    '<button class="btn btn-sm btn-warn" onclick="revokeApiToken(' + t.id + ')">⛔ ' + esc(__.t('api_token.revoke')) + '</button> ')
                    + '<button class="btn btn-sm btn-del" onclick="deleteApiToken(' + t.id + ')">🗑 ' + esc(__.t('api_token.delete')) + '</button>';
                return '<tr>' +
                    '<td>' + esc(t.name) + '</td>' +
                    '<td style="font-size:12px;max-width:260px;">' + scopes + '</td>' +
                    '<td>' + exp + '</td>' +
                    '<td>' + status + '</td>' +
                    '<td style="font-size:12px;">' + esc(t.created_at || '') + '</td>' +
                    '<td>' + actions + '</td>' +
                '</tr>';
            }).join('');
            wrap.style.display = 'block';
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false, true);
    }
    loading.style.display = 'none';
}

// ═══════════ Helpers ═══════════
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }
function js(obj) { return JSON.stringify(obj).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

// ═══════════ Init ═══════════
// 支持 URL 参数 ?lang=zh|en 覆盖 localStorage
(function() {
    var urlLang = new URLSearchParams(location.search).get('lang');
    if (urlLang === 'zh' || urlLang === 'zh_CN' || urlLang === 'zh-CN') urlLang = 'zh-CN';
    else if (urlLang === 'en') urlLang = 'en';
    else urlLang = null;
    if (urlLang) localStorage.setItem('dg_lang', urlLang);
    __.init(urlLang);
})();
// 同步语言选择器
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('lang-select');
    if (sel) sel.value = __.lang;
    var selLogin = document.getElementById('lang-select-login');
    if (selLogin) selLogin.value = __.lang;
});
// 语言切换时刷新当前页签内容
document.addEventListener('i18n-changed', function() {
    var sel = document.getElementById('lang-select');
    var selLogin = document.getElementById('lang-select-login');
    if (sel) sel.value = __.lang;
    if (selLogin) selLogin.value = __.lang;
    // 刷新活跃的 tab 以更新动态生成的中文内容
    var activeTab = document.querySelector('.sidebar .menu-item.active');
    if (activeTab) {
        var tabName = activeTab.getAttribute('data-tab');
        if (tabName === 'monitor') loadMonitor();
        else if (tabName === 'mapping') { if (currentMapView === 'topology') loadTopology(); else loadMaps(); }
        else if (tabName === 'security') loadSecurityChecks();
        else if (tabName === 'versions') loadVersions();
        else if (tabName === 'mode') loadSettings();
        else if (tabName === 'roles') loadRoleList();
    }
});
if (token) {
    document.getElementById('login-page').style.display = 'none';
    document.getElementById('app-page').style.display = 'block';
    switchTab('monitor');
} else {
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}