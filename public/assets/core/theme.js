// assets/core/theme.js
export function applyTheme() {
    const saved = localStorage.getItem('theme') || 'light';
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