// assets/core/auth.js
import { normalizePerms } from './utils.js';

let token = sessionStorage.getItem('admin_token') || '';
let currentUserRole = sessionStorage.getItem('admin_role') || '';
let currentUserName = sessionStorage.getItem('admin_user') || '';
let currentUserIsRoot = sessionStorage.getItem('admin_is_root') === 'true';
let currentMustChange = sessionStorage.getItem('admin_must_change') === '1';
let currentPermissions = normalizePerms(
    (() => { try { return JSON.parse(sessionStorage.getItem('admin_perms') || '[]'); } catch(e) { return []; } })()
);

export function getToken() { return token; }
export function getRole() { return currentUserRole; }
export function getUserName() { return currentUserName; }
export function isRoot() { return currentUserIsRoot; }
export function getPermissions() { return currentPermissions; }
export function mustChangeRequired() { return currentMustChange; }

export function authHeaders() {
    const headers = {};
    if (token) headers['Authorization'] = 'Bearer ' + token;
    // 界面语言存在 localStorage（i18n.js），浏览器的 Accept-Language 与之无关。
    // 显式带给后端，让 API 错误文案按当前界面语言翻译，而不是永远用默认 zh_CN。
    try {
        if (typeof window !== 'undefined' && window.__) {
            headers['X-DG-Lang'] = window.__.lang === 'en' ? 'en' : 'zh_CN';
        }
    } catch (e) { /* 语言包未就绪时省略，后端回退默认 */ }
    return headers;
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
    currentMustChange = !!(data.must_change_password);
    sessionStorage.setItem('admin_token', token);
    sessionStorage.setItem('admin_must_change', currentMustChange ? '1' : '0');
    sessionStorage.setItem('admin_role', currentUserRole);
    sessionStorage.setItem('admin_user', currentUserName);
    sessionStorage.setItem('admin_is_root', currentUserIsRoot ? 'true' : 'false');
}

export async function refreshPermissions() {
    try {
        const res = await fetch('/api/admin/me/permissions', { headers: authHeaders() });
        if (res.status === 401) { doLogout(); return; }
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
    currentMustChange = false;
    sessionStorage.removeItem('admin_token');
    sessionStorage.removeItem('admin_must_change');
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