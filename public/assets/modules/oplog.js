import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast } from '../core/toast.js';

let oplogPage = 1;

export async function loadOperationLogs(page) {
    if (typeof page === 'number') oplogPage = page;
    const loading = document.getElementById('oplog-loading');
    const wrap = document.getElementById('oplog-table-wrap');
    const empty = document.getElementById('oplog-empty');
    const pager = document.getElementById('oplog-pagination');
    loading.style.display = 'block'; wrap.style.display = 'none'; empty.style.display = 'none'; pager.style.display = 'none';
    const q = new URLSearchParams();
    const username = document.getElementById('oplog-username').value.trim();
    const action = document.getElementById('oplog-action').value.trim();
    const result = document.getElementById('oplog-result').value;
    const operatorType = document.getElementById('oplog-operator-type').value;
    const dateFrom = document.getElementById('oplog-date-from').value;
    const dateTo = document.getElementById('oplog-date-to').value;
    if (username) q.set('username', username);
    if (action) q.set('action', action);
    if (result) q.set('result', result);
    if (operatorType) q.set('operator_type', operatorType);
    if (dateFrom) q.set('date_from', dateFrom);
    if (dateTo) q.set('date_to', dateTo);
    q.set('page', oplogPage);
    q.set('per_page', 20);
    try {
        const res = await fetch('/api/admin/operation_logs?' + q.toString(), { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (!res.ok) { toast(data.message || __.t('js.operation_failed'), false, true); return; }
        const rows = data.items || [];
        const tbody = document.getElementById('oplog-tbody');
        if (!rows.length) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = rows.map(function(r) {
                let detail = '';
                if (r.detail && typeof r.detail === 'object') {
                    detail = Object.keys(r.detail).map(function(k) {
                        const v = r.detail[k];
                        const sv = (typeof v === 'object') ? JSON.stringify(v) : String(v);
                        return esc(k) + '=' + esc(sv);
                    }).join(', ');
                } else if (r.detail) {
                    detail = esc(String(r.detail));
                }
                const resultHtml = r.result === 'success'
                    ? '<span style="color:#16a34a;">✅ ' + esc(__.t('oplog.success')) + '</span>'
                    : '<span style="color:#dc2626;">❌ ' + esc(__.t('oplog.failure')) + '</span>';
                const opBadge = r.operator_type === 'api_token'
                    ? ' <span class="badge badge-default" title="' + esc(__.t('oplog.api_token')) + '">🔑 ' + esc(__.t('oplog.api_token')) + '</span>'
                    : '';
                const time = (r.created_at || '').replace('T', ' ').substring(0, 19);
                return '<tr>' +
                    '<td style="font-size:12px;white-space:nowrap;color:#6b7280;">' + esc(time) + '</td>' +
                    '<td>' + esc(r.username) + opBadge + '</td>' +
                    '<td>' + esc(oplogActionLabel(r.action)) + '</td>' +
                    '<td>' + esc(r.target || '') + '</td>' +
                    '<td style="font-size:12px;max-width:320px;overflow-wrap:anywhere;">' + detail + '</td>' +
                    '<td style="font-size:12px;color:#6b7280;">' + esc(r.ip || '') + '</td>' +
                    '<td>' + resultHtml + '</td>' +
                '</tr>';
            }).join('');
            wrap.style.display = 'block';
            renderOplogPagination(data);
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false, true);
    }
    loading.style.display = 'none';
}

export function resetOperationLogs() {
    document.getElementById('oplog-username').value = '';
    document.getElementById('oplog-action').value = '';
    document.getElementById('oplog-result').value = '';
    document.getElementById('oplog-operator-type').value = '';
    document.getElementById('oplog-date-from').value = '';
    document.getElementById('oplog-date-to').value = '';
    loadOperationLogs(1);
}

function oplogActionLabel(action) {
    const key = 'oplog.act_' + action;
    const label = __.t(key);
    return label === key ? action : label;
}

function renderOplogPagination(data) {
    const pager = document.getElementById('oplog-pagination');
    if (data.total_pages <= 1) { pager.style.display = 'none'; return; }
    const page = data.page, total = data.total_pages, totalItems = data.total;
    let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: totalItems}) + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadOperationLogs(1)" ' + (page<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
    pag += '<button class="btn btn-sm" onclick="loadOperationLogs(Math.max(1,' + (page-1) + '))" ' + (page<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
    pag += '<span style="color:#374151;font-weight:600;">' + page + ' / ' + total + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadOperationLogs(Math.min(' + total + ',' + (page+1) + '))" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
    pag += '<button class="btn btn-sm" onclick="loadOperationLogs(' + total + ')" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
    pager.innerHTML = pag;
    pager.style.display = 'flex';
}