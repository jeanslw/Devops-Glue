// assets/breadcrumb.js
import { ROUTES } from './core/router.js';
import { esc } from './core/utils.js';
import { adminRootPath } from './core/base.js';

function buildTrail(tab) {
    const trail = [];
    let cur = tab;
    while (cur) {
        const node = ROUTES[cur];
        if (!node) break;
        trail.unshift({ key: cur, ...node });
        cur = node.parent;
    }
    return trail;
}

export function renderBreadcrumb(tab) {
    const el = document.getElementById('breadcrumb');
    if (!el) return;

    const trail = buildTrail(tab);
    const parts = [];

    // 首页指向后台入口（adminRootPath：根域 -> /admin，子路径 -> /{prefix}/admin），避免 / 落到宣传页。
    parts.push(`<a href="${adminRootPath()}" class="bc-item bc-home">🏠 ${__.t('admin.home')}</a>`);

    trail.forEach((node, i) => {
        const isLast = i === trail.length - 1;
        const label = __.t(node.title) || node.key;
        // 分组节点（users-group / perms-group / build-group / settings-group）没有对应的 #tab-* 页面，
        // 点击会落到 doSwitch('<分组>') 导致所有 tab 隐藏 -> 空白页，因此一律渲染为静态文本。
        const isGroup = /-group$/.test(node.key);
        if (isLast) {
            parts.push(`<span class="bc-item bc-current">${node.icon || ''} ${esc(label)}</span>`);
        } else if (isGroup) {
            parts.push(`<span class="bc-item">${node.icon || ''} ${esc(label)}</span>`);
        } else {
            parts.push(`<a href="#/${node.key}" class="bc-item">${node.icon || ''} ${esc(label)}</a>`);
        }
    });

    el.innerHTML = parts.join('<span class="bc-sep">/</span>');
}