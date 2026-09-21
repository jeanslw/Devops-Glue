// assets/core/base.js — 后台入口路径推算（支持部署在子路径下）。
// 约定：后台路由固定以 .../admin 结尾；若非 admin 页（宣传页）则视为站点根目录。
// 注意：项目其余静态路径（/assets、/logo.png、/api 等）目前仍是硬编码绝对根路径，
// 如需整套子路径支持，应统一改造所有绝对 URL。
export function adminRootPath() {
    const p = location.pathname.replace(/\/+$/, '');
    const m = p.match(/^(.*)\/admin$/);
    if (m) return (m[1] || '') + '/admin'; // 后台页 -> /admin 或 /{prefix}/admin
    return (p || '') + '/';                // 宣传页/根 -> / 或 /{prefix}/（p 已去尾斜杠，根时 p='' -> /）
}