import { authHeaders, handle401 } from '../core/api.js';
import { esc, fmtYmd } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';

let _apiScopes = [];
let _apiTokenCreated = '';

export async function loadApiTokens() {
    const loading = document.getElementById('api-token-loading');
    const wrap = document.getElementById('api-token-table-wrap');
    const empty = document.getElementById('api-token-empty');
    loading.style.display = 'block'; wrap.style.display = 'none'; empty.style.display = 'none';
    try {
        const res = await fetch('/api/admin/api_tokens', { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        const rows = data.tokens || [];
        const tbody = document.getElementById('api-token-tbody');
        if (!rows.length) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = rows.map(function(t) {
                const scopes = (t.capabilities || t.scopes || []).map(esc).join(', ');
                const exp = t.expires_at ? fmtYmd(t.expires_at) : esc(__.t('api_token.never'));
                const status = t.enabled === false
                    ? '<span style="color:#9ca3af;">🚫 ' + esc(__.t('api_token.disabled')) + '</span>'
                    : (t.expired
                        ? '<span style="color:#dc2626;">⏳ ' + esc(__.t('api_token.expired')) + '</span>'
                        : '<span style="color:#16a34a;">✅ ' + esc(__.t('api_token.active')) + '</span>');
                const actions = (t.enabled === false ? '' :
                    '<button class="btn btn-sm btn-warn" onclick="revokeApiToken(' + t.id + ')">⛔ ' + esc(__.t('api_token.revoke')) + '</button> ')
                    + '<button class="btn btn-sm btn-del" onclick="deleteApiToken(' + t.id + ')">🗑 ' + esc(__.t('api_token.delete')) + '</button>';
                return '<tr>' +
                    '<td>' + esc(t.name) + '</td>' +
                    '<td class="scope-cell" style="font-size:12px;max-width:260px;">' + scopes + '</td>' +
                    '<td>' + exp + '</td>' +
                    '<td>' + status + '</td>' +
                    '<td style="font-size:12px;">' + esc(t.created_at || '') + '</td>' +
                    '<td>' + actions + '</td>' +
                '</tr>';
            }).join('');
            wrap.style.display = 'block';
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false, true); }
    loading.style.display = 'none';
}

async function loadApiTokenScopes() {
    const lang = __.lang === 'en' ? 'en' : 'zh_CN';
    try {
        const res = await fetch('/api/admin/api_tokens/scopes?lang=' + lang, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        _apiScopes = data.scopes || [];
    } catch(e) {}
}

function renderApiTokenScopes(preserveChecked) {
    const box = document.getElementById('api-token-scopes');
    if (!box) return;
    const checked = {};
    if (preserveChecked) {
        box.querySelectorAll('input[data-scope]:checked').forEach(function(i) { checked[i.value] = true; });
    }
    box.innerHTML = _apiScopes.map(function(s) {
        return '<label style="display:grid; grid-template-columns:1fr 9fr;gap:5px;font-size:10px;padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">' +
            '<input type="checkbox" value="' + esc(s.key) + '" data-scope' + (checked[s.key] ? ' checked' : '') + '>' +
            '<span>' + esc(s.label) + '</span>' +
        '</label>';
    }).join('');
}

export async function showApiTokenForm() {
    document.getElementById('api-token-form').style.display = 'block';
    document.getElementById('api-token-created').style.display = 'none';
    document.getElementById('api-token-name').value = '';
    document.getElementById('api-token-expires').value = '';
    document.getElementById('api-token-note').value = '';
    document.getElementById('api-token-msg').textContent = '';
    await loadApiTokenScopes();
    renderApiTokenScopes(false);
}

export function hideApiTokenForm() {
    document.getElementById('api-token-form').style.display = 'none';
}

export async function refreshApiTokenView() {
    await loadApiTokenScopes();
    renderApiTokenScopes(true);
    loadApiTokens();
}

export async function submitApiTokenForm(e) {
    e.preventDefault();
    const name = document.getElementById('api-token-name').value.trim();
    const expires = document.getElementById('api-token-expires').value.trim();
    const note = document.getElementById('api-token-note').value.trim();
    const scopes = Array.from(document.querySelectorAll('#api-token-scopes input[data-scope]:checked')).map(i => i.value);
    const msg = document.getElementById('api-token-msg');
    msg.textContent = '';
    if (!name) { msg.textContent = __.t('api_token.name_required'); msg.style.color = '#dc2626'; return; }

    let expiresAt = null;
    if (expires) {
        const iso = expires.replace(/\//g, '-');
        const d = new Date(iso + 'T23:59:59');
        if (isNaN(d.getTime())) { msg.textContent = __.t('api_token.expires_invalid'); msg.style.color = '#dc2626'; return; }
        expiresAt = Math.floor(d.getTime() / 1000);
    }

    try {
        const res = await fetch('/api/admin/api_tokens', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: JSON.stringify({ name: name, scopes: scopes, expires_at: expiresAt, note: note })
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok && data.token) {
            _apiTokenCreated = data.token;
            document.getElementById('api-token-form').style.display = 'none';
            const created = document.getElementById('api-token-created');
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

export function copyApiToken() {
    if (!_apiTokenCreated) return;
    navigator.clipboard.writeText(_apiTokenCreated).then(
        function(){ toast(__.t('api_token.copied'), true); },
        function(){ toast(__.t('api_token.copy_failed'), false); }
    );
}

export async function revokeApiToken(id) {
    if (!await confirmDialog({ title: '⛔ ' + __.t('api_token.confirm_revoke'), message: __.t('api_token.confirm_revoke'), confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/api_tokens/' + id + '/revoke', { method: 'POST', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('api_token.revoked'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

export async function deleteApiToken(id) {
    if (!await confirmDialog({ title: '🗑️ ' + __.t('api_token.confirm_delete'), message: __.t('api_token.confirm_delete'), confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/api_tokens/' + id, { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('api_token.deleted'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}