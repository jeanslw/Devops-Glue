// assets/core/auth.js
import { normalizePerms } from './utils.js';

let token = sessionStorage.getItem('admin_token') || '';
let currentUserRole = sessionStorage.getItem('admin_role') || '';
let currentUserName = sessionStorage.getItem('admin_user') || '';
let currentUserIsRoot = sessionStorage.getItem('admin_is_root') === 'true';
let currentPermissions = normalizePerms(
    (() => { try { return JSON.parse(sessionStorage.getItem('admin_perms') || '[]'); } catch(e) { return []; } })()
);

export function getToken() { return token; }
export function getRole() { return currentUserRole; }
export function getUserName() { return currentUserName; }
export function isRoot() { return currentUserIsRoot; }
export function getPermissions() { return currentPermissions; }

export function authHeaders() {
    return token ? { 'Authorization': 'Bearer ' + token } : {};
}

export function hasPermission(permKey) {
    if (Array.isArray(currentPermissions) && currentPermissions[0] === '*') return true;
    if (!Array.isArray(currentPermissions)) return false;
    return currentPermissions.includes(permKey);
}

export function setSession(data) {
    token = data.token || token;
    currentUserRole = data.role || '';
    currentUserName = data.user || '';
    currentUserIsRoot = data.is_root === true;
    sessionStorage.setItem('admin_token', token);
    sessionStorage.setItem('admin_role', currentUserRole);
    sessionStorage.setItem('admin_user', currentUserName);
    sessionStorage.setItem('admin_is_root', currentUserIsRoot ? 'true' : 'false');
}

export async function refreshPermissions() {
    try {
        const res = await fetch('/api/admin/me/permissions', { headers: authHeaders() });
        if (res.ok) {
            const data = await res.json();
            currentPermissions = normalizePerms(data.permissions);
            sessionStorage.setItem('admin_perms', JSON.stringify(currentPermissions));
        }
    } catch (e) {}
}

export function clearSession() {
    token = '';
    currentUserRole = '';
    currentUserName = '';
    currentUserIsRoot = false;
    currentPermissions = [];
    sessionStorage.removeItem('admin_token');
    sessionStorage.removeItem('admin_role');
    sessionStorage.removeItem('admin_user');
    sessionStorage.removeItem('admin_is_root');
    sessionStorage.removeItem('admin_perms');
}

export function doLogout() {
    if (token) {
        fetch('/api/admin/logout', { method: 'POST', headers: authHeaders() }).catch(() => {});
    }
    clearSession();
    const mu = document.getElementById('top-user-menu');
    if (mu) mu.style.display = 'none';
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-page').style.display = 'none';
}

export function setTopUser() {
    const wrap = document.getElementById('top-user-wrap');
    if (wrap) wrap.style.display = currentUserName ? 'inline-block' : 'none';
    const nameEl = document.getElementById('top-user-name');
    if (nameEl) nameEl.textContent = currentUserName || '';
}

export function toggleTopMenu() {
    const m = document.getElementById('top-user-menu');
    if (!m) return;
    m.style.display = (m.style.display === 'block') ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('top-user-wrap');
    if (!wrap || wrap.contains(e.target)) return;
    const m = document.getElementById('top-user-menu');
    if (m) m.style.display = 'none';
});