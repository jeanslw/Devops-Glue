import { authHeaders, handle401 } from '../core/api.js';
import { esc, escJs, js } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';

let _allPerms = null;
let _permDescMap = {};
let IMPLIED_PERMISSIONS = {};

export function resetPermsCache() { _allPerms = null; }

async function loadAllPerms() {
    if (_allPerms) return _allPerms;
    try {
        const res = await fetch('/api/admin/permissions', { headers: authHeaders() });
        if (handle401(res)) return [];
        const data = await res.json();
        if (Array.isArray(data)) _allPerms = data;
        else {
            _allPerms = data.permissions || [];
            IMPLIED_PERMISSIONS = data.implied || {};
        }
        _permDescMap = {};
        _allPerms.forEach(function(p) { _permDescMap[p.perm_key] = p.description || ''; });
        return _allPerms;
    } catch (e) { return []; }
}

function permLabel(key) {
    const tkey = 'role.perm_' + key.replace(/\./g, '_');
    const translated = __.t(tkey);
    if (translated && translated !== tkey) return translated;
    return _permDescMap[key] || key;
}

function cascadeImpliedCheck(sourceKey, checked, rootContainer) {
    const targets = IMPLIED_PERMISSIONS[sourceKey];
    if (!targets) return;
    targets.forEach(function(targetKey) {
        if (!checked) {
            const hasOtherSource = Object.keys(IMPLIED_PERMISSIONS).some(function(otherKey) {
                if (otherKey === sourceKey) return false;
                if (IMPLIED_PERMISSIONS[otherKey].indexOf(targetKey) < 0) return false;
                const otherCb = rootContainer.querySelector('input[value="' + otherKey + '"]');
                return otherCb && otherCb.checked;
            });
            if (hasOtherSource) return;
        }
        const targetCb = rootContainer.querySelector('input[value="' + targetKey + '"]');
        if (!targetCb) return;
        targetCb.checked = checked;
        const label = targetCb.closest('.perm-check');
        if (label) label.classList.toggle('checked', checked);
    });
}

function groupPermissions(allPerms) {
    const ciTop = [], ciChildMap = {}, cdTop = [], cdChildMap = {};
    allPerms.forEach(function(p) {
        const k = p.perm_key;
        if (k.indexOf('ci.') === 0) {
            if (p.parent_key) { if (!ciChildMap[p.parent_key]) ciChildMap[p.parent_key] = []; ciChildMap[p.parent_key].push(k); }
            else ciTop.push(k);
        } else if (k.indexOf('cd.') === 0) {
            if (p.parent_key) { if (!cdChildMap[p.parent_key]) cdChildMap[p.parent_key] = []; cdChildMap[p.parent_key].push(k); }
            else cdTop.push(k);
        }
    });
    const ciSubs = [];
    for (const pk in ciChildMap) ciSubs.push({ id: pk, label: permLabel(pk), perms: ciChildMap[pk] });
    const cdSubs = [];
    for (const pk in cdChildMap) cdSubs.push({ id: pk, label: permLabel(pk), perms: cdChildMap[pk] });
    return [
        { id: 'ci', label: __.t('role.perm_ci_group'), perms: ciTop, subGroups: ciSubs },
        { id: 'cd', label: __.t('role.perm_cd_group'), perms: cdTop, subGroups: cdSubs }
    ];
}

function getAllCiKeys(allPerms) { return allPerms.filter(p => p.perm_key.indexOf('ci.') === 0).map(p => p.perm_key); }
function getAllCdKeys(allPerms) { return allPerms.filter(p => p.perm_key.indexOf('cd.') === 0).map(p => p.perm_key); }

function permTags(allPerms, userPerms) {
    let html = '';
    allPerms.forEach(function(p) {
        const has = userPerms.indexOf(p) >= 0;
        html += '<span class="perm-tag' + (has ? ' on' : '') + '">' + esc(permLabel(p)) + '</span>';
    });
    return '<div class="perm-list">' + html + '</div>';
}

export function togglePermGroup(containerId, btn) {
    const container = document.getElementById(containerId);
    const rootContainer = document.getElementById('perm-groups');
    const cbs = container.querySelectorAll('input[type="checkbox"]');
    const allChecked = Array.from(cbs).every(cb => cb.checked);
    const target = !allChecked;
    cbs.forEach(function(cb) {
        cb.checked = target;
        cascadeImpliedCheck(cb.value, target, rootContainer || container);
    });
    container.querySelectorAll('.perm-check').forEach(el => el.classList.toggle('checked', target));
    if (rootContainer) {
        rootContainer.querySelectorAll('.perm-check input').forEach(function(cb) {
            const label = cb.closest('.perm-check');
            if (label) label.classList.toggle('checked', cb.checked);
        });
    }
    btn.textContent = target ? __.t('role.deselect_all') : __.t('role.select_all');
}

export async function loadRoleList() {
    const tbody = document.getElementById('role-tbody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">' + __.t('common.loading') + '</td></tr>';
    try {
        const [rolesRes, perms] = await Promise.all([
            fetch('/api/admin/roles', { headers: authHeaders() }),
            loadAllPerms()
        ]);
        if (handle401(rolesRes)) return;
        const roles = await rolesRes.json();
        if (!roles || roles.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">' + __.t('role.no_roles') + '</td></tr>';
            return;
        }
        const ciKeys = getAllCiKeys(perms);
        const cdKeys = getAllCdKeys(perms);
        let html = '';
        roles.forEach(function(r) {
            const rPerms = r.permissions || [];
            const typeBadge = r.is_system
                ? '<span class="badge badge-sys">' + __.t('role.system_role') + '</span>'
                : '<span class="badge badge-cus">' + __.t('role.custom_role') + '</span>';
            let actions;
            if (r.is_system) {
                actions = '<span style="color:#9ca3af;font-size:12px;" title="' + __.t('role.sys_cannot_edit') + '">' + __.t('role.locked') + '</span>';
            } else {
                actions = '<button class="btn btn-sm btn-edit" onclick="showRoleForm(' + r.id + ',\'' + escJs(esc(r.name)) + '\',\'' + escJs(esc(r.description||'')) + '\',' + js(rPerms) + ')" data-i18n-title="map.edit" title="编辑">✏️</button> '
                    + '<button class="btn btn-sm btn-del" onclick="deleteRole(' + r.id + ',\'' + escJs(esc(r.name)) + '\')" data-i18n-title="map.delete" title="删除">🗑</button>';
            }
            const roleKey = 'user.role_' + r.name;
            const i18nName = __.t(roleKey);
            const displayName = (i18nName && i18nName !== roleKey) ? i18nName : (r.description || r.name);
            html += '<tr>'
                + '<td class="mono">' + esc(r.name) + '</td>'
                + '<td>' + esc(displayName) + '</td>'
                + '<td>' + typeBadge + '</td>'
                + '<td class="perm-col-ci">' + permTags(ciKeys, rPerms) + '</td>'
                + '<td class="perm-col-cd">' + permTags(cdKeys, rPerms) + '</td>'
                + '<td>' + actions + '</td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#ef4444;">' + __.t('js.network_error') + '</td></tr>';
    }
}

export async function showRoleForm(id, name, desc, perms) {
    const isEdit = !!id;
    document.getElementById('role-form').style.display = 'block';
    document.getElementById('edit-role-id').value = id || '';
    document.getElementById('role-name').value = name || '';
    document.getElementById('role-name').disabled = isEdit;
    document.getElementById('role-desc').value = desc || '';
    document.getElementById('role-form-title').textContent = isEdit ? __.t('role.edit_role') + ': ' + name : __.t('role.add_role');
    document.getElementById('role-msg').textContent = '';

    const selected = perms || [];
    let allPerms;
    try { allPerms = await loadAllPerms(); } catch (e) {
        document.getElementById('role-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('role-msg').style.color = '#ef4444';
        return;
    }
    const groups = groupPermissions(allPerms);
    const container = document.getElementById('perm-groups');
    let html = '';
    groups.forEach(function(g) {
        html += '<div class="perm-sect">';
        html += '<div class="perm-sect-hd"><span>' + esc(g.label) + '</span><a href="javascript:void(0)" onclick="togglePermGroup(\'' + g.id + '-perms\',this)" data-i18n="role.select_all">' + __.t('role.select_all') + '</a></div>';
        html += '<div class="perm-sect-body" id="' + g.id + '-perms">';
        g.perms.forEach(function(key) {
            const chk = selected.indexOf(key) >= 0;
            html += '<label class="perm-check' + (chk ? ' checked' : '') + '">'
                + '<input type="checkbox" value="' + esc(key) + '"' + (chk ? ' checked' : '') + '>'
                + esc(permLabel(key)) + '</label>';
        });
        g.subGroups.forEach(function(sg) {
            html += '<div class="perm-sub">';
            html += '<div class="perm-sub-hd">▸ ' + esc(sg.label) + '</div>';
            html += '<div class="perm-sub-body">';
            sg.perms.forEach(function(key) {
                const chk = selected.indexOf(key) >= 0;
                html += '<label class="perm-check perm-check-sub' + (chk ? ' checked' : '') + '">'
                    + '<input type="checkbox" value="' + esc(key) + '"' + (chk ? ' checked' : '') + '>'
                    + esc(permLabel(key)) + '</label>';
            });
            html += '</div></div>';
        });
        html += '</div></div>';
    });
    container.innerHTML = html;

    container.querySelectorAll('.perm-check').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            e.preventDefault();
            const cb = this.querySelector('input');
            cb.checked = !cb.checked;
            this.classList.toggle('checked', cb.checked);
            cascadeImpliedCheck(cb.value, cb.checked, container);
        });
    });
    container.querySelectorAll('.perm-check input').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const label = this.closest('.perm-check');
            if (label) label.classList.toggle('checked', this.checked);
            cascadeImpliedCheck(this.value, this.checked, container);
        });
    });
}

export function hideRoleForm() {
    document.getElementById('role-form').style.display = 'none';
    document.getElementById('edit-role-id').value = '';
    document.getElementById('role-name').disabled = false;
    document.getElementById('role-msg').textContent = '';
}

export async function submitRoleForm(e) {
    e.preventDefault();
    const id = document.getElementById('edit-role-id').value;
    const isEdit = !!id;
    const name = document.getElementById('role-name').value.trim();
    const desc = document.getElementById('role-desc').value.trim();
    const cbs = document.querySelectorAll('#perm-groups input:checked');
    const perms = Array.from(cbs).map(cb => cb.value);
    const msg = document.getElementById('role-msg');

    if (!name) { msg.textContent = __.t('role.name_required'); msg.style.color = '#ef4444'; return; }

    const saveBtn = document.querySelector('#role-form button[type="submit"]');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const url = isEdit ? '/api/admin/roles/' + id : '/api/admin/roles';
        const method = isEdit ? 'PUT' : 'POST';
        const body = { name: name, permissions: perms };
        if (desc) body.description = desc;
        const res = await fetch(url, { method, headers: Object.assign({}, authHeaders(), {'Content-Type':'application/json'}), body: JSON.stringify(body) });
        if (handle401(res)) { if (saveBtn) saveBtn.disabled = false; return; }
        const data = await res.json();
        if (res.ok) {
            hideRoleForm();
            await loadRoleList();
            toast(data.message || __.t('common.success'), true);
        } else {
            msg.textContent = data.message || __.t('js.operation_failed');
            msg.style.color = '#ef4444';
        }
    } catch (e) {
        msg.textContent = __.t('js.network_error') + ': ' + e.message;
        msg.style.color = '#ef4444';
    }
    if (saveBtn) saveBtn.disabled = false;
}

export async function deleteRole(id, name) {
    if (!await confirmDialog({ title: '🗑️ ' + __.t('role.delete_confirm'), message: name || '', note: __.t('role.delete_confirm'), confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/roles/' + id, { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        if (res.ok) {
            await loadRoleList();
            toast(__.t('role.deleted'), true);
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.operation_failed'), false);
        }
    } catch (e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}