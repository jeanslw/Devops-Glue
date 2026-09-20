export function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

export function safeUrl(s) {
    s = String(s == null ? '' : s).trim();
    if (!s) return '';
    if (/^https?:\/\//i.test(s)) return s;
    if (/^\/\//.test(s)) return '';
    if (/^[/#?]/.test(s)) return s;
    if (!/^[a-z][a-z0-9+.-]*:/i.test(s)) return s;
    return '';
}

export function escJs(s) { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }

export function js(obj) { return JSON.stringify(obj).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

export function truncateUrl(url) {
    const cleaned = url.replace(/\.git$/, '').replace(/\/+$/, '');
    const parts = cleaned.split('/');
    const last = parts.pop() || '';
    const second = parts.pop() || '';
    if (second) return second + '/' + last;
    return last || url;
}

export function encodePath(p) {
    return String(p).split('/').map(encodeURIComponent).join('/');
}

export function fmtYmd(ts) {
    const d = new Date(ts * 1000);
    const p = n => (n < 10 ? '0' : '') + n;
    return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate());
}

export function normalizePerms(raw) {
    if (Array.isArray(raw)) return raw;
    if (raw === '*') return ['*'];
    return [];
}