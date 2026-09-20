import { authHeaders, handle401 } from '../core/api.js';
import { esc, escJs, js } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import { hasPermission, getRole, getUserName, isRoot } from '../core/auth.js';

function isAdminRole(role) { return role === 'admin' || role === 'super_admin'; }

function roleLabel(user) {
    if (user.role === 'super_admin') return '👑 ' + __.t('user.role_super_admin');
    return __.t('user.role_' + user.role) || user.role;
}

let _rolesCache = null;
async function loadRoles() {
    if (_rolesCache) return _rolesCache;
    try {
        const res = await fetch('/api/admin/roles', { headers: authHeaders() });
        if (handle401(res)) return [];
        _rolesCache = await res.json();
        return _rolesCache;
    } catch (e) { console.error('loadRoles failed:', e); return []; }
}

export async function loadUsers() {
    document.getElementById('user-msg').textContent = '';
    try {
        const res = await fetch('/api/admin/users', { headers: authHeaders() });
        if (!res.ok && res.status === 403) { alert(__.t('user.cannot_create_admin')); return; }
        if (handle401(res)) return;
        const result = await res.json();
        const allUsers = Array.isArray(result) ? result : (result.data || []);
        const currentUserRole = getRole();
        const users = isAdminRole(currentUserRole) ? allUsers : allUsers.filter(u => !isAdminRole(u.role));
        const tbody = document.getElementById('user-tbody');
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#9ca3af;">' + __.t('user.no_users') + '</td></tr>';
            return;
        }
        const currentUserName = getUserName();
        const currentUserIsRoot = isRoot();
        tbody.innerHTML = users.map(u => {
            const time = u.updated_at || '-';
            const isSuperAdmin = u.role === 'super_admin';
            const isAdmin = u.role === 'admin';
            const isSelf = u.username === currentUserName;
            const isEnabled = Number(u.status === undefined || u.status === null ? 1 : u.status) !== 0;
            const statusBadge = isEnabled
                ? '<span style="color:#16a34a;font-size:12px;">● ' + __.t('common.enabled') + '</span>'
                : '<span style="color:#ef4444;font-size:12px;">● ' + __.t('common.disabled') + '</span>';
            const canToggle = !isSuperAdmin && !isSelf && (currentUserIsRoot || currentUserRole === 'super_admin' || hasPermission('ci.users.manage_admin'));
            const toggleBtn = canToggle
                ? (isEnabled
                    ? `<button class="btn btn-sm btn-del" onclick="toggleUserStatus('${escJs(esc(u.username))}', false)">🚫 ${__.t('user.disable')}</button>`
                    : `<button class="btn btn-sm btn-save" onclick="toggleUserStatus('${escJs(esc(u.username))}', true)">✅ ${__.t('user.enable')}</button>`)
                : '';
            let actions;
            const modPwBtn = (currentUserRole === 'super_admin' && !isSelf)
                ? `<button class="btn btn-sm btn-edit" onclick="modifyUserPassword('${escJs(esc(u.username))}')">🔑 ${__.t('user.modify_password')}</button>`
                : '';
            if (isSuperAdmin) {
                if (isSelf) actions = `<button class="btn btn-sm btn-edit" onclick='showUserEditForm(${js(u)})'>✏️ ${__.t('common.edit')}</button>`;
                else actions = modPwBtn || `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_super_admin')}</span>`;
            } else if (isSelf) {
                actions = `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
            } else if (isAdmin) {
                if (currentUserIsRoot) actions = modPwBtn + `<button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(esc(u.username))}')">🗑 ${__.t('common.delete')}</button>`;
                else actions = modPwBtn || `<span style="color:#9ca3af;font-size:12px;">${__.t('user.role_admin')}</span>`;
            } else {
                actions = modPwBtn + `<button class="btn btn-sm btn-edit" onclick='showUserEditForm(${js(u)})'>✏️ ${__.t('common.edit')}</button>
                    <button class="btn btn-sm btn-del" onclick="deleteUser('${escJs(esc(u.username))}')">🗑 ${__.t('common.delete')}</button>`;
            }
            return `<tr>
                <td><strong>${esc(u.username)}</strong></td>
                <td>${esc(roleLabel(u))}</td>
                <td>${esc(u.systems || '-')}</td>
                <td style="font-size:12px;color:#6b7280;">${esc(u.email || '-')}</td>
                <td style="font-size:12px;color:#6b7280;">${esc(time)}</td>
                <td>${statusBadge}</td>
                <td style="white-space:nowrap">${toggleBtn}${actions}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        document.getElementById('user-tbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444;">' + __.t('js.load_failed') + ': ' + e.message + '</td></tr>';
    }
}

export async function toggleUserStatus(username, enable) {
    const actionLabel = enable ? __.t('user.enable') : __.t('user.disable');
    if (!await confirmDialog({ title: '⚠️ ' + actionLabel, message: username, confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username) + '/status', {
            method: 'PUT',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: enable })
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) { toast(__.t('user.status_updated') + ': ' + username, true); await loadUsers(); }
        else { alert(data.message || __.t('common.failed')); }
    } catch (e) { alert(e.message); }
}

export async function showUserForm() {
    document.getElementById('user-form').style.display = 'block';
    document.getElementById('edit-target-username').value = '';
    document.getElementById('user-form-title').textContent = __.t('user.add_user');
    document.getElementById('new-username-wrap').style.display = 'block';
    document.getElementById('new-username').value = '';
    document.getElementById('new-username').required = true;
    document.getElementById('new-password').value = '';
    document.getElementById('new-password').required = true;
    document.getElementById('new-password').placeholder = __.t('form.placeholder_password');
    document.getElementById('new-password-label').textContent = __.t('user.password');
    try { await populateRoleSelect('deployer'); } catch (e) {
        document.getElementById('user-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('user-msg').style.color = '#ef4444';
        return;
    }
    document.getElementById('new-systems-wrap').style.display = 'block';
    populateSystemsSelect('cd');
    document.getElementById('new-email').value = '';
    document.getElementById('user-msg').textContent = '';
}

export async function showUserEditForm(user) {
    document.getElementById('user-form').style.display = 'block';
    document.getElementById('edit-target-username').value = user.username;
    document.getElementById('user-form-title').textContent = __.t('user.edit_user') + ': ' + user.username;
    document.getElementById('new-username-wrap').style.display = 'none';
    document.getElementById('new-username').value = user.username;
    document.getElementById('new-username').required = false;
    document.getElementById('new-password').value = '';
    document.getElementById('new-password').required = false;
    document.getElementById('new-password').placeholder = __.t('user.password_keep_empty');
    document.getElementById('new-password-label').textContent = __.t('user.new_password_optional');
    try { await populateRoleSelect(user.role); } catch (e) {
        document.getElementById('user-msg').textContent = __.t('js.network_error') + ': ' + (e.message || '');
        document.getElementById('user-msg').style.color = '#ef4444';
        return;
    }
    document.getElementById('new-systems-wrap').style.display = 'none';
    document.getElementById('new-email').value = user.email || '';
    const isRootSelf = (user.username === getUserName()) && isRoot();
    document.getElementById('new-role').disabled = isRootSelf;
    document.getElementById('new-password').disabled = isRootSelf;
    document.getElementById('user-msg').textContent = '';
}

async function populateRoleSelect(selected) {
    const sel = document.getElementById('new-role');
    sel.innerHTML = '';
    const roles = await loadRoles();
    const canManageAdmin = hasPermission('ci.users.manage_admin');
    roles.forEach(function(r) {
        if (r.name === 'super_admin' && !canManageAdmin) return;
        sel.add(new Option(r.description || __.t('user.role_' + r.name) || r.name, r.name));
    });
    const found = Array.from(sel.options).some(o => o.value === selected);
    if (found) sel.value = selected;
    else if (sel.options.length > 0) sel.selectedIndex = 0;
}

function populateSystemsSelect(selected) {
    const sel = document.getElementById('new-systems');
    sel.innerHTML = '';
    sel.add(new Option(__.t('user.systems_cd'), 'cd'));
    if (isAdminRole(getRole())) {
        sel.add(new Option(__.t('user.systems_ci'), 'ci'));
        sel.add(new Option(__.t('user.systems_cd_ci'), 'cd,ci'));
    }
    sel.value = selected;
}

export function hideUserForm() {
    document.getElementById('user-form').style.display = 'none';
    document.getElementById('edit-target-username').value = '';
    document.getElementById('new-password').required = true;
    document.getElementById('new-role').disabled = false;
    document.getElementById('new-password').disabled = false;
}

export async function submitUserForm(e) {
    e.preventDefault();
    const targetUser = document.getElementById('edit-target-username').value;
    const isEdit = !!targetUser;
    const username = document.getElementById('new-username').value.trim();
    const password = document.getElementById('new-password').value;
    const role = document.getElementById('new-role').value;
    const systems = document.getElementById('new-systems').value;
    const email = document.getElementById('new-email').value.trim();
    const msg = document.getElementById('user-msg');

    if (!isEdit && password.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }
    if (!isEdit && !username) { msg.textContent = __.t('js.username_required'); msg.style.color = '#ef4444'; return; }
    if (isEdit && password && password.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#ef4444'; return; }

    const saveBtn = document.querySelector('#user-form button[type="submit"]');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const url = isEdit ? '/api/admin/users/' + encodeURIComponent(targetUser) : '/api/admin/users';
        const isRootSelf = isEdit && (targetUser === getUserName()) && isRoot();
        const body = isEdit
            ? (isRootSelf ? { email } : { ...(password ? { password } : {}), role, email })
            : { username, password, role, systems, email };
        const res = await fetch(url, {
            method: isEdit ? 'PUT' : 'POST',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        if (handle401(res)) { if (saveBtn) saveBtn.disabled = false; return; }
        const data = await res.json();
        if (res.ok) { hideUserForm(); await loadUsers(); toast(isEdit ? __.t('user.updated') : __.t('user.created'), true); }
        else { msg.textContent = data.message || __.t('common.failed'); msg.style.color = '#ef4444'; }
    } catch (e) {
        msg.textContent = e.message;
        msg.style.color = '#ef4444';
    }
    if (saveBtn) saveBtn.disabled = false;
}

export async function deleteUser(username) {
    if (!await confirmDialog({ title: '🗑️ ' + __.t('user.confirm_delete'), message: username, confirmText: __.t('common.confirm') })) return;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username), { method: 'DELETE', headers: authHeaders() });
        if (handle401(res)) return;
        if (res.ok) { await loadUsers(); toast(__.t('user.deleted'), true); }
        else { const data = await res.json(); alert(data.message || __.t('common.failed')); }
    } catch (e) { alert(e.message); }
}

let _pwdTargetUser = '';

export function modifyUserPassword(username) {
    _pwdTargetUser = username;
    document.getElementById('pwd-modal-hint').innerHTML =
        __.t('user.modify_password_hint').replace('{user}', function() { return '<b>' + esc(username) + '</b>'; });
    document.getElementById('pwd-new').value = '';
    document.getElementById('pwd-confirm').value = '';
    document.getElementById('pwd-msg').textContent = '';
    document.getElementById('pwd-modal').style.display = 'flex';
    setTimeout(function() { document.getElementById('pwd-new').focus(); }, 60);
}

export function closePasswordModal() {
    document.getElementById('pwd-modal').style.display = 'none';
    _pwdTargetUser = '';
}

export async function submitPasswordChange(e) {
    if (e) e.preventDefault();
    const username = _pwdTargetUser;
    const newPass = document.getElementById('pwd-new').value;
    const confirmPass = document.getElementById('pwd-confirm').value;
    const msg = document.getElementById('pwd-msg');
    const submitBtn = document.getElementById('pwd-submit');

    msg.textContent = '';
    if (newPass.length < 8) { msg.textContent = __.t('auth.new_password_short'); return; }
    if (newPass !== confirmPass) { msg.textContent = __.t('user.modify_password_mismatch'); return; }

    if (submitBtn) submitBtn.disabled = true;
    try {
        const res = await fetch('/api/admin/users/' + encodeURIComponent(username) + '/password', {
            method: 'PUT',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ new_password: newPass })
        });
        if (handle401(res)) { if (submitBtn) submitBtn.disabled = false; return; }
        if (res.ok) { closePasswordModal(); await loadUsers(); toast(__.t('user.password_updated') + ': ' + username, true); }
        else { const data = await res.json(); msg.textContent = data.message || __.t('common.failed'); }
    } catch (err) { msg.textContent = err.message; }
    if (submitBtn) submitBtn.disabled = false;
}

export async function changePassword(e) {
    e.preventDefault();
    const oldP = document.getElementById('old-pass').value;
    const newP = document.getElementById('new-pass').value;
    const new2 = document.getElementById('new-pass2').value;
    const msg  = document.getElementById('pwd-msg');
    if (newP !== new2) { msg.textContent = __.t('js.password_mismatch'); msg.style.color = '#dc2626'; return; }
    if (newP.length < 8) { msg.textContent = __.t('auth.new_password_short'); msg.style.color = '#dc2626'; return; }
    try {
        const res = await fetch('/api/admin/password', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({old_password: oldP, new_password: newP})
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            msg.textContent = '✅ ' + __.t('auth.password_updated');
            msg.style.color = '#16a34a';
            setTimeout(() => window.doLogout(), 1500);
        } else {
            msg.textContent = data.message || __.t('js.modify_failed');
            msg.style.color = '#dc2626';
        }
    } catch(x) { msg.textContent = __.t('js.network_error'); msg.style.color = '#dc2626'; }
}