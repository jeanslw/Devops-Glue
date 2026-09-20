import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';

const SEC_API = '/api/admin/security_checks';
let secPage = 1, secTotalPages = 1, secDebounce = null;
const STATE_ICONS = {success:'✅',failed:'❌',error:'⚠️',pending:'⏳'};
const STATE_LABELS = {success:'security.passed',failed:'security.failed',error:'security.error',pending:'security.pending'};
const WB_ICONS = {success:'✅', failed:'❌', skipped:'⏭️'};
const WB_LABELS = {success:'security.writeback_success', failed:'security.writeback_failed', skipped:'security.writeback_skipped'};

export function secOnFilterChange() {
    clearTimeout(secDebounce);
    secDebounce = setTimeout(() => { secPage = 1; loadSecurityChecks(); }, 300);
}

export async function loadSecurityChecks() {
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
        if (secPage > secTotalPages) secPage = secTotalPages;

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
            pag += '<button class="btn btn-sm" onclick="secSetPage(1)" ' + (secPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
            pag += '<button class="btn btn-sm" onclick="secSetPage(Math.max(1,getSecPage()-1))" ' + (secPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
            pag += '<span style="color:#374151;font-weight:600;">' + secPage + ' / ' + secTotalPages + '</span>';
            pag += '<button class="btn btn-sm" onclick="secSetPage(Math.min(getSecTotalPages(),getSecPage()+1))" ' + (secPage>=secTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
            pag += '<button class="btn btn-sm" onclick="secSetPage(getSecTotalPages())" ' + (secPage>=secTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
            document.getElementById('sec-pagination').innerHTML = pag;
        }
    } catch(e) {
        document.getElementById('sec-loading').textContent = __.t('js.load_failed') + ': ' + e.message;
    }
}

export function getSecPage() { return secPage; }
export function getSecTotalPages() { return secTotalPages; }
export function secSetPage(p) { secPage = p; loadSecurityChecks(); }