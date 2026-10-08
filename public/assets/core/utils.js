export function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/**
 * 归一化 URL，得到「浏览器最终会看到的形式」，再据此判协议。
 *
 * 不做这一步会被两类伪装绕过（都是把危险协议伪装成「没有协议」的相对路径）：
 *   1. 制表/换行：WHATWG URL 解析会先剔除所有 ASCII tab/CR/LF 再判协议，
 *      于是 "java\nscript:alert(1)" 匹配不到「有协议」分支而被当相对路径放行，点击即执行；
 *   2. HTML 字符引用：属性值里的 &#58; / &#x3a; / &colon; / &NewLine; / &Tab; 会被
 *      HTML 解析器还原，"javascript&#58;alert(1)" 同理绕开协议判定。
 * 合法的 URL 来自 JSON（不是 HTML），既不含裸换行也不含字符引用，
 * 因此这里的还原对正常值是无操作，只吃掉伪装写法。
 */
function normalizeUrlForSchemeCheck(s) {
    return String(s)
        // 数字/十六进制字符引用（&#58; &#x3a; &#058; &#x03a; …）。非法码点保持原样，不抛异常。
        .replace(/&#x([0-9a-f]+);?/gi, (m, h) => {
            const n = parseInt(h, 16);
            return n >= 0 && n <= 0x10ffff ? String.fromCodePoint(n) : m;
        })
        .replace(/&#(\d+);?/g, (m, d) => {
            const n = parseInt(d, 10);
            return n >= 0 && n <= 0x10ffff ? String.fromCodePoint(n) : m;
        })
        // 命名引用里能用于伪装的几个（HTML 规范里 &Tab;=U+0009、&NewLine;=U+000A）
        .replace(/&(colon|tab|newline);?/gi, (_, n) => {
            const k = n.toLowerCase();
            return k === 'colon' ? ':' : (k === 'tab' ? '\t' : '\n');
        })
        // 与浏览器一致：判协议前剔除所有 ASCII tab/CR/LF
        .replace(/[\t\n\r]/g, '')
        .trim();
}

/**
 * 把外部来的 URL 收敛成可安全放进 href/src 的形式（不安全则返回 ''）。
 *
 * 返回的是归一化后的值（浏览器真正会解释的那个），避免「校验的值」与
 * 「渲染后浏览器看到的值」不一致。
 */
export function safeUrl(s) {
    const probe = normalizeUrlForSchemeCheck(s == null ? '' : s);
    // 剩下的控制字符正常 URL 不含，且是协议伪装的载体，一律拒绝
    if (/[\u0000-\u001f\u007f]/.test(probe)) return '';
    if (!probe) return '';
    if (/^https?:\/\//i.test(probe)) return probe;
    if (/^\/\//.test(probe)) return '';
    if (/^[/#?]/.test(probe)) return probe;
    if (!/^[a-z][a-z0-9+.-]*:/i.test(probe)) return probe;
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