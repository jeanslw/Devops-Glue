import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast } from '../core/toast.js';

let deploylogPage = 1;

export async function loadDeployLogs(page) {
    if (typeof page === 'number') deploylogPage = page;
    const loading = document.getElementById('deploylog-loading');
    const wrap = document.getElementById('deploylog-table-wrap');
    const empty = document.getElementById('deploylog-empty');
    const unavailable = document.getElementById('deploylog-unavailable');
    const pager = document.getElementById('deploylog-pagination');
    loading.style.display = 'block'; wrap.style.display = 'none'; empty.style.display = 'none'; unavailable.style.display = 'none'; pager.style.display = 'none';
    const q = new URLSearchParams();
    const project = document.getElementById('deploylog-project').value.trim();
    const status = document.getElementById('deploylog-status').value;
    const deployType = document.getElementById('deploylog-deploy-type').value;
    const dateFrom = document.getElementById('deploylog-date-from').value;
    const dateTo = document.getElementById('deploylog-date-to').value;
    if (project) q.set('project', project);
    if (status) q.set('status', status);
    if (deployType) q.set('deploy_type', deployType);
    if (dateFrom) q.set('date_from', dateFrom);
    if (dateTo) q.set('date_to', dateTo);
    q.set('page', deploylogPage);
    q.set('per_page', 20);
    try {
        const res = await fetch('/api/admin/deploy_logs?' + q.toString(), { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (!res.ok) { toast(data.message || __.t('js.operation_failed'), false, true); return; }
        if (!data.available) {
            unavailable.style.display = 'block';
            return;
        }
        const rows = data.items || [];
        const tbody = document.getElementById('deploylog-tbody');
        if (!rows.length) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = rows.map(function(r) {
                const time = (r.created_at || '').replace('T', ' ').substring(0, 19);
                const statusHtml = deploylogStatusBadge(r.status);
                return '<tr>' +
                    '<td style="font-size:12px;white-space:nowrap;color:#6b7280;">' + esc(time) + '</td>' +
                    '<td>' + esc(r.project || '') + '</td>' +
                    '<td style="font-size:12px;">' + esc(r.tag || '') + '</td>' +
                    '<td style="font-size:12px;max-width:240px;overflow-wrap:anywhere;white-space:normal;color:#6b7280;">' + esc(r.image || '') + '</td>' +
                    '<td>' + esc(deploylogTypeLabel(r.deploy_type)) + '</td>' +
                    '<td style="font-size:12px;max-width:200px;overflow-wrap:anywhere;white-space:normal;">' + esc(r.target || '') + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                    '<td>' + esc(r.triggered_by || '') + '</td>' +
                    '<td style="font-size:12px;max-width:200px;overflow-wrap:anywhere;white-space:normal;color:#6b7280;">' + esc(r.deploy_note || '') + '</td>' +
                '</tr>';
            }).join('');
            wrap.style.display = 'block';
            renderDeploylogPagination(data);
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false, true);
    }
    loading.style.display = 'none';
}

export function resetDeployLogs() {
    document.getElementById('deploylog-project').value = '';
    document.getElementById('deploylog-status').value = '';
    document.getElementById('deploylog-deploy-type').value = '';
    document.getElementById('deploylog-date-from').value = '';
    document.getElementById('deploylog-date-to').value = '';
    loadDeployLogs(1);
}

function deploylogStatusBadge(status) {
    const map = {
        ok:          ['#16a34a', 'deploylog.status_ok'],
        failed:      ['#dc2626', 'deploylog.status_failed'],
        running:     ['#2563eb', 'deploylog.status_running'],
        pending:     ['#d97706', 'deploylog.status_pending'],
        terminated:  ['#6b7280', 'deploylog.status_terminated'],
        interrupted: ['#dc2626', 'deploylog.status_interrupted'],
        partial:     ['#d97706', 'deploylog.status_partial'],
    };
    const m = map[status] || ['#6b7280', null];
    const label = m[1] ? __.t(m[1]) : status;
    return '<span style="color:' + m[0] + ';">' + esc(label) + '</span>';
}

function deploylogTypeLabel(type) {
    if (!type) return '';
    const key = 'deploylog.type_' + type;
    const label = __.t(key);
    return label === key ? type : label;
}

function renderDeploylogPagination(data) {
    const pager = document.getElementById('deploylog-pagination');
    if (data.total_pages <= 1) { pager.style.display = 'none'; return; }
    const page = data.page, total = data.total_pages, totalItems = data.total;
    let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: totalItems}) + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadDeployLogs(1)" ' + (page<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
    pag += '<button class="btn btn-sm" onclick="loadDeployLogs(Math.max(1,' + (page-1) + '))" ' + (page<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
    pag += '<span style="color:#374151;font-weight:600;">' + page + ' / ' + total + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadDeployLogs(Math.min(' + total + ',' + (page+1) + '))" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
    pag += '<button class="btn btn-sm" onclick="loadDeployLogs(' + total + ')" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
    pager.innerHTML = pag;
    pager.style.display = 'flex';
}