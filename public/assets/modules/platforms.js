import { apiFetch } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';

let _pc = null;

const DEFS = [
    { key: 'jenkins', label: '🤖 Jenkins', fields: ['url', 'username', 'token'] },
    { key: 'gitlab',  label: '🦊 GitLab',  fields: ['url', 'token'] },
    { key: 'gitea',   label: '🦎 Gitea',   fields: ['url', 'token'] },
    { key: 'gitee',   label: '🐈 Gitee',   fields: ['url', 'token'] },
    { key: 'github',  label: '🐙 GitHub',  fields: ['url', 'token'] },
    { key: 'harbor',  label: '🐳 Harbor',  fields: ['url', 'username', 'password'] },
];

const NAME_BY_KEY = {};
DEFS.forEach(function (d) { NAME_BY_KEY[d.key] = d.label; });

const FIELD_META = {
    url:      { label: 'platform_conn.url_label', type: 'text', valueKey: 'url' },
    token:    { label: 'platform_conn.token_label', type: 'secret', setKey: 'token_set' },
    username: { label: 'platform_conn.username_label', type: 'text', valueKey: 'username' },
    password: { label: 'platform_conn.password_label', type: 'secret', setKey: 'password_set' },
};

function fieldHtml(p, field) {
    const meta = FIELD_META[field];
    const label = __.t(meta.label);
    if (meta.type === 'secret') {
        const set = !!p[meta.setKey];
        const hint = set
            ? '<span style="color:#16a34a;font-size:11px;">(' + esc(__.t('platform_conn.secret_set')) + ')</span>'
            : '<span style="color:#9ca3af;font-size:11px;">(' + esc(__.t('platform_conn.secret_unset')) + ')</span>';
        return '<label class="pc-lbl">' + esc(label) + ' ' + hint + '</label>'
            + '<input type="password" class="pc-field-' + field + '" value="" placeholder="' + esc(__.t('platform_conn.secret_keep')) + '" autocomplete="new-password">';
    }
    const value = p[meta.valueKey] || '';
    // SaaS 平台的 URL 是固定默认地址，自建平台 URL 为必填
    let labelExtra = '';
    if (field === 'url' && p.hosting === 'saas') {
        labelExtra = ' <span style="color:#9ca3af;font-size:11px;font-weight:400;">(' + esc(__.t('platform_conn.url_fixed_hint')) + ')</span>';
    } else if (field === 'url' && p.hosting === 'self-hosted') {
        labelExtra = ' <span style="color:#dc2626;">*</span>';
    }
    return '<label class="pc-lbl">' + esc(label) + labelExtra + '</label>'
        + '<input type="text" class="pc-field-' + field + '" value="' + esc(value) + '">';
}

function renderPlatforms() {
    const body = document.getElementById('pc-platforms');
    if (!body || !_pc) return;
    const byKey = {};
    (_pc.platforms || []).forEach(function (p) { byKey[p.platform] = p; });

    const rows = DEFS.map(function (def) {
        const p = byKey[def.key] || { platform: def.key, url: '', token_set: false, username: '', password_set: false, configured: false };
        const ok = !!p.configured;
        const dot = ok ? '#16a34a' : '#dc2626';
        const fields = def.fields.map(function (f) { return fieldHtml(p, f); }).join('');
        // 仅对「已配置且有 CI 能力」的平台显示 CI 开关（未配置时 CI 无从启用）
        const ciToggle = (p.ci_capable && ok)
            ? '<div style="margin:6px 0 0 18px;">'
                + '<label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:12px;font-weight:600;color:#374151;white-space:nowrap;">'
                    + '<input type="checkbox" class="pc-field-ci" ' + (p.ci_enabled ? 'checked' : '') + '>'
                    + esc(__.t('platform_conn.ci_enabled'))
                + '</label>'
                + '</div>'
            : '';
        // 托管类型徽标：saas=固定地址、self-hosted=自定义地址（与 CI 能力正交）
        const hostingBadge = (p.hosting === 'saas' || p.hosting === 'self-hosted')
            ? '<span style="margin-left:6px;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:600;'
                + (p.hosting === 'saas' ? 'background:#eef2ff;color:#4f46e5;' : 'background:#ecfdf5;color:#059669;') + '">'
                + esc(__.t(p.hosting === 'saas' ? 'platform_conn.hosting_saas' : 'platform_conn.hosting_self_hosted'))
                + '</span>'
            : '';
        return '<div class="pc-row" data-key="' + esc(def.key) + '">'
            + '<div class="pc-row-hd" onclick="togglePlatformRow(this)">'
                + '<span class="pc-caret">▸</span>'
                + '<span style="width:10px;height:10px;border-radius:50%;background:' + dot + ';flex-shrink:0;display:inline-block;"></span>'
                + '<span class="pc-name">' + esc(def.label) + hostingBadge + '</span>'
                + '<span style="margin-left:auto;color:' + (ok ? '#16a34a' : '#dc2626') + ';font-weight:600;font-size:12px;">'
                    + (ok ? '✓ ' : '✗ ') + (ok ? __.t('sys.configured') : __.t('sys.not_configured'))
                + '</span>'
                + '<button class="btn btn-sm btn-del" onclick="event.stopPropagation();onPlatformDelete(\'' + esc(def.key) + '\')">🗑 ' + esc(__.t('platform_conn.delete')) + '</button>'
            + '</div>'
            + '<div class="pc-body">' + ciToggle + '<div class="pc-grid">' + fields + '</div>' + '</div>'
        + '</div>';
    }).join('');

    body.innerHTML = rows
        + '<div style="display:flex;justify-content:flex-end;margin-top:12px;">'
            + '<button class="btn btn-pri" onclick="savePlatformConnections()">💾 ' + esc(__.t('platform_conn.save')) + '</button>'
        + '</div>';
}

export async function loadPlatformConnections() {
    const body = document.getElementById('pc-platforms');
    if (!body) return;
    try {
        const res = await apiFetch('/api/admin/platform_connections');
        const d = await res.json();
        if (!res.ok) { toast(d.message || __.t('js.network_error'), false); return; }
        _pc = d;
        renderPlatforms();
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

// 汇总当前表单为 PUT 请求体。只提交有改动或新填凭证的平台，避免把 github/gitee 的
// 默认 URL 无谓写回 DB；token/password 留空不提交该字段（后端据此保留原值）。
function collectPayload() {
    const platforms = [];
    document.querySelectorAll('#pc-platforms .pc-row').forEach(function (row) {
        const key = row.dataset.key;
        const orig = (_pc && _pc.platforms || []).find(function (p) { return p.platform === key; }) || {};

        const url = row.querySelector('.pc-field-url').value.trim();
        const usernameEl = row.querySelector('.pc-field-username');
        const username = usernameEl ? usernameEl.value.trim() : '';
        const tokenEl = row.querySelector('.pc-field-token');
        const token = tokenEl ? tokenEl.value.trim() : '';
        const passwordEl = row.querySelector('.pc-field-password');
        const password = passwordEl ? passwordEl.value : '';

        const urlChanged = url !== (orig.url || '');
        const usernameChanged = username !== (orig.username || '');
        const tokenProvided = token !== '';
        const passwordProvided = password.trim() !== '';

        // CI 能力开关（CI-capable 且已配置的平台，含 Jenkins 与 gitlab/gitea）
        const ciEl = row.querySelector('.pc-field-ci');
        const ciChanged = ciEl ? ((ciEl.checked === true) !== (orig.ci_enabled === true)) : false;

        if (!urlChanged && !usernameChanged && !tokenProvided && !passwordProvided && !ciChanged) {
            return; // 无改动，不提交
        }
        if (url === '' && (tokenProvided || passwordProvided)) {
            throw new Error(__.t('platform_conn.url_required', { name: NAME_BY_KEY[key] || key }));
        }

        const item = { platform: key, url: url, username: username };
        if (tokenProvided) item.token = token;
        if (passwordProvided) item.password = password;
        if (ciEl) item.ci_enabled = ciEl.checked;
        platforms.push(item);
    });
    return { platforms: platforms };
}

export async function savePlatformConnections() {
    let payload;
    try {
        payload = collectPayload();
    } catch (e) {
        toast(e.message, false);
        return;
    }
    try {
        const res = await apiFetch('/api/admin/platform_connections', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await res.json();
        if (res.ok) {
            _pc = d;
            renderPlatforms();
            toast(__.t('platform_conn.saved'), true);
        } else {
            toast(d.message || __.t('js.network_error'), false);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

export async function onPlatformDelete(key) {
    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: __.t('platform_conn.delete_confirm', { name: NAME_BY_KEY[key] || key }),
        confirmText: __.t('platform_conn.delete'),
    })) return;
    try {
        const res = await apiFetch('/api/admin/platform_connections', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ platforms: [{ platform: key, delete: true }] }),
        });
        const d = await res.json();
        if (res.ok) {
            _pc = d;
            renderPlatforms();
        } else {
            toast(d.message || __.t('js.network_error'), false);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

// 平台连接卡片默认折叠，点表头展开/收起（删除按钮已 stopPropagation 不触发折叠）
export function togglePlatformRow(hd) {
    const row = hd.closest('.pc-row');
    if (!row) return;
    row.classList.toggle('open');
    const caret = hd.querySelector('.pc-caret');
    if (caret) caret.textContent = row.classList.contains('open') ? '▾' : '▸';
}
