export function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/**
 * 归一化 URL，得到浏览器最终会看到的形式，再据此判断协议。
 *
 * 不做这一步会被两类伪装绕过（都是把危险协议伪装成没有协议的相对路径）：
 *   1. 制表符与换行：WHATWG URL 解析会先剔除所有 ASCII tab/CR/LF 再判协议，
 *      于是 "java\nscript:alert(1)" 匹配不到有协议分支而被当相对路径放行，点击即执行；
 *   2. HTML 字符引用：属性值里的 &#58; / &#x3a; / &colon; / &NewLine; / &Tab; 会被
 *      HTML 解析器还原，"javascript&#58;alert(1)" 同理绕开协议判定。
 * 合法的 URL 来自 JSON（不是 HTML），既不含裸换行也不含字符引用，
 * 因此这里的还原对正常值没有影响，只处理这类伪装写法。
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
 * 把外部来的 URL 处理成可安全放进 href/src 的形式（不安全则返回 ''）。
 *
 * 返回的是归一化后的值（浏览器真正会解释的那个），避免校验的值与
 * 渲染后浏览器看到的值不一致。
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

/**
 * 把存储为 UTC 的 'Y-m-d H:i:s'（无时区标记）按指定/全局时区渲染为本地可读串。
 * - 数据库时间统一以 UTC 存储，这里补 'Z' 让 JS 严格按 UTC 解析，避免被当本地时。
 * - tz 优先级：显式参数 > window.DG_TZ（后台下发的 display_timezone）> 浏览器本地时区。
 * 注：存量历史数据仍是部署时本地时、未迁移；统一按 UTC→TZ 渲染时，部署环境≠UTC 的旧数据会差一个偏移。
 */
export function fmtUtc(dt, tz) {
    if (!dt) return '-';
    let iso = String(dt).replace(' ', 'T');
    if (iso.length === 19) iso = iso + 'Z';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(dt);
    const useTz = tz || window.DG_TZ || undefined;
    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: useTz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        }).format(d);
    } catch (e) {
        return String(dt);
    }
}

export function normalizePerms(raw) {
    if (Array.isArray(raw)) return raw;
    if (raw === '*') return ['*'];
    return [];
}

/**
 * 归一化日志筛选日期：YYYY-MM-DD / YYYY/MM/DD → YYYY-MM-DD。
 * 返回 '' 表示未填（不加该筛选条件），null 表示格式非法或日期不存在（如 2026-02-30）。
 *
 * 日期输入框是文本框而非 <input type="date">：后者的占位格式由浏览器/系统语言决定，
 * 英文界面下中文浏览器仍会显示「日」。改用文本框后必须由前端把关，因为后端会把
 * date_from/date_to 直接拼 ' 00:00:00' / ' 23:59:59' 参与 SQL 比较，脏值会静默查错。
 */
export function normalizeDateFilter(raw) {
    const s = String(raw ?? '').trim();
    if (s === '') return '';
    const m = /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/.exec(s);
    if (!m) return null;
    const y = Number(m[1]);
    const mo = Number(m[2]);
    const d = Number(m[3]);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    const dt = new Date(Date.UTC(y, mo - 1, d));
    if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) return null;
    const p = n => (n < 10 ? '0' : '') + n;
    return y + '-' + p(mo) + '-' + p(d);
}

/**
 * 构建模式流程图里「镜像仓库」节点的展示元数据。
 * 仅 'registry'（标准 OCI Distribution）走 Registry 分支，其余值/字段缺失一律按 Harbor 兜底，
 * 避免后端未返回 registry_type 时流程图变形。返回 labelKey 而非文案，保持本模块无 i18n/DOM 依赖。
 */
export function registryNodeMeta(registryType) {
    return registryType === 'registry'
        ? { icon: '📦', cls: 'registry', labelKey: 'js.mode_registry_name' }
        : { icon: '🐳', cls: 'harbor', labelKey: 'js.mode_harbor_name' };
}