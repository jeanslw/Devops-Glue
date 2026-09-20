import { authHeaders, handle401 } from '../core/api.js';
import { esc, escJs, js } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import {
    platforms, setPlatforms,
    currentBuildModes, currentCpEnabled,
    isPullProvider, pullProviderMeta
} from '../core/state.js';
import { loadTopology } from './topology.js';

const MAP_API = '/api/admin/job_git_map';

let mapPage = 1, mapPerPage = 20, mapTotalPages = 1;
let mapDebounceTimer = null;
let currentMapView = 'table';

export function getMapView() { return currentMapView; }
export function getMapPage() { return mapPage; }
export function setMapPage(v) { mapPage = v; }
export function getMapTotalPages() { return mapTotalPages; }
export function setMapTotalPages(v) { mapTotalPages = v; }

function normalizeRemote(r) {
    r = (r || '').trim();
    if (!r) return '';
    let host = '', path = '';
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(r)) {
        let u = null;
        try { u = new URL(r); } catch (e) { /* ignore */ }
        if (u) {
            host = (u.hostname || '').toLowerCase();
            path = (u.pathname || '').replace(/^\/+/, '');
        } else {
            const m = r.match(/^[a-z][a-z0-9+.-]*:\/\/([^/]+)\/(.*)$/i);
            if (m) { host = m[1].split('@').pop().split(':')[0].toLowerCase(); path = m[2]; }
        }
    } else {
        const m = r.match(/^git@([^:]+):(.+)/);
        if (m) { host = m[1].toLowerCase(); path = m[2].replace(/^\/+/, ''); }
        else { path = r.replace(/^\/+/, ''); }
    }
    path = path.replace(/\.git$/i, '').replace(/\/+$/, '').toLowerCase();
    return (host && path) ? host + '/' + path : path;
}

export async function loadMaps() {
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

        const activeRemotes = new Set();
        maps.forEach(m => {
            if ((m.status || 'active') === 'active' && m.git_remote) {
                const nk = normalizeRemote(m.git_remote);
                if (nk) activeRemotes.add(nk);
            }
        });
        maps = maps.filter(m => (m.status || 'active') === 'active' || !activeRemotes.has(normalizeRemote(m.git_remote)));

        maps = maps.filter(m => {
            const bp = (m.build_provider || 'jenkins');
            if (!isPullProvider(bp)) return true;
            return (m.status || 'active') === 'active' || currentBuildModes.includes(bp);
        });

        const displayTotal = maps.length;
        mapTotalPages = Math.max(1, Math.ceil(displayTotal / mapPerPage));
        if (mapPage > mapTotalPages) mapPage = mapTotalPages;

        setPlatforms(data.platforms || []);

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
                    : bp === 'gitea_ci' ? __.t('build.mode_gitea_ci')
                    : bp === 'custom_push' ? 'Custom_Push'
                    : bp;
                const bpBadge = bp === 'gitlab_ci' ? 'badge-gitlab' : bp === 'gitea_ci' ? 'badge-gitea' : bp === 'custom_push' ? 'badge-cus' : 'badge-default';
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

            let pagHtml = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: displayTotal}) + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="setMapPage(1);loadMaps()" ' + (mapPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pagHtml += '<button class="btn btn-sm" onclick="setMapPage(Math.max(1,getMapPage()-1));loadMaps()" ' + (mapPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pagHtml += '<span style="color:#374151;font-weight:600;">' + mapPage + ' / ' + mapTotalPages + '</span>';
            pagHtml += '<button class="btn btn-sm" onclick="setMapPage(Math.min(getMapTotalPages(),getMapPage()+1));loadMaps()" ' + (mapPage>=mapTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pagHtml += '<button class="btn btn-sm" onclick="setMapPage(getMapTotalPages());loadMaps()" ' + (mapPage>=mapTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            pagination.innerHTML = pagHtml;
        }
    } catch(e) {
        document.getElementById('loading-map').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

export function onFilterChange() {
    clearTimeout(mapDebounceTimer);
    mapDebounceTimer = setTimeout(() => { mapPage = 1; loadMaps(); }, 300);
}

export function switchMapView(view) {
    currentMapView = view;
    document.querySelectorAll('#view-toggle button').forEach(b => b.classList.remove('active'));
    const target = document.querySelector(`#view-toggle [data-view="${view}"]`);
    if (target) target.classList.add('active');
    document.getElementById('table-view').style.display = view === 'table' ? 'block' : 'none';
    document.getElementById('topo-view').style.display = view === 'topology' ? 'block' : 'none';
    if (view === 'topology') hideForm();
    if (view === 'topology') loadTopology(); else loadMaps();
}

let _discovering = false;
export async function doDiscover() {
    if (_discovering) { toast('⏳ ' + __.t('js.scan_in_progress'), true, true); return; }
    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: __.t('js.discover_confirm'),
        note: __.t('js.discover_note')
    })) return;
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

export function updateDiscoverButton() {
    const btn = document.querySelector('.btn-discover');
    if (btn) btn.style.display = '';
}

export function showForm(editData) {
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
    const cpOption = document.querySelector('#f-build_provider option[value="custom_push"]');
    const cpHint = document.getElementById('cp-disabled-hint');
    if (cpOption) cpOption.disabled = !currentCpEnabled;
    if (cpHint) cpHint.style.display = currentCpEnabled ? 'none' : '';
    panel.scrollIntoView({behavior:'smooth'});
}

export function hideForm() {
    document.getElementById('form-panel').classList.remove('show');
}

export async function submitForm(e) {
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

export function editMap(item) { showForm(item); }

export function statusBadge(s) {
    s = s || 'active';
    if (s === 'pending') return '<span class="badge" style="background:#fef3c7;color:#d97706;">' + __.t('common.pending') + '</span>';
    if (s === 'disabled') return '<span class="badge" style="background:#fef2f2;color:#dc2626;">' + __.t('common.disabled') + '</span>';
    return '<button class="btn btn-sm" disabled style="background:#dcfce7;color:#16a34a;border:1px solid #86efac;cursor:default;">✅ ' + __.t('common.enabled') + '</button>';
}

export async function activateMap(jobName, item) {
    const bp = item.build_provider || 'jenkins';
    const isBuiltinBp = isPullProvider(bp);
    if (isBuiltinBp && !currentBuildModes.includes(bp)) {
        const itemLabel = pullProviderMeta(bp).label;
        const curLabel = currentBuildModes.map(m => pullProviderMeta(m).label).join(' + ') || __.t('js.mode_none');
        toast(__.t('js.cannot_activate_mode', {mode: curLabel, item: itemLabel}), false);
        return;
    }
    if (bp === 'custom_push' && !currentCpEnabled) {
        toast(__.t('js.cp_disabled_hint'), false);
        return;
    }
    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: __.t('js.activate') + ' "' + jobName + '"',
        note: __.t('js.activate_warn_hide'),
        confirmText: __.t('common.confirm')
    })) return;
    try {
        item._original_job_name = jobName;
        item.status = 'active';
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

export async function deleteMap(jobName) {
    if (!await confirmDialog({
        title: '🗑️ ' + __.t('js.delete_confirm'),
        message: '"' + jobName + '"',
        note: __.t('js.delete_note'),
        confirmText: __.t('common.confirm')
    })) return;
    try {
        const res = await fetch(MAP_API + '?job_name=' + encodeURI(jobName), { method:'DELETE', headers:authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('js.already_deleted') + ' ' + jobName, true); hideForm(); loadMaps(); }
        else { toast(data.message || __.t('js.delete_failed'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

export async function copyPipelineIds(jobName) {
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