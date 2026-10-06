import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast } from '../core/toast.js';

let apilogPage = 1;

export async function loadApiAccessLogs(page) {
    if (typeof page === 'number') apilogPage = page;
    const loading = document.getElementById('apilog-loading');
    const wrap = document.getElementById('apilog-table-wrap');
    const empty = document.getElementById('apilog-empty');
    const pager = document.getElementById('apilog-pagination');
    loading.style.display = 'block'; wrap.style.display = 'none'; empty.style.display = 'none'; pager.style.display = 'none';

    const q = new URLSearchParams();
    const tokenName = document.getElementById('apilog-token-name').value.trim();
    const route = document.getElementById('apilog-route').value.trim();
    const method = document.getElementById('apilog-method').value;
    const result = document.getElementById('apilog-result').value;
    const dateFrom = document.getElementById('apilog-date-from').value;
    const dateTo = document.getElementById('apilog-date-to').value;
    if (tokenName) q.set('token_name', tokenName);
    if (route) q.set('route', route);
    if (method) q.set('method', method);
    if (result) q.set('result', result);
    if (dateFrom) q.set('date_from', dateFrom);
    if (dateTo) q.set('date_to', dateTo);
    q.set('page', apilogPage);
    q.set('per_page', 20);

    try {
        const res = await fetch('/api/admin/api_access_logs?' + q.toString(), { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (!res.ok) { toast(data.message || __.t('js.operation_failed'), false, true); return; }
        const rows = data.items || [];
        const tbody = document.getElementById('apilog-tbody');
        if (!rows.length) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = rows.map(function(r) {
                const time = (r.created_at || '').replace('T', ' ').substring(0, 19);
                return '<tr>' +
                    '<td style="font-size:12px;white-space:nowrap;color:#6b7280;">' + esc(time) + '</td>' +
                    '<td style="font-size:12px;white-space:nowrap;">' + esc(r.token_name || '') + '</td>' +
                    '<td style="font-size:12px;white-space:nowrap;">' + esc(r.method || '') + '</td>' +
                    '<td style="font-size:12px;max-width:260px;overflow-wrap:anywhere;white-space:normal;">' + esc(r.route || '') + '</td>' +
                    '<td style="font-size:12px;">' + esc(String(r.status_code ?? '')) + '</td>' +
                    '<td>' + apilogResultBadge(r.result) + '</td>' +
                    '<td style="font-size:12px;max-width:220px;overflow-wrap:anywhere;white-space:normal;color:#6b7280;">' + esc(r.error_reason || '') + '</td>' +
                    '<td style="font-size:12px;max-width:180px;overflow-wrap:anywhere;white-space:normal;color:#6b7280;">' + esc(r.scopes || '') + '</td>' +
                    '<td style="font-size:12px;white-space:nowrap;">' + esc(r.ip || '') + '</td>' +
                    '<td style="font-size:12px;white-space:nowrap;text-align:right;">' + esc(String(r.duration_ms ?? 0)) + '</td>' +
                '</tr>';
            }).join('');
            wrap.style.display = 'block';
            renderApilogPagination(data);
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false, true);
    }
    loading.style.display = 'none';
}

export function resetApiAccessLogs() {
    document.getElementById('apilog-token-name').value = '';
    document.getElementById('apilog-route').value = '';
    document.getElementById('apilog-method').value = '';
    document.getElementById('apilog-result').value = '';
    document.getElementById('apilog-date-from').value = '';
    document.getElementById('apilog-date-to').value = '';
    loadApiAccessLogs(1);
}

function apilogResultBadge(result) {
    // 白名单着色，未知值原样 esc 输出（防御后端新增枚举）
    const colorMap = { success: '#16a34a', failure: '#dc2626', denied: '#d97706' };
    const labelMap = {
        success: 'apilog.result_success',
        failure: 'apilog.result_failure',
        denied: 'apilog.result_denied',
    };
    const key = labelMap[result] ? __.t(labelMap[result]) : result;
    const color = colorMap[result] || '#6b7280';
    return '<span style="color:' + color + ';font-weight:600;">' + esc(key) + '</span>';
}

function renderApilogPagination(data) {
    const pager = document.getElementById('apilog-pagination');
    if (data.total_pages <= 1) { pager.style.display = 'none'; return; }
    const page = data.page, total = data.total_pages, totalItems = data.total;
    let pag = '<span style="color:#6b7280;">' + __.t('js.total_items', {total: totalItems}) + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadApiAccessLogs(1)" ' + (page<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
    pag += '<button class="btn btn-sm" onclick="loadApiAccessLogs(Math.max(1,' + (page-1) + '))" ' + (page<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
    pag += '<span style="color:#374151;font-weight:600;">' + page + ' / ' + total + '</span>';
    pag += '<button class="btn btn-sm" onclick="loadApiAccessLogs(Math.min(' + total + ',' + (page+1) + '))" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
    pag += '<button class="btn btn-sm" onclick="loadApiAccessLogs(' + total + ')" ' + (page>=total?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
    pager.innerHTML = pag;
    pager.style.display = 'flex';
}
