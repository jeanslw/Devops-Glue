/* eslint-disable curly, no-extra-semi, keyword-spacing */
// assets/core/theme.js
export function applyTheme() {
    // 未登录（展示登录页）时强制浅色：登录框不应跟随后台的暗色主题。
    // 与 admin.html head 里的同步脚本同口径；theme.js 在模块加载时也会调用
    // applyTheme()，若不判断登录态会把 head 设好的 light 又改回 dark。
    const loggedIn = !!(window.sessionStorage && sessionStorage.getItem('admin_token'));
    const saved = loggedIn ? (localStorage.getItem('theme') || 'light') : 'light';
    document.documentElement.setAttribute('data-theme', saved);
    const el = document.getElementById('theme-toggle');
    if (el) el.textContent = saved === 'dark' ? '☀️' : '🌙';
}

export function toggleTheme() {
    const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    const el = document.getElementById('theme-toggle');
    if (el) el.textContent = next === 'dark' ? '☀️' : '🌙';
}

applyTheme();