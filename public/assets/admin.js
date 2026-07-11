const LOGIN_API    = '/api/admin/login';
const MAP_API      = '/api/admin/job_git_map';
const HEALTH_API   = '/api/health';
const MAP_LIST_API  = '/api/main/map/list';
const VERSIONS_API  = '/api/admin/platform_versions';
let platforms = [];
let token = sessionStorage.getItem('admin_token') || '';

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
    sessionStorage.removeItem('admin_token');
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}

async function doLogin() {
    const user = document.getElementById('login-user').value.trim();
    const pass = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-err');
    errEl.style.display = 'none';

    if (!user || !pass) { errEl.textContent = '请输入账号和密码'; errEl.style.display = 'block'; return; }
    try {
        const res = await fetch(LOGIN_API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({user,password:pass}) });
        const data = await res.json();
        if (res.ok && data.token) {
            token = data.token;
            sessionStorage.setItem('admin_token', token);
            document.getElementById('login-page').style.display = 'none';
            document.getElementById('app-page').style.display = 'block';
            switchTab('monitor');
        } else {
            errEl.textContent = data.message || '登录失败';
            errEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = '网络错误: ' + e.message;
        errEl.style.display = 'block';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && document.getElementById('login-page').style.display !== 'none') doLogin();
});

function switchTab(name) {
    document.querySelectorAll('.sidebar .menu-item').forEach(el => el.classList.remove('active'));
    document.querySelector(`[data-tab="${name}"]`).classList.add('active');
    ['monitor','mapping','topology','versions','mode','password'].forEach(t => {
        document.getElementById('tab-' + t).style.display = name === t ? 'block' : 'none';
    });
    if (name === 'monitor') loadMonitor();
    if (name === 'mapping') loadMaps();
    if (name === 'topology') loadTopology();
    if (name === 'versions') loadVersions();
    if (name === 'mode') loadSettings();
}

// ═══════════ Toast ═══════════
function toast(msg, ok) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err') + ' show';
    setTimeout(() => el.classList.remove('show'), 2500);
}

// ═══════════ 服务监测 ═══════════
async function loadMonitor() {
    const now = new Date().toLocaleString('zh-CN');

    function setSvc(iconId, nameId, statId, dotId, ok, ver, label) {
        const icon = document.getElementById(iconId);
        const name = document.getElementById(nameId);
        const stat = document.getElementById(statId);
        const dot  = document.getElementById(dotId);
        if (icon) icon.textContent = ok === true ? '✅' : ok === null ? '⚪' : '❌';
        if (stat) { stat.textContent = label; stat.className = 'svc-stat ' + (ok===true?'ok':ok===null?'off':'err'); }
        if (dot)  { dot.className = 'dot ' + (ok===true?'dot-ok':ok===null?'dot-off':'dot-err'); }
        if (name && ver) name.innerHTML = (name.dataset.base || name.textContent) + ' <span class="svc-ver">' + ver + '</span>';
    }

    try {
        const res = await fetch(HEALTH_API);
        const data = await res.json();
        const chk = data.checks || {};

        // Jenkins
        const jOk = chk.jenkins === true;
        const jVer = chk.jenkins_version || '';
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', jOk, jVer ? 'v'+jVer : '', jOk?'正常':'不可达');

        // Git 平台
        const gitRows = document.getElementById('git-rows');
        const gitData = chk.git;
        const dotGit = document.getElementById('dot-git');
        if (gitData === null || gitData === undefined) {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">Git 平台</span><span class="svc-stat off">未配置</span></div>';
        } else if (Array.isArray(gitData) && gitData.length > 0) {
            dotGit.className = gitData.every(g=>g.reachable) ? 'dot dot-ok' : 'dot dot-err';
            gitRows.innerHTML = gitData.map(g => {
                const ok = g.reachable;
                return `<div class="svc-row child">
                    <span class="svc-icon">${ok ? '✅' : '❌'}</span>
                    <span class="svc-name">${esc(g.name)}<span class="svc-ver">${g.api_version||''}</span></span>
                    <span class="svc-stat ${ok?'ok':'err'}">${ok?'正常':'不可达'}</span>
                </div>`;
            }).join('') || '<div class="svc-row child"><span class="svc-icon">⚪</span><span class="svc-name">无已配置平台</span></div>';
        } else {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">Git 平台</span><span class="svc-stat off">未知</span></div>';
        }

        // Harbor
        const hOk = chk.harbor === true;
        const hVer = chk.harbor_version || '';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', hOk, hVer, hOk===true?'正常':hOk===null?'未配置':'不可达');

    } catch(e) {
        const msg = e.name === 'AbortError' ? '超时' : '无法连接';
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', false, '', msg);
        document.getElementById('dot-git').className = 'dot dot-err';
        document.getElementById('git-rows').innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">Git 平台</span><span class="svc-stat err">' + msg + '</span></div>';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', false, '', msg);
    }
}

// ═══════════ 映射管理 ═══════════
async function loadMaps() {
    try {
        const res = await fetch(MAP_API, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const maps = data.maps || [];
        platforms = data.platforms || [];

        const sel = document.getElementById('f-git_platform');
        sel.innerHTML = '<option value="">— 自动检测（IP 地址需手动选）—</option>';
        platforms.forEach(p => { sel.innerHTML += `<option value="${p}">${p}</option>`; });

        const tbody = document.getElementById('map-tbody');
        const empty = document.getElementById('empty-msg');
        const tableWrap = document.getElementById('table-wrap');
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
                    <td>${(m.status||'active')==='disabled'?'<span class="badge" style="background:#fef2f2;color:#dc2626;">禁用</span>':'<span class="badge" style="background:#f0fdf4;color:#16a34a;">启用</span>'}</td>
                    <td style="white-space:nowrap">
                        <button class="btn btn-sm btn-edit" onclick='editMap(${js(m)})'>✏️ 编辑</button>
                        <button class="btn btn-sm btn-del" onclick='deleteMap("${escJs(m.job_name)}")'>🗑 删除</button>
                        <a href="/api/build/${esc(encodeURIComponent(m.job_name))}/pipelines?list=id" target="_blank" style="color:#4f46e5;font-size:12px;margin-left:6px;">📋</a>
                    </td>
                </tr>`;
            }).join('');
        }
    } catch(e) {
        document.getElementById('loading-map').textContent = '加载失败: ' + e.message;
    }
}

// ═══════════ 项目拓扑 ═══════════
async function loadTopology() {
    const loading = document.getElementById('topo-loading');
    const grid    = document.getElementById('topo-grid');
    const empty   = document.getElementById('topo-empty');

    loading.style.display = 'block';
    loading.innerHTML = '<p style="font-size:15px;">⏳ 正在从 Jenkins 拉取 Job 列表…</p><p style="font-size:12px;color:#9ca3af;margin-top:4px;">首次加载稍慢，后续 30 秒内有缓存</p>';
    grid.style.display = 'none';
    empty.style.display = 'none';

    try {
        const res = await fetch(MAP_LIST_API);
        const data = await res.json();

        // 服务端报错
        if (data._error) {
            loading.innerHTML = `<p style="color:#dc2626;">⚠️ ${esc(data._error)}</p><p style="font-size:13px;color:#9ca3af;margin-top:4px;">${esc(data._detail||'')}</p>`;
            return;
        }

        const projects = data.data || data;
        const entries = Array.isArray(projects) ? projects : Object.entries(projects).map(([k,v]) => ({project:k, ...v}));

        if (entries.length === 0) {
            loading.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        // 过期缓存标记
        let staleNote = '';
        if (data._stale) {
            staleNote = '<div style="text-align:center;margin-bottom:12px;padding:8px;background:#fef3c7;border-radius:8px;font-size:12px;color:#92400e;">⚠️ Jenkins 暂时不可达，显示的是最近一次缓存数据</div>';
        }

        loading.style.display = 'none';
        grid.style.display = 'block';
        grid.innerHTML = staleNote + entries.map((p, i) => {
            const platform = p.git_platform || '—';
            const source   = p.platform_source || '';
            const method   = p.detection_method || '';
            let detectBadge = '';
            if (source === 'manual') detectBadge = '<span class="badge" style="background:#fef3c7;color:#d97706;">手动映射</span>';
            else if (method === 'fallback') detectBadge = '<span class="badge" style="background:#fef2f2;color:#dc2626;">兜底识别</span>';
            else if (method === 'exact') detectBadge = '<span class="badge" style="background:#ecfdf5;color:#065f46;">自动识别</span>';

            // Jenkins Jobs
            const jobs = (p.jobs || []).map(j => `<span class="job-tag">${esc(j)}</span>`).join(' ') || '<span class="topo-empty-field">无关联 Job</span>';

            // Git
            const gitUrl = p.git_remote || '';
            const gitDisplay = gitUrl
                ? `<a href="${esc(gitUrl)}" target="_blank" title="${esc(gitUrl)}">${esc(truncateUrl(gitUrl))}</a>`
                : '<span class="topo-empty-field">未配置</span>';

            // Harbor
            const harbor = p.harbor_repository || '';
            const harborDisplay = harbor
                ? `<span style="font-family:monospace;font-size:12px;">${esc(harbor)}</span>`
                : '<span class="topo-empty-field">未关联</span>';

            const build = p.build_provider || 'jenkins';
            const buildLabel = build === 'gitlab_ci' ? '🐺 GitLab CI' : '⚡ Jenkins';
            const buildIcon = build === 'gitlab_ci' ? '🐺' : '⚡';
            const buildBg = build === 'gitlab_ci' ? '#fce4ec' : '#fff8e1';
            const buildBorder = build === 'gitlab_ci' ? '#e91e63' : '#f59e0b';
            const platformCls = platform !== '—' && platform.length > 0 ? 'badge-' + platform : 'badge-default';
            const buildBadgeCls = build === 'gitlab_ci' ? 'badge-gitlab' : 'badge-gitee';

            return `<div class="topo-card">
                <div class="topo-header">
                    <span class="topo-project">📦 ${esc(p.project || p.current_path || '未命名项目')}</span>
                    <div class="topo-meta">
                        <span class="badge ${buildBadgeCls}">${buildLabel}</span>
                        <span class="badge ${platformCls}">${esc(platform)}</span>
                        ${detectBadge}
                    </div>
                </div>
                <div class="topo-flow">
                    <div class="topo-node" style="background:${buildBg};border-left:3px solid ${buildBorder};">
                        <div class="node-label">${buildIcon} 构建源</div>
                        <div class="node-main" style="font-size:14px;font-weight:600;">${buildLabel}</div>
                        <div class="topo-jobs">${jobs}</div>
                    </div>
                    <div class="topo-arrow">→</div>
                    <div class="topo-node">
                        <div class="node-label">🔗 Git 仓库</div>
                        <div class="node-sub">${gitDisplay}</div>
                    </div>
                    <div class="topo-arrow">→</div>
                    <div class="topo-node">
                        <div class="node-label">🐳 Harbor 镜像</div>
                        <div class="node-sub">${harborDisplay}</div>
                    </div>
                </div>
            </div>`;
        }).join('');

    } catch(e) {
        loading.innerHTML = '<p style="color:#dc2626;">⚠️ 网络请求失败</p><p style="font-size:13px;color:#9ca3af;margin-top:6px;">请确认服务正常运行：' + esc(e.message) + '</p>';
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
    gitlab: {label:'GitLab', desc:'API v4，2017年至今无破坏性变更'},
    gitee:  {label:'Gitee',  desc:'API v5，SaaS 平台端点稳定'},
    github: {label:'GitHub', desc:'通过 X-GitHub-Api-Version Header 传递，仅影响显示'},
    gitea:  {label:'Gitea',  desc:'API v1，自建平台'},
    harbor: {label:'Harbor', desc:'支持 v1.10 / v2.0 自动探测'},
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
            if (src === 'config')  srcBadge = '<span class="badge" style="background:#fde8e8;color:#c81e1e;">配置文件</span>';
            else if (src === 'json') srcBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;">管理界面</span>';
            else srcBadge = '<span class="badge" style="background:#f3f4f6;color:#6b7280;">系统默认</span>';

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
                      style="${inputStyle}" ${readonly ? 'readonly title="此值由 config/settings.php 显式配置，优先级最高，如需修改请编辑配置文件"' : ''}></td>
                <td style="font-size:12px;color:#6b7280;">${info.desc}</td>
            </tr>`;
        }).join('');
    } catch(e) {
        document.getElementById('ver-loading').innerHTML = '<p style="color:#dc2626;">加载失败: ' + esc(e.message) + '</p>';
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
            toast(data.message || '保存失败', false);
        }
    } catch(e) { toast('网络错误: ' + e.message, false); }
}

// ── 基本设置 ──
async function loadSettings() {
    const display = document.getElementById('mode-display');
    try {
        // 公开端点，不调 CI 系统
        const res = await fetch('/api/build/config-mode');
        const data = await res.json();
        const hasJenkins = data.has_jenkins;
        const hasGitlab  = data.has_gitlab_ci;

        let mode = '', diagram = '';
        if (hasJenkins && hasGitlab) {
            mode = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;font-size:13px;">共存模式</span>';
            diagram = '<div style="font-size:13px;"><b>项目</b></div>'
                + '<div style="display:flex;justify-content:center;gap:20px;margin:8px 0;">'
                + '<div style="border:2px solid #f59e0b;border-radius:8px;padding:8px 16px;">⚡ Jenkins</div>'
                + '<div style="border:2px solid #e91e63;border-radius:8px;padding:8px 16px;">🐺 GitLab CI</div>'
                + '</div>'
                + '<div style="font-size:13px;">⬇</div>'
                + '<div style="border:2px solid #22c55e;border-radius:8px;padding:8px 16px;">🐳 Harbor</div>';
        } else if (hasGitlab) {
            mode = '<span class="badge" style="background:#fce4ec;color:#c81e1e;font-size:13px;">GitLab CI 模式</span>';
            diagram = '<div style="font-size:13px;"><b>项目</b> → 🐺 <b>GitLab CI</b> → 🐳 <b>Harbor</b></div>';
        } else {
            mode = '<span class="badge" style="background:#fff8e1;color:#d97706;font-size:13px;">Jenkins 模式</span>';
            diagram = '<div style="font-size:13px;"><b>项目</b> → ⚡ <b>Jenkins</b> → 🐳 <b>Harbor</b></div>';
        }
        display.innerHTML = '<div style="margin-bottom:10px;">' + mode + '</div>' + diagram;
    } catch(e) {
        display.innerHTML = '<span style="color:#9ca3af;">无法检测（请先登录）</span>';
    }
}

// ── 密码修改 ──
async function changePassword(e) {
    e.preventDefault();
    const oldP = document.getElementById('old-pass').value;
    const newP = document.getElementById('new-pass').value;
    const new2 = document.getElementById('new-pass2').value;
    const msg  = document.getElementById('pwd-msg');
    if (newP !== new2) { msg.textContent = '两次密码不一致'; msg.style.color = '#dc2626'; return; }
    if (newP.length < 6) { msg.textContent = '新密码至少 6 位'; msg.style.color = '#dc2626'; return; }
    try {
        const res = await fetch('/api/admin/password', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({old_password: oldP, new_password: newP})
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ 密码已更新，请重新登录';
            msg.style.color = '#16a34a';
            setTimeout(() => doLogout(), 1500);
        } else {
            msg.textContent = data.message || '修改失败';
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = '网络错误'; msg.style.color = '#dc2626'; }
}

// ── 表单 ──
function showForm(editData) {
    const panel = document.getElementById('form-panel');
    panel.classList.add('show');
    if (editData) {
        document.getElementById('form-title').textContent = '编辑映射: ' + editData.job_name;
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
        document.getElementById('form-title').textContent = '新增映射';
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
            toast(isEdit ? '已更新' : '已新增', true);
            hideForm();
            loadMaps();
        } else {
            toast(data.message || '操作失败', false);
        }
    } catch(e) { toast('网络错误: ' + e.message, false); }
}

function editMap(item) { showForm(item); }

async function deleteMap(jobName) {
    if (!confirm('确定删除映射 "' + jobName + '" 吗？')) return;
    try {
        const res = await fetch(MAP_API + '?job_name=' + encodeURIComponent(jobName), { method:'DELETE', headers:authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast('已删除 ' + jobName, true); loadMaps(); }
        else { toast(data.message || '删除失败', false); }
    } catch(e) { toast('网络错误: ' + e.message, false); }
}

// ═══════════ Helpers ═══════════
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }
function js(obj) { return JSON.stringify(obj).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

// ═══════════ Init ═══════════
if (token) {
    document.getElementById('login-page').style.display = 'none';
    document.getElementById('app-page').style.display = 'block';
    switchTab('monitor');
} else {
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}