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
    token = '';
    currentUserRole = '';
    currentUserName = '';
    currentUserIsRoot = false;
    sessionStorage.removeItem('admin_token');
    sessionStorage.removeItem('admin_role');
    sessionStorage.removeItem('admin_user');
    sessionStorage.removeItem('admin_is_root');
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}

// 刷新后恢复角色菜单可见性
(function initRoleMenu() {
    currentUserName = sessionStorage.getItem('admin_user') || '';
    currentUserIsRoot = sessionStorage.getItem('admin_is_root') === 'true';
    if (currentUserRole && currentUserRole !== 'admin') {
        var userMenuItem = document.querySelector('[data-tab="users"]');
        if (userMenuItem) userMenuItem.style.display = 'none';
    }
})();

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

async function doLogin() {
    const user = document.getElementById('login-user').value.trim();
    const pass = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-err');
    errEl.style.display = 'none';

    if (!user || !pass) { errEl.textContent = __.t('auth.please_enter_credentials'); errEl.style.display = 'block'; return; }
    try {
        const res = await fetch(LOGIN_API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({user,password:pass}) });
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
            // 非 admin 隐藏用户管理菜单
            var userMenuItem = document.querySelector('[data-tab="users"]');
            if (userMenuItem) {
                userMenuItem.style.display = (currentUserRole === 'admin') ? '' : 'none';
            }
            document.getElementById('login-page').style.display = 'none';
            document.getElementById('app-page').style.display = 'block';
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
    document.querySelector(`[data-tab="${name}"]`).classList.add('active');
    ['monitor','mapping','security','versions','mode','users','password'].forEach(t => {
        document.getElementById('tab-' + t).style.display = name === t ? 'block' : 'none';
    });
    if (name === 'monitor') loadMonitor();
    if (name === 'mapping') { if (currentMapView === 'topology') loadTopology(); else loadMaps(); }
    if (name === 'security') loadSecurityChecks();
    if (name === 'versions') loadVersions();
    if (name === 'mode') loadSettings();
    if (name === 'users') loadUsers();
}

// ═══════════ Toast ═══════════
function toast(msg, ok, center) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err') + ' show' + (center ? ' toast-center' : '');
    setTimeout(() => el.classList.remove('show'), 2500);
}

// ═══════════ 服务监测 ═══════════
async function loadMonitor() {
    const now = new Date().toLocaleString(__.lang === 'en' ? 'en-US' : 'zh-CN');

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
        const res = await fetch(HEALTH_API);
        const data = await res.json();
        const chk = data.checks || {};
        const st  = data.stats || {};

        // 统计卡片
        document.getElementById('stat-total').textContent = st.total_maps ?? '—';
        document.getElementById('stat-active').textContent = st.active_maps ?? '—';
        document.getElementById('stat-platforms').textContent = st.git_platforms ?? '—';
        document.getElementById('stat-repos').textContent = st.harbor_repos ?? '—';

        // Jenkins
        const jOk = chk.jenkins === true;
        const jVer = chk.jenkins_version || '';
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', jOk, jVer ? 'v'+jVer : '', jOk ? __.t('common.ok') : __.t('common.unreachable'));

        // Git 平台
        const gitRows = document.getElementById('git-rows');
        const gitData = chk.git;
        const dotGit = document.getElementById('dot-git');
        if (gitData === null || gitData === undefined) {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat off">' + __.t('common.not_configured') + '</span></div>';
        } else if (Array.isArray(gitData) && gitData.length > 0) {
            dotGit.className = gitData.every(g=>g.reachable) ? 'dot dot-ok' : 'dot dot-err';
            gitRows.innerHTML = gitData.map(g => {
                const ok = g.reachable;
                const label = ok ? __.t('common.ok') : __.t('common.unreachable');
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

    } catch(e) {
        const msg = e.name === 'AbortError' ? __.t('js.timeout') : __.t('js.cannot_connect');
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', false, '', msg);
        document.getElementById('dot-git').className = 'dot dot-err';
        document.getElementById('git-rows').innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat err">' + msg + '</span></div>';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', false, '', msg);
    }
}

// ═══════════ 映射管理 ═══════════
let mapPage = 1, mapPerPage = 20;
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
        if (currentBuildMode !== 'both') {
            maps = maps.filter(m => (m.status || 'active') === 'active' || (m.build_provider || 'jenkins') === currentBuildMode);
        }

        // 分页以过滤后的实际可见数量为准（API 返回的是原始数据库总数，前端 dedup/provider 过滤后不可见）
        const displayTotal = maps.length;
        const displayTotalPages = 1;

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
                const bpLabel = bp === 'gitlab_ci' ? '🐺 GitLab CI' : '⚡ Jenkins';
                const bpBadge = bp === 'gitlab_ci' ? 'badge-gitlab' : 'badge-default';
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
                            return `<button class="btn btn-sm btn-activate" title="${esc(__.t('js.activate_warn_hide'))}" onclick='activateMap("${escJs(m.job_name)}", ${js(m)})'>${__.t('common.enabled')}</button>`;
                        })()}
                        <button class="btn btn-sm btn-edit" onclick='editMap(${js(m)})'>✏️ ${__.t('common.edit')}</button>
                        <button class="btn btn-sm btn-del" onclick='deleteMap("${escJs(m.job_name)}")'>🗑 ${__.t('common.delete')}</button>
                        <a href="/api/build/${esc(encodeURI(m.job_name))}/pipelines?list=id" target="_blank" style="color:#4f46e5;font-size:12px;margin-left:6px;">📋</a>
                    </td>
                </tr>`;
            }).join('');

            // 分页
            let pagHtml = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: displayTotal}) + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=1;loadMaps()" ' + (mapPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=Math.max(1,mapPage-1);loadMaps()" ' + (mapPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pagHtml += '<span style="color:#374151;font-weight:600;">' + mapPage + ' / ' + displayTotalPages + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=Math.min(displayTotalPages,mapPage+1);loadMaps()" ' + (mapPage>=displayTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pagHtml += '<button class="btn btn-sm" onclick="mapPage=displayTotalPages;loadMaps()" ' + (mapPage>=displayTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            pagination.innerHTML = pagHtml;
        }
    } catch(e) {
        document.getElementById('loading-map').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

// ═══════════ 项目拓扑 ═══════════
let topoEntries = [];   // 已加载的拓扑数据
let topoPage    = 1;    // 当前页
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
        const res = await fetch(MAP_LIST_API);
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

    // 服务端报错
    if (data._error) {
        loading.innerHTML = `<p style="color:#dc2626;">⚠️ ${esc(data._error)}</p><p style="font-size:13px;color:#9ca3af;margin-top:4px;">${esc(data._detail||'')}</p>`;
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
    const totalPages = Math.max(1, Math.ceil(total / TOPO_PER_PAGE));
    if (topoPage > totalPages) topoPage = totalPages;
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
            const buildLabel = build === 'gitlab_ci' ? '🐺 GitLab CI' : '⚡ Jenkins';
            const buildIcon = build === 'gitlab_ci' ? '🐺' : '⚡';
            const buildUrl = topoPlatformUrls.jenkins_url || '';
            const projectPath = (p.project || p.current_path || '').replace(/\/+$/, '');
            const jenkinsPath = projectPath
                ? '/' + projectPath.split('/').map(s => 'job/' + encodeURIComponent(s)).join('/') + '/'
                : '';
            const buildDisplay = buildUrl
                ? `<a href="${esc(buildUrl + jenkinsPath)}" target="_blank" title="${esc(__.t('js.topo_open_jenkins'))}">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</a>`
                : `<span class="node-main">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</span>`;
            const platformCls = platform !== '—' && platform.length > 0 ? 'badge-' + platform : 'badge-default';
            const buildBadgeCls = build === 'gitlab_ci' ? 'badge-gitlab' : 'badge-gitee';

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
                        <div class="node-label">${buildIcon} ${__.t('js.topo_build_source')}</div>
                        <div class="node-main">${buildDisplay}</div>
                    </div>
                    <div class="topo-arrow">→</div>
                    <div class="topo-node">
                        <div class="node-label">🔗 ${__.t('js.topo_git_repo')}</div>
                        <div class="node-sub">${gitDisplay}</div>
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
    if (totalPages <= 1) {
        pagination.style.display = 'none';
    } else {
        pagination.style.display = 'flex';
        let pagHtml = '<span style="color:#6b7280;">' + __.t('js.topo_total_items', {total: total}) + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=1;renderTopology()" ' + (topoPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=Math.max(1,topoPage-1);renderTopology()" ' + (topoPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
        pagHtml += '<span style="color:#374151;font-weight:600;">' + topoPage + ' / ' + totalPages + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=Math.min(totalPages,topoPage+1);renderTopology()" ' + (topoPage>=totalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
        pagHtml += '<button class="btn btn-sm" onclick="topoPage=totalPages;renderTopology()" ' + (topoPage>=totalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
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
    } catch(e) {
        document.getElementById('ver-loading').innerHTML = '<p style="color:#dc2626;">' + __.t('js.load_failed') + ': ' + esc(e.message) + '</p>';
    }
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

async function loadSettings() {
    const display = document.getElementById('mode-display');
    const configPanel = document.getElementById('mode-config');
    const sel = document.getElementById('build-mode-select');
    const statusEl = document.getElementById('build-mode-status');
    try {
        const res = await fetch('/api/admin/build_mode', { headers: authHeaders() });
        if (res.status === 401) { display.innerHTML = '<span style="color:#9ca3af;">' + __.t('auth.please_login_first') + '</span>'; return; }
        const data = await res.json();
        const mode = data.mode || 'both';
        const hasJenkins = data.has_jenkins;
        const hasGitlab = data.has_gitlab_ci;
        const source = data.source || 'env';
        currentBuildMode = mode;

        // 来源标识
        const srcLabel = source === 'database'
            ? '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_persisted') + '">✓ ' + __.t('js.mode_persisted') + '</span>'
            : '<span class="badge" style="background:#fefce8;color:#ca8a04;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_temp') + '">⚠️ ' + __.t('js.mode_temp') + '</span>';

        // 下拉选项状态
        sel.value = mode;
        sel.querySelectorAll('option').forEach(opt => {
            if (opt.value === 'jenkins') opt.disabled = !hasJenkins;
            if (opt.value === 'gitlab_ci') opt.disabled = !hasGitlab;
        });
        configPanel.style.display = 'block';
        statusEl.style.display = 'none';

        // 状态图（流程卡片式）
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

        if (hasJenkins && hasGitlab) {
            if (mode === 'both') {
                modeBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;font-size:13px;">⚡ ' + __.t('js.mode_jenkins_name') + ' + 🐺 ' + __.t('js.mode_gitlab_ci_name') + ' ' + __.t('js.mode_coexist') + '</span>';
                flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;">'
                    + node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                    + arrow
                    + node('⚡', __.t('js.mode_jenkins_name'), '#fff8e1', '#f59e0b', '#d97706', 24)
                    + split
                    + node('🐺', __.t('js.mode_gitlab_ci_name'), '#fce4ec', '#e91e63', '#c81e1e', 24)
                    + arrow
                    + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24)
                    + '</div>';
            } else {
                modeBadge = '<span class="badge" style="background:' + buildBg + ';color:' + buildColor + ';font-size:13px;">' + buildCi + ' ' + __.t('js.mode_mode') + '</span>';
                const icon = (mode === 'gitlab_ci') ? '🐺' : '⚡';
                const name = (mode === 'gitlab_ci') ? __.t('js.mode_gitlab_ci_name') : __.t('js.mode_jenkins_name');
                flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;">'
                    + node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                    + arrow
                    + node(icon, name, buildBg, buildBorder, buildColor, 24)
                    + arrow
                    + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24)
                    + '</div>';
            }
        } else if (hasGitlab) {
            modeBadge = '<span class="badge" style="background:#fce4ec;color:#c81e1e;font-size:13px;">🐺 ' + __.t('js.mode_gitlab_ci_name') + ' ' + __.t('js.mode_mode') + '</span>';
            flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;">'
                + node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                + arrow
                + node('🐺', __.t('js.mode_gitlab_ci_name'), '#fce4ec', '#e91e63', '#c81e1e', 24)
                + arrow
                + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24)
                + '</div>';
            sel.querySelectorAll('option').forEach(opt => {
                if (opt.value === 'jenkins' || opt.value === 'both') opt.disabled = true;
            });
        } else {
            modeBadge = '<span class="badge" style="background:#fff8e1;color:#d97706;font-size:13px;">⚡ ' + __.t('js.mode_jenkins_name') + ' ' + __.t('js.mode_mode') + '</span>';
            flow = '<div style="margin-top:14px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;">'
                + node('🌿', __.t('js.mode_git_repo'), '#f3f4f6', '#d1d5db', '#374151', 22)
                + arrow
                + node('⚡', __.t('js.mode_jenkins_name'), '#fff8e1', '#f59e0b', '#d97706', 24)
                + arrow
                + node('🐳', __.t('js.mode_harbor_name'), '#ecfeff', '#0891b2', '#0e7490', 24)
                + '</div>';
            sel.querySelectorAll('option').forEach(opt => {
                if (opt.value === 'gitlab_ci' || opt.value === 'both') opt.disabled = true;
            });
        }
        display.innerHTML = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
            + modeBadge + srcLabel
            + '</div>'
            + flow;

    } catch(e) {
        display.innerHTML = '<span style="color:#9ca3af;">' + __.t('js.mode_cannot_detect') + '</span>';
    }
}

async function onBuildModeChange() {
    const sel = document.getElementById('build-mode-select');
    const newMode = sel.value;
    const oldMode = currentBuildMode;

    // 先回退选择，待确认后再正式切换
    sel.value = oldMode;

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

    const statusEl = document.getElementById('build-mode-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({mode: newMode})
        });
        if (handle401(res)) { sel.value = oldMode; currentBuildMode = oldMode; return; }
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
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        sel.value = oldMode;
        currentBuildMode = oldMode;
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
    if (newP.length < 6) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#dc2626'; return; }
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
            toast(isEdit ? __.t('js.already_updated') : __.t('js.already_added'), true);
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
    if (currentBuildMode !== 'both' && bp !== currentBuildMode) {
        const curLabel = currentBuildMode === 'jenkins' ? 'Jenkins' : 'GitLab CI';
        const itemLabel = bp === 'gitlab_ci' ? 'GitLab CI' : 'Jenkins';
        toast(__.t('js.cannot_activate_mode', {mode: curLabel, item: itemLabel}), false);
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

// ═══════════ 安全扫描 ═══════════
const SEC_API = '/api/admin/security_checks';
let secPage = 1, secDebounce = null;
const STATE_ICONS = {success:'✅',failed:'❌',error:'⚠️',pending:'⏳'};
const STATE_LABELS = {success:__.t('security.passed'),failed:__.t('security.failed'),error:__.t('security.error'),pending:__.t('security.pending')};

function secOnFilterChange() {
    clearTimeout(secDebounce);
    secDebounce = setTimeout(() => { secPage = 1; loadSecurityChecks(); }, 300);
}

async function loadSecurityChecks() {
    try {
        const project = document.getElementById('sec-search-project')?.value?.trim() || '';
        const checkType = document.getElementById('sec-filter-type')?.value || '';
        const state = document.getElementById('sec-filter-state')?.value || '';
        const params = new URLSearchParams();
        if (project) params.set('project', project);
        if (checkType) params.set('check_type', checkType);
        if (state) params.set('state', state);
        params.set('page', secPage);
        params.set('per_page', '20');
        const url = SEC_API + '?' + params.toString();
        const res = await fetch(url, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const checks = data.checks || [];
        const total = data.total || 0;
        const totalPages = data.total_pages || 1;

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
                const label = STATE_LABELS[c.state] || c.state || '—';
                const cls = c.state === 'success' ? 'ok' : c.state === 'failed' ? 'err' : 'off';
                const shaShort = (c.sha || '').substring(0, 8);
                const time = (c.created_at || '').replace('T',' ').substring(0, 19);
                return `<tr>
                    <td style="font-size:12px;white-space:nowrap;color:#6b7280;">${esc(time)}</td>
                    <td><strong>${esc(c.project)}</strong></td>
                    <td><span class="badge badge-default">${esc(c.check_type || '—')}</span></td>
                    <td><span class="svc-stat ${cls}">${icon} ${label}</span></td>
                    <td>${esc(c.tag || '—')}</td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(c.description||'')}">${esc(c.description || '—')}</td>
                    <td><code style="font-size:11px;color:#6b7280;" title="${esc(c.sha||'')}">${esc(shaShort)}</code></td>
                </tr>`;
            }).join('');

            let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: total}) + '</span>';
            pag += '<button class="btn btn-sm" onclick="secPage=1;loadSecurityChecks()" ' + (secPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pag += '<button class="btn btn-sm" onclick="secPage=Math.max(1,secPage-1);loadSecurityChecks()" ' + (secPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pag += '<span style="color:#374151;font-weight:600;">' + secPage + ' / ' + totalPages + '</span>';
            pag += '<button class="btn btn-sm" onclick="secPage=Math.min(totalPages,secPage+1);loadSecurityChecks()" ' + (secPage>=totalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pag += '<button class="btn btn-sm" onclick="secPage=totalPages;loadSecurityChecks()" ' + (secPage>=totalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            document.getElementById('sec-pagination').innerHTML = pag;
        }
    } catch(e) {
        document.getElementById('sec-loading').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

// ═══════════ 用户管理 ═══════════
async function loadUsers() {
    document.getElementById('user-msg').textContent = '';
    try {
        const res = await fetch('/api/admin/users', { headers: authHeaders() });
        if (!res.ok && res.status === 403) { alert(__.t('user.cannot_create_admin')); return; }
        if (handle401(res)) return;
        const result = await res.json();
        const allUsers = Array.isArray(result) ? result : (result.data || []);
        const users = currentUserRole === 'admin' ? allUsers : allUsers.filter(u => u.role !== 'admin');
        const tbody = document.getElementById('user-tbody');
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;">' + __.t('user.no_users') + '</td></tr>';
            return;
        }
        tbody.innerHTML = users.map(u => {
            const time = u.updated_at || '-';
            const isAdmin = u.role === 'admin';
            const isRoot = u.is_root === true;
            const isSelf = u.username === currentUserName;
            let actions = '';
            if (isSelf || isRoot) {
                actions = `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
            } else if (isAdmin) {
                if (currentUserIsRoot) {
                    actions = `<button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(u.username)}')">🗑 ${__.t('common.delete')}</button>`;
                } else {
                    actions = `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
                }
            } else {
                actions = `<button class="btn btn-sm btn-edit" onclick='showUserEditForm(${js(u)})'>✏️ ${__.t('common.edit')}</button>
                    <button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(u.username)}')">🗑 ${__.t('common.delete')}</button>`;
            }
            return `<tr>
                <td><strong>${esc(u.username)}</strong></td>
                <td>${esc(u.role)}</td>
                <td>${esc(u.systems || '-')}</td>
                <td style="font-size:12px;color:#6b7280;">${esc(time)}</td>
                <td style="white-space:nowrap">${actions}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        document.getElementById('user-tbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;">' + __.t('js.load_failed') + ': ' + e.message + '</td></tr>';
    }
}

function showUserForm() {
    document.getElementById('user-form').style.display = 'block';
    document.getElementById('edit-target-username').value = '';
    document.getElementById('user-form-title').textContent = __.t('user.add_user');
    document.getElementById('new-username-wrap').style.display = 'block';
    document.getElementById('new-username').value = '';
    document.getElementById('new-username').required = true;
    document.getElementById('new-password').value = '';
    document.getElementById('new-password').required = true;
    document.getElementById('new-password').placeholder = '至少 6 位';
    document.getElementById('new-password-label').textContent = __.t('user.password');
    populateRoleSelect('deployer');
    document.getElementById('new-systems-wrap').style.display = 'block';
    populateSystemsSelect('cd');
    document.getElementById('user-msg').textContent = '';
}

function showUserEditForm(user) {
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
    populateRoleSelect(user.role);
    document.getElementById('new-systems-wrap').style.display = 'none';
    document.getElementById('user-msg').textContent = '';
}

// 根据当前用户角色动态填充角色下拉
function populateRoleSelect(selected) {
    var sel = document.getElementById('new-role');
    sel.innerHTML = '';
    sel.add(new Option(__.t('user.role_deployer'), 'deployer'));
    sel.add(new Option(__.t('user.role_viewer'), 'viewer'));
    if (currentUserRole === 'admin') {
        sel.add(new Option(__.t('user.role_admin'), 'admin'));
    }
    sel.value = selected;
}

// 根据当前用户角色动态填充系统下拉
function populateSystemsSelect(selected) {
    var sel = document.getElementById('new-systems');
    sel.innerHTML = '';
    sel.add(new Option(__.t('user.systems_cd'), 'cd'));
    if (currentUserRole === 'admin') {
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

    if (!isEdit && password.length < 6) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }
    if (!isEdit && !username) { msg.textContent = 'Username required'; msg.style.color = '#ef4444'; return; }
    if (isEdit && password && password.length < 6) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }

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
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            hideUserForm();
            toast(isEdit ? __.t('user.updated') : __.t('user.created'), true);
            loadUsers();
        } else {
            msg.textContent = data.message || 'Failed';
            msg.style.color = '#ef4444';
        }
    } catch (e) {
        msg.textContent = e.message;
        msg.style.color = '#ef4444';
    }
}

async function deleteUser(username) {
    if (!confirm(__.t('user.confirm_delete') + ' ' + username + ' ?')) return;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username), { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        if (res.ok) {
            toast(__.t('user.deleted'), true);
            loadUsers();
        } else {
            const data = await res.json();
            alert(data.message || 'Failed');
        }
    } catch (e) {
        alert(e.message);
    }
}

// ═══════════ Helpers ═══════════
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }
function js(obj) { return JSON.stringify(obj).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

// ═══════════ Init ═══════════
__.init();
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