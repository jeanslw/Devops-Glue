import { authHeaders, handle401 } from '../core/api.js';
import { esc, safeUrl, encodePath } from '../core/utils.js';
import { toast } from '../core/toast.js';
import { hasPermission } from '../core/auth.js';
import { currentBuildModes, isPullProvider, pullProviderMeta } from '../core/state.js';

let currentPullPath = '';
let pullTimer = null;
let pullRecordsCache = [];
let pullLogJobs = [];
let pullPage = 1;
const pullPageSize = 20;
let pushPage = 1, pushTotalPages = 1;

export function startPullAutoRefresh() {
    stopPullAutoRefresh();
    pullTimer = setInterval(() => {
        const sel = document.getElementById('pull-project-select');
        if (sel && sel.value) loadPullRecords(true);
    }, 10000);
}
export function stopPullAutoRefresh() {
    if (pullTimer) { clearInterval(pullTimer); pullTimer = null; }
}

export function applyBuildRecordsMenuVisibility(cpEnabled) {
    var group = document.getElementById('menu-group-build-records');
    var pullItem = document.querySelector('#menu-group-build-records .submenu .menu-item[data-tab="pull-records"]');
    var pushItem = document.querySelector('#menu-group-build-records .submenu .menu-item[data-tab="push-records"]');
    var pullOk = currentBuildModes.length > 0 && hasPermission('ci.build-records.pull');
    var pushOk = !!cpEnabled && hasPermission('ci.build-records.push');
    if (pullItem) pullItem.style.display = pullOk ? '' : 'none';
    if (pushItem) pushItem.style.display = pushOk ? '' : 'none';
    if (group) group.style.display = (pullOk || pushOk) ? '' : 'none';
}

export async function loadPullProjects() {
    const sel = document.getElementById('pull-project-select');
    if (!sel) return;
    const prev = currentPullPath || sel.value;
    try {
        const res = await fetch('/api/build/jobs/list?format=json', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const all = Array.isArray(data.data) ? data.data : [];
        const projects = all.filter(m => isPullProvider(m.ci_provider));
        let opts = '<option value="">' + esc(__.t('form.please_select')) + '</option>';
        projects.forEach(m => {
            const bp = m.ci_provider || 'jenkins';
            const name = m.job_name || m.current_path || '';
            const path = m.job_name || m.current_path || '';
            const meta = pullProviderMeta(bp);
            opts += '<option value="' + esc(path) + '" data-provider="' + esc(bp) + '">' + meta.icon + ' ' + esc(name) + '</option>';
        });
        sel.innerHTML = opts;
        if (prev) {
            const exists = Array.prototype.some.call(sel.options, o => o.value === prev);
            if (exists) sel.value = prev;
        }
        if (projects.length === 0) {
            const empty = document.getElementById('pull-records-empty');
            if (empty) { empty.style.display = 'block'; empty.textContent = __.t('pull.no_projects'); }
        }
    } catch (e) {
        sel.innerHTML = '<option value="">' + esc(__.t('pull.load_failed')) + '</option>';
    }
}

export async function loadPullRecords(silent) {
    const sel = document.getElementById('pull-project-select');
    const tbody = document.getElementById('pull-records-tbody');
    const table = document.getElementById('pull-records-table');
    const empty = document.getElementById('pull-records-empty');
    const loading = document.getElementById('pull-records-loading');
    const pagination = document.getElementById('pull-pagination');
    if (!sel || !tbody) return;
    const path = sel.value;
    if (!path) {
        if (!silent) toast(__.t('pull.select_first'), false);
        return;
    }
    currentPullPath = path;
    if (!silent) { pullPage = 1; if (loading) loading.style.display = 'block'; if (table) table.style.display = 'none'; if (empty) empty.style.display = 'none'; if (pagination) pagination.style.display = 'none'; }
    try {
        const res = await fetch('/api/build/' + encodePath(path) + '/pipelines?per_page=200', { headers: authHeaders() });
        if (handle401(res)) return;
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const list = await res.json();
        const records = Array.isArray(list) ? list : [];
        const selOpt = sel.options[sel.selectedIndex];
        const provider = selOpt ? (selOpt.getAttribute('data-provider') || 'jenkins') : 'jenkins';
        pullRecordsCache = records.map(r => Object.assign({}, r, {
            _runId: provider === 'gitlab_ci' ? (r.iid || r.id || 0) : (r.id || 0)
        }));
        if (loading) loading.style.display = 'none';
        if (!records.length) {
            if (table) table.style.display = 'none';
            if (pagination) pagination.style.display = 'none';
            if (empty) { empty.style.display = 'block'; empty.textContent = __.t('pull.no_records'); }
            return;
        }
        if (empty) empty.style.display = 'none';
        if (table) table.style.display = 'table';
        const statusBadge = (s) => {
            const st = (s || '').toLowerCase();
            let bg = '#f3f4f6', fg = '#6b7280';
            if (st === 'success') { bg = '#ecfdf5'; fg = '#065f46'; }
            else if (st === 'failed' || st === 'canceled') { bg = '#fef2f2'; fg = '#dc2626'; }
            else if (st === 'running') { bg = '#dbeafe'; fg = '#1d4ed8'; }
            else if (st === 'pending') { bg = '#fef3c7'; fg = '#d97706'; }
            else if (st === 'unstable') { bg = '#fff7ed'; fg = '#c2410c'; }
            else if (st === 'manual') { bg = '#f5f3ff'; fg = '#6d28d9'; }
            return '<span class="badge" style="background:' + bg + ';color:' + fg + ';">' + esc(s || '—') + '</span>';
        };
        const totalPages = Math.max(1, Math.ceil(records.length / pullPageSize));
        if (pullPage > totalPages) pullPage = totalPages;
        const start = (pullPage - 1) * pullPageSize;
        const pageRecords = records.slice(start, start + pullPageSize);
        tbody.innerHTML = pageRecords.map((r, j) => {
            const i = start + j;
            const num = r.iid || r.id || '';
            const ref = r.ref || '';
            const sha = r.sha ? String(r.sha).slice(0, 8) : '';
            const time = r.created_at || '';
            const safeWeb = safeUrl(r.web_url);
            const viewCell = safeWeb
                ? '<a href="' + esc(safeWeb) + '" target="_blank" rel="noopener noreferrer">' + esc(__.t('pull.view')) + '</a>'
                : '';
            const actionsCell = '<button class="btn btn-sm" onclick="openPullLog(' + i + ')">📋 ' + esc(__.t('pull.log')) + '</button>'
                + (viewCell ? ' ' + viewCell : '');
            return '<tr>'
                + '<td>' + (num ? '#' + esc(String(num)) : '—') + '</td>'
                + '<td>' + statusBadge(r.status) + '</td>'
                + '<td>' + (ref ? '<code style="font-size:11px;">' + esc(ref) + '</code>' : '—') + '</td>'
                + '<td>' + (sha ? '<code style="font-size:11px;word-break:break-all;">' + esc(sha) + '</code>' : '—') + '</td>'
                + '<td>' + (r.tag ? '<code style="font-size:11px;">' + esc(r.tag) + '</code>' : '—') + '</td>'
                + '<td>' + (time ? esc(time) : '—') + '</td>'
                + '<td>' + actionsCell + '</td>'
                + '</tr>';
        }).join('');
        if (pagination) {
            if (records.length > pullPageSize) {
                pagination.style.display = 'flex';
                let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: records.length}) + '</span>';
                pag += '<button class="btn btn-sm" onclick="setPullPage(1)" ' + (pullPage <= 1 ? 'disabled' : '') + '>« ' + __.t('js.page_first') + '</button>';
                pag += '<button class="btn btn-sm" onclick="setPullPage(Math.max(1,getPullPage()-1))" ' + (pullPage <= 1 ? 'disabled' : '') + '>‹ ' + __.t('js.page_prev') + '</button>';
                pag += '<span style="color:#374151;font-weight:600;">' + pullPage + ' / ' + totalPages + '</span>';
                pag += '<button class="btn btn-sm" onclick="setPullPage(Math.min(' + totalPages + ',getPullPage()+1))" ' + (pullPage >= totalPages ? 'disabled' : '') + '>' + __.t('js.page_next') + ' ›</button>';
                pag += '<button class="btn btn-sm" onclick="setPullPage(' + totalPages + ')" ' + (pullPage >= totalPages ? 'disabled' : '') + '>' + __.t('js.page_last') + ' »</button>';
                pagination.innerHTML = pag;
            } else {
                pagination.style.display = 'none';
            }
        }
    } catch (e) {
        if (loading) loading.style.display = 'none';
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#dc2626;">' + __.t('pull.load_failed') + ': ' + esc(e.message) + '</td></tr>';
    }
}

export function getPullPage() { return pullPage; }
export function setPullPage(p) { pullPage = p; loadPullRecords(true); }

export async function openPullLog(idx) {
    const r = pullRecordsCache[idx];
    const modal = document.getElementById('pull-log-modal');
    const title = document.getElementById('pull-log-title');
    const jobsBox = document.getElementById('pull-log-jobs');
    const content = document.getElementById('pull-log-content');
    if (!r || !modal || !content) return;
    const runId = r._runId || r.id || 0;
    const runLabel = r.iid || r.id || '';
    if (title) title.textContent = '📋 ' + (runLabel ? '#' + runLabel + ' ' : '') + __.t('pull.log_title');
    if (jobsBox) jobsBox.innerHTML = '';
    content.textContent = __.t('common.loading');
    modal.style.display = 'flex';
    pullLogJobs = [];
    try {
        const res = await fetch('/api/build/' + encodePath(currentPullPath) + '/pipelines/' + runId + '?format=json', { headers: authHeaders() });
        if (handle401(res)) return;
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        const jobs = (data.data && Array.isArray(data.data.jobs)) ? data.data.jobs : [];
        if (!jobs.length) { content.textContent = __.t('pull.no_log'); return; }
        pullLogJobs = jobs;
        if (jobsBox) {
            jobsBox.innerHTML = jobs.map((j, k) =>
                '<button class="btn btn-sm' + (k === 0 ? ' active' : '') + '" onclick="pullShowLog(' + k + ')">'
                + esc(j.name || ('job ' + j.id)) + '</button>'
            ).join('');
        }
        await pullShowLog(0);
    } catch (e) {
        content.textContent = __.t('pull.log_load_failed') + ': ' + e.message;
    }
}

export async function pullShowLog(i) {
    const content = document.getElementById('pull-log-content');
    const jobsBox = document.getElementById('pull-log-jobs');
    if (!content) return;
    const j = pullLogJobs[i];
    if (!j) { content.textContent = __.t('pull.no_log'); return; }
    if (jobsBox) {
        Array.prototype.forEach.call(jobsBox.children, (b, k) => b.classList.toggle('active', k === i));
    }
    content.textContent = __.t('common.loading');
    try {
        const url = j.log_url || ('/api/build/' + encodePath(currentPullPath) + '/logs/' + j.id);
        const res = await fetch(url, { headers: authHeaders() });
        if (handle401(res)) return;
        if (!res.ok) { content.textContent = __.t('pull.no_log') + ' (HTTP ' + res.status + ')'; return; }
        content.textContent = await res.text();
    } catch (e) {
        content.textContent = __.t('pull.log_load_failed') + ': ' + e.message;
    }
}

export function closePullLog() {
    const modal = document.getElementById('pull-log-modal');
    if (modal) modal.style.display = 'none';
    pullLogJobs = [];
}

export async function loadPushRecords() {
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
            else if (st === 'unstable') { bg = '#fff7ed'; fg = '#c2410c'; }
            else if (st === 'manual') { bg = '#f5f3ff'; fg = '#6d28d9'; }
            return '<span class="badge" style="background:' + bg + ';color:' + fg + ';">' + esc(s || '—') + '</span>';
        };
        tbody.innerHTML = records.map(r => {
            const vars = r.variables_json || '';
            const safeLogUrl = safeUrl(r.log_url);
            const logCell = safeLogUrl
                ? '<a href="' + esc(safeLogUrl) + '" target="_blank" rel="noopener noreferrer">' + esc(__.t('push.view_log')) + '</a>'
                : '—';
            const safeWebUrl = safeUrl(r.web_url);
            const webCell = safeWebUrl
                ? '<a href="' + esc(safeWebUrl) + '" target="_blank" rel="noopener noreferrer" style="display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;" title="' + esc(safeWebUrl) + '">' + esc(safeWebUrl) + '</a>'
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
            pag += '<button class="btn btn-sm" onclick="setPushPage(1)" ' + (pushPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pag += '<button class="btn btn-sm" onclick="setPushPage(Math.max(1,getPushPage()-1))" ' + (pushPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pag += '<span style="color:#374151;font-weight:600;">' + pushPage + ' / ' + pushTotalPages + '</span>';
            pag += '<button class="btn btn-sm" onclick="setPushPage(Math.min(getPushTotalPages(),getPushPage()+1))" ' + (pushPage>=pushTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pag += '<button class="btn btn-sm" onclick="setPushPage(getPushTotalPages())" ' + (pushPage>=pushTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            pagination.innerHTML = pag;
        }
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#dc2626;">' + __.t('js.network_error') + ': ' + esc(e.message) + '</td></tr>';
    }
}

export function getPushPage() { return pushPage; }
export function getPushTotalPages() { return pushTotalPages; }
export function setPushPage(p) { pushPage = p; loadPushRecords(); }