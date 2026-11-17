/* eslint-disable curly, prefer-arrow-callback, prefer-template, camelcase, no-extra-semi, comma-dangle, keyword-spacing, no-useless-concat */
// assets/core/api.js
import { authHeaders, doLogout } from './auth.js';

// 关键：把 authHeaders 重新导出，让 modules/ 里可以
// import { authHeaders, handle401 } from '../core/api.js'
export { authHeaders };

export function handle401(res) {
    if (res.status === 401) { doLogout(); return true; }
    return false;
}

export async function apiFetch(url, opts = {}) {
    const res = await fetch(url, {
        ...opts,
        headers: { ...authHeaders(), ...(opts.headers || {}) },
    });
    if (handle401(res)) throw new Error('unauthorized');
    return res;
}