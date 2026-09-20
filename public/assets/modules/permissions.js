import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import { hasPermission } from '../core/auth.js';
import { resetPermsCache } from './roles.js';

export async function loadPermList() {
    const loading = document.getElementById('perm-list-loading');
    const tableWrap = document.getElementById('perm-list-table-wrap');
    const tbody = document.getElementById('perm-list-tbody');
    const empty = document.getElementById('perm-list-empty');
    loading.style.display = 'block';
    tableWrap.style.display = 'none';
    empty.style.display = 'none';
    try {
        const res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const perms = data.permissions || [];
        if (perms.length === 0) { loading.style.display = 'none'; empty.style.display = 'block'; return; }
        const canDelete = hasPermission('ci.permissions.register');
        tbody.innerHTML = perms.map(function(p) {
            const type = p.is_builtin
                ? '<span class="badge badge-sys">' + __.t('perm.builtin') + '</span>'
                : '<span class="badge badge-cus">' + __.t('perm.registered') + '</span>';
            let actions;
            if (p.is_builtin) actions = '<span style="color:#9ca3af;font-size:12px;" title="' + __.t('permission.builtin_protected') + '">🔒</span>';
            else if (canDelete) actions = '<button class="btn btn-sm btn-del" onclick="deletePermission(\'' + p.perm_key + '\')" title="' + __.t('common.delete') + '">🗑</button>';
            else actions = '<span style="color:#9ca3af;font-size:12px;">—</span>';
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

export async function deletePermission(key) {
    if (!await confirmDialog({
        title: '🗑️ ' + __.t('perm.confirm_delete'),
        message: key,
        note: __.t('perm.confirm_delete'),
        confirmText: __.t('common.confirm')
    })) return;
    try {
        const res = await fetch('/api/admin/permissions/' + encodeURIComponent(key), { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            toast(__.t('perm.delete_ok'), true);
            resetPermsCache();
            loadPermList();
            window.loadRoleList();
        } else {
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

export async function registerPermission(e) {
    e.preventDefault();
    const key = document.getElementById('new-perm-key').value.trim();
    const desc = document.getElementById('new-perm-desc').value.trim();
    const parent = document.getElementById('new-perm-parent').value.trim();
    const msg = document.getElementById('perm-register-msg');
    if (!key) { msg.textContent = __.t('perm.key_required'); msg.style.color = '#dc2626'; return; }
    if (!desc) { msg.textContent = __.t('perm.desc_required'); msg.style.color = '#dc2626'; return; }
    try {
        const res = await fetch('/api/admin/permissions', {
            method: 'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, authHeaders()),
            body: JSON.stringify({ perm_key: key, description: desc || null, parent_key: parent || null })
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ ' + __.t('perm.register_ok');
            msg.style.color = '#16a34a';
            document.getElementById('new-perm-key').value = '';
            document.getElementById('new-perm-desc').value = '';
            document.getElementById('new-perm-parent').value = '';
            loadPermList();
            window.loadRoleList();
        } else {
            msg.textContent = data.message || __.t('js.operation_failed');
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = __.t('js.network_error'); msg.style.color = '#dc2626'; }
}

export async function loadImpliedRules() {
    const loading = document.getElementById('implied-loading');
    const tableWrap = document.getElementById('implied-table-wrap');
    const tbody = document.getElementById('implied-tbody');
    const empty = document.getElementById('implied-empty');
    loading.style.display = 'block';
    tableWrap.style.display = 'none';
    empty.style.display = 'none';
    try {
        const res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const implied = data.implied || {};
        const builtin = data.builtin_implied || {};
        const rules = [];
        Object.keys(implied).forEach(function(src) {
            implied[src].forEach(function(tgt) { rules.push({source: src, target: tgt}); });
        });
        if (rules.length === 0) { loading.style.display = 'none'; empty.style.display = 'block'; return; }
        tbody.innerHTML = rules.map(function(r) {
            const isBuiltin = (builtin[r.source] || []).indexOf(r.target) >= 0;
            const type = isBuiltin
                ? '<span class="badge badge-sys">' + __.t('perm.builtin') + '</span>'
                : '<span class="badge badge-cus">' + __.t('perm.registered') + '</span>';
            const actions = isBuiltin
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

export async function showImpliedForm() {
    const form = document.getElementById('implied-form');
    const srcSel = document.getElementById('implied-source');
    const tgtSel = document.getElementById('implied-target');
    form.style.display = 'block';
    form.scrollIntoView({behavior: 'smooth'});
    try {
        const res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const perms = data.permissions || [];
        const opts = perms.map(function(p) { return '<option value="' + p.perm_key + '">' + p.perm_key + '</option>'; }).join('');
        srcSel.innerHTML = '<option value="">' + __.t('common.please_select') + '</option>' + opts;
        tgtSel.innerHTML = '<option value="">' + __.t('common.please_select') + '</option>' + opts;
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

export function hideImpliedForm() {
    document.getElementById('implied-form').style.display = 'none';
    document.getElementById('implied-source').value = '';
    document.getElementById('implied-target').value = '';
    document.getElementById('implied-msg').textContent = '';
}

export async function submitImpliedForm(e) {
    e.preventDefault();
    const src = document.getElementById('implied-source').value;
    const tgt = document.getElementById('implied-target').value;
    const msg = document.getElementById('implied-msg');
    if (!src || !tgt) { msg.textContent = __.t('implied.both_required'); msg.style.color = '#dc2626'; return; }
    if (src === tgt) { msg.textContent = __.t('implied.cannot_same'); msg.style.color = '#dc2626'; return; }
    try {
        const res = await fetch('/api/admin/implied_rules', {
            method: 'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, authHeaders()),
            body: JSON.stringify({ source_key: src, target_key: tgt })
        });
        if (handle401(res)) return;
        const data = await res.json();
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

export async function deleteImpliedRule(src, tgt) {
    if (!await confirmDialog({
        title: '🗑️ ' + __.t('implied.confirm_delete'),
        message: src + ' → ' + tgt,
        note: __.t('implied.confirm_delete'),
        confirmText: __.t('common.confirm')
    })) return;
    try {
        const res = await fetch('/api/admin/implied_rules?source_key=' + encodeURIComponent(src) + '&target_key=' + encodeURIComponent(tgt), {
            method: 'DELETE',
            headers: authHeaders()
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('implied.delete_ok'), true); loadImpliedRules(); }
        else { toast(data.message || __.t('js.operation_failed'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}