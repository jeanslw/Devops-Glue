import { authHeaders, handle401 } from '../core/api.js';
import { esc, fmtYmd } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';

let _apiScopes = [];
let _apiTokenCreated = '';
let _tokenRows = [];
let _editingTokenId = null;

function tokenById(id) {
    return _tokenRows.find(function(x) { return x.id === id; }) || null;
}

function tokenNameById(id) {
    const t = tokenById(id);
    return (t && t.name) ? t.name : ('#' + id);
}

// 读取某个 scope 勾选框里已勾选的 scope key
function checkedScopes(boxId) {
    return Array.from(document.querySelectorAll('#' + boxId + ' input[data-scope]:checked')).map(function(i) { return i.value; });
}

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
        _tokenRows = rows;
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
                // 编辑 Scope 对「已禁用 / 已过期」的行同样可用：改的是元数据，与明文和有效性无关
                const actions = '<button class="btn btn-sm" onclick="showApiTokenScopeForm(' + t.id + ')">✏️ ' + esc(__.t('api_token.edit_scopes')) + '</button> '
                    + (t.enabled === false ? '' :
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

// 新增 / 编辑两个表单共用同一套 scope 勾选框渲染（selected 为需要预勾选的 scope key 数组）
function renderScopeBoxes(boxId, selected) {
    const box = document.getElementById(boxId);
    if (!box) return;
    const on = {};
    (selected || []).forEach(function(k) { on[k] = true; });
    box.innerHTML = _apiScopes.map(function(s) {
        return '<label style="display:grid; grid-template-columns:1fr 9fr;gap:5px;font-size:10px;padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">' +
            '<input type="checkbox" value="' + esc(s.key) + '" data-scope' + (on[s.key] ? ' checked' : '') + '>' +
            '<span>' + esc(s.label) + '</span>' +
        '</label>';
    }).join('');
}

function renderApiTokenScopes(preserveChecked) {
    renderScopeBoxes('api-token-scopes', preserveChecked ? checkedScopes('api-token-scopes') : []);
}

export async function showApiTokenForm() {
    hideApiTokenScopeForm();                 // 与「编辑 Scope」互斥，避免两处勾选框同时可见看错行
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

/**
 * 打开某条 Token 的「编辑 Scope」表单，按当前 scope 预勾选。
 * 改的是元数据（scopes 列），保存后立即生效，明文 Token 不变、无需重新签发。
 */
export async function showApiTokenScopeForm(id) {
    const token = tokenById(id);
    if (!token) return;
    hideApiTokenForm();
    _editingTokenId = id;
    document.getElementById('api-token-edit-name').textContent = token.name || ('#' + id);
    document.getElementById('api-token-edit-msg').textContent = '';
    document.getElementById('api-token-scope-form').style.display = 'block';
    await loadApiTokenScopes();
    renderScopeBoxes('api-token-edit-scopes', token.scopes || []);
}

export function hideApiTokenScopeForm() {
    document.getElementById('api-token-scope-form').style.display = 'none';
    _editingTokenId = null;
}

export async function refreshApiTokenView() {
    await loadApiTokenScopes();
    renderApiTokenScopes(true);
    // 编辑框若开着：按当前勾选状态重建，保留未保存的改动，只刷新 label 文案
    if (_editingTokenId !== null) {
        renderScopeBoxes('api-token-edit-scopes', checkedScopes('api-token-edit-scopes'));
    }
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

/**
 * 提交「编辑 Scope」：PUT /api/admin/api_tokens/{id}/scopes
 * 后端对空集合与目录外的 scope 一律 400 拒绝，这里只做「空勾选」的前置提示。
 */
export async function submitApiTokenScopeForm(e) {
    if (e) e.preventDefault();
    if (_editingTokenId === null) return;
    const msg = document.getElementById('api-token-edit-msg');
    msg.textContent = '';
    const scopes = checkedScopes('api-token-edit-scopes');
    if (!scopes.length) { msg.textContent = __.t('api_token.scopes_required'); msg.style.color = '#dc2626'; return; }

    try {
        const res = await fetch('/api/admin/api_tokens/' + _editingTokenId + '/scopes', {
            method: 'PUT',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: JSON.stringify({ scopes: scopes })
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            toast(__.t('api_token.scopes_updated'), true);
            hideApiTokenScopeForm();
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
    if (!await confirmDialog({ title: '⛔ ' + __.t('api_token.revoke_title'), message: __.t('api_token.confirm_revoke') + ' [' + tokenNameById(id) + ']', confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/api_tokens/' + id + '/revoke', { method: 'POST', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('api_token.revoked'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}

export async function deleteApiToken(id) {
    if (!await confirmDialog({ title: '🗑️ ' + __.t('api_token.delete_title'), message: __.t('api_token.confirm_delete') + ' [' + tokenNameById(id) + ']', confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/api_tokens/' + id, { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('api_token.deleted'), true); loadApiTokens(); }
        else { toast(data.message || __.t('js.network_error'), false); }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}