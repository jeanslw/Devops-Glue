import { apiFetch } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';

let _oidc = null;

// 来源徽标：database=绿（已持久化，DB 权威）；无来源时返回空（issuer/ttl 未在 DB 配置时为空）
function sourceBadge(source) {
    if (source === 'database') {
        return '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;margin-left:6px;white-space:nowrap;">✓ ' + esc(__.t('oidc.source_db')) + '</span>';
    }
    return '';
}

// 单行客户端：既有行 client_id 只读（改名会新建孤儿行），新增行才可编辑 client_id。
function renderClientRow(c, idx) {
    const isNew = !!c._new;
    const cid = isNew
        ? '<input type="text" class="oidc-cid-input" data-idx="' + idx + '" placeholder="' + esc(__.t('oidc.new_client_placeholder')) + '" value="' + esc(c.client_id || '') + '">'
        : '<span class="oidc-cid">' + esc(c.client_id) + '</span>';
    const secretHint = c.secret_set
        ? '<span style="color:#16a34a;font-size:11px;">(' + esc(__.t('oidc.secret_set')) + ')</span>'
        : '<span style="color:#9ca3af;font-size:11px;">(' + esc(__.t('oidc.secret_unset')) + ')</span>';
    // 所有客户端都是 DB 行，可删除（删除后该客户端不再可用）
    const delBtn = '<button class="btn btn-sm btn-del" onclick="onOidcClientDelete(' + idx + ')">🗑 ' + esc(__.t('oidc.delete')) + '</button>';

    return '<div class="oidc-row">'
        + '<div class="oidc-row-hd">'
            + cid
            + sourceBadge(c.source)
            + '<span style="margin-left:auto;display:inline-flex;align-items:center;gap:8px;">'
                + '<label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;">'
                    + '<input type="checkbox" class="oidc-enabled" data-idx="' + idx + '"' + (c.enabled ? ' checked' : '') + '>'
                    + esc(__.t('oidc.enabled'))
                + '</label>'
                + delBtn
            + '</span>'
        + '</div>'
        + '<div class="oidc-grid">'
            + '<label class="oidc-lbl">' + esc(__.t('oidc.client_redirect')) + '</label>'
            + '<input type="text" class="oidc-redirect" data-idx="' + idx + '" value="' + esc(c.redirect_uri || '') + '" placeholder="https://cd.example.com/api/auth/oidc/callback">'
            + '<label class="oidc-lbl">' + esc(__.t('oidc.client_system')) + '</label>'
            + '<input type="text" class="oidc-system" data-idx="' + idx + '" value="' + esc(c.system || '') + '" placeholder="cd / jenkins">'
            + '<label class="oidc-lbl">' + esc(__.t('oidc.client_secret')) + ' ' + secretHint + '</label>'
            + '<input type="password" class="oidc-secret" data-idx="' + idx + '" value="" placeholder="' + esc(__.t('oidc.secret_keep')) + '" autocomplete="new-password">'
        + '</div>'
    + '</div>';
}

function renderOidc() {
    const body = document.getElementById('oidc-body');
    if (!body || !_oidc) return;
    const clients = _oidc.clients || [];
    const rows = clients.map(renderClientRow).join('')
        || '<div style="color:#9ca3af;font-size:12px;padding:6px 0;">' + esc(__.t('oidc.no_clients')) + '</div>';
    body.innerHTML =
        '<div style="display:flex;flex-direction:column;gap:12px;">'
        + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
            + '<label style="min-width:72px;font-size:13px;font-weight:600;white-space:nowrap;">' + esc(__.t('oidc.issuer_label')) + '</label>'
            + '<input type="text" id="oidc-issuer" value="' + esc(_oidc.issuer || '') + '" placeholder="' + esc(__.t('oidc.issuer_placeholder')) + '" style="flex:1 1 280px;min-width:0;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">'
            + sourceBadge(_oidc.issuer_source)
        + '</div>'
        + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
            + '<label style="min-width:72px;font-size:13px;font-weight:600;white-space:nowrap;">' + esc(__.t('oidc.ttl_label')) + '</label>'
            + '<input type="number" id="oidc-ttl" min="1" value="' + esc(String(_oidc.id_token_ttl || 3600)) + '" style="width:120px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">'
            + sourceBadge(_oidc.id_token_ttl_source)
        + '</div>'
        + '<div style="border-top:1px dashed #e5e7eb;padding-top:12px;">'
            + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">'
                + '<span style="font-size:13px;font-weight:700;">' + esc(__.t('oidc.clients_label')) + '</span>'
                + '<button class="btn btn-sm" onclick="onOidcAddClient()" style="margin-left:auto;">' + esc(__.t('oidc.add_client')) + '</button>'
            + '</div>'
            + rows
        + '</div>'
        + '<div style="display:flex;justify-content:flex-end;margin-top:12px;">'
            + '<button class="btn btn-pri" onclick="saveOidcConfig()">💾 ' + esc(__.t('oidc.save')) + '</button>'
        + '</div>'
        + '</div>';
}

export async function loadOidcConfig() {
    const body = document.getElementById('oidc-body');
    if (!body) return;
    try {
        const res = await apiFetch('/api/admin/oidc_config');
        const d = await res.json();
        if (!res.ok) { toast(d.message || __.t('js.network_error'), false); return; }
        _oidc = d;
        renderOidc();
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

// OIDC 卡片默认折叠，点表头展开/收起（与平台接入状态一致）
export function toggleOidcCard(hd) {
    const card = document.getElementById('oidc-card');
    if (!card) return;
    card.classList.toggle('open');
    const caret = hd ? hd.querySelector('.pc-caret') : null;
    if (caret) caret.textContent = card.classList.contains('open') ? '▾' : '▸';
}

export function onOidcAddClient() {
    if (!_oidc) return;
    _oidc.clients = (_oidc.clients || []).concat([{
        client_id: '', system: '', enabled: false, description: '',
        redirect_uri: '', secret_set: false, source: '', _new: true,
    }]);
    renderOidc();
}

// idx 对应当前渲染的 clients 下标；新行直接移除，已持久化行走确认 + 后端 delete。
export async function onOidcClientDelete(idx) {
    if (!_oidc || !_oidc.clients || !_oidc.clients[idx]) return;
    const c = _oidc.clients[idx];
    if (c._new) {
        _oidc.clients.splice(idx, 1);
        renderOidc();
        return;
    }
    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: __.t('oidc.delete_confirm', { id: c.client_id }),
        confirmText: __.t('oidc.delete'),
    })) return;
    try {
        const res = await apiFetch('/api/admin/oidc_config', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ clients: [{ client_id: c.client_id, delete: true }] }),
        });
        const d = await res.json();
        if (res.ok) {
            toast(__.t('oidc.deleted'), true);
            _oidc = d;
            renderOidc();
        } else {
            toast(d.message || __.t('js.network_error'), false);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

// 汇总当前表单为 PUT 请求体。secret 留空不提交该字段（后端据此保留原值），
// 新增行 client_id 为空则跳过。前端先做与后端一致的「启用需 secret+redirect」校验。
function collectPayload() {
    const issuer = document.getElementById('oidc-issuer');
    const ttl = document.getElementById('oidc-ttl');
    const payload = {};
    if (issuer) payload.issuer = issuer.value.trim();
    if (ttl) payload.id_token_ttl = parseInt(ttl.value, 10) || 0;

    const clients = [];
    document.querySelectorAll('#oidc-body .oidc-row').forEach(function(row) {
        const toggle = row.querySelector('.oidc-enabled');
        const idx = toggle ? parseInt(toggle.dataset.idx, 10) : -1;
        const orig = _oidc && _oidc.clients && _oidc.clients[idx] ? _oidc.clients[idx] : null;
        if (!orig) return;
        const cidInput = row.querySelector('.oidc-cid-input');
        const clientId = cidInput ? cidInput.value.trim() : orig.client_id;
        if (!clientId) return;

        const redirect = row.querySelector('.oidc-redirect').value.trim();
        const secret = row.querySelector('.oidc-secret').value.trim();
        const system = row.querySelector('.oidc-system').value.trim();
        const enabled = row.querySelector('.oidc-enabled').checked;

        // 新增行总是提交；既有行只在有改动时提交 —— 避免把未改动的客户端无谓写回 DB
        if (!orig._new) {
            const changed = enabled !== !!orig.enabled
                || redirect !== (orig.redirect_uri || '')
                || system !== (orig.system || '')
                || secret !== '';
            if (!changed) return;
        }

        if (enabled && !secret && !orig.secret_set) {
            throw new Error(__.t('oidc.secret_required', { id: clientId }));
        }
        if (enabled && !redirect) {
            throw new Error(__.t('oidc.redirect_required', { id: clientId }));
        }

        const item = {
            client_id: clientId,
            system: system,
            enabled: enabled,
            redirect_uri: redirect,
            description: orig.description || '',
        };
        if (secret) item.secret = secret; // 留空 = 保持原值，不提交
        clients.push(item);
    });
    payload.clients = clients;
    return payload;
}

export async function saveOidcConfig() {
    let payload;
    try {
        payload = collectPayload();
    } catch (e) {
        toast(e.message, false);
        return;
    }
    try {
        const res = await apiFetch('/api/admin/oidc_config', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await res.json();
        if (res.ok) {
            _oidc = d;
            renderOidc();
            toast(__.t('oidc.saved'), true);
        } else {
            toast(d.message || __.t('js.network_error'), false);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}
