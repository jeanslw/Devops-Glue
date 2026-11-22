import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * 回归：admin.js 用 `Object.assign(window, {...})` 把模块函数挂到全局，
 * 供 HTML 的 inline onclick 调用。若某个标识符只在挂载清单里、却漏了 import
 * 或本文件定义，ESM 模块在运行时抛 ReferenceError 并整体中断——登录按钮
 * 的 doLogin 等全部失效，页面表现为「登录不了」。node --check 查不出这类
 * 错误（语法合法），所以必须静态核对。
 */
test('admin.js window 挂载的所有标识符都已 import 或在本文件定义', () => {
    const src = fs.readFileSync(path.join(ROOT, 'public/assets/admin.js'), 'utf8');

    const imported = new Set();
    const importRe = /import\s*\{([\s\S]*?)\}\s*from\s*['"][^'"]+['"]/g;
    let m;
    while ((m = importRe.exec(src)) !== null) {
        m[1].split(',').map(s => s.trim()).filter(Boolean).forEach(n => imported.add(n));
    }
    const declared = new Set();
    for (const re of [/function\s+([A-Za-z_$][\w$]*)/g, /(?:const|let|var|class)\s+([A-Za-z_$][\w$]*)/g]) {
        let d;
        while ((d = re.exec(src)) !== null) declared.add(d[1]);
    }

    const mountBlock = (src.match(/Object\.assign\(window,\s*\{([\s\S]*?)\}\);/) || [])[1];
    assert.ok(mountBlock, 'admin.js 应包含 Object.assign(window, {...}) 挂载块');
    const mounted = mountBlock.split('\n').flatMap(line => {
        const code = line.replace(/\/\/.*/, '');
        return code.split(',').map(s => s.trim().replace(/,$/, '')).filter(s => /^[A-Za-z_$][\w$]*$/.test(s));
    });
    assert.ok(mounted.length > 50, '挂载清单不应异常缩小（解析失败？）');

    const missing = mounted.filter(n => !imported.has(n) && !declared.has(n));
    assert.deepEqual(missing, [], '以下标识符被挂到 window 但未定义，会导致 admin.js 运行时中断');
});

/**
 * 回归：队列统计徽标用字符串拼接生成 inline onclick。之前把结尾写成
 * `\\')"`（单引号开头、双引号结尾）生成 `onclick="fn('x")"`，浏览器解析该
 * 属性时抛 SyntaxError，点击徽标无效。直接模拟拼接并求值，捕获引号错误。
 */
/**
 * 回归：队列面板只在 i18n-changed 回调里加载过，doSwitch('system-info') 漏了，
 * 导致切到数据管理页时队列永远显示默认的「队列为空」（即使 API 有数据）。
 */
test('system-info tab 切换时会加载回写队列', () => {
    const src = fs.readFileSync(path.join(ROOT, 'public/assets/admin.js'), 'utf8');
    const line = src.split('\n').find(l => /if \(name === 'system-info'\)/.test(l));
    assert.ok(line, '应能找到 doSwitch 的 system-info 分支');
    assert.match(line, /loadWritebackQueue\(/, '切到数据管理 tab 必须调用 loadWritebackQueue');
});

test('队列徽标 inline onclick 拼接出的 JS 可被解析', () => {
    const line = fs.readFileSync(path.join(ROOT, 'public/assets/modules/system.js'), 'utf8')
        .split('\n').find(l => l.includes("onclick=\"filterWritebackStatus"));
    assert.ok(line, '应能找到徽标 onclick 拼接行');
    // 安全提取：该行是 `return '...onclick="filterWritebackStatus(\'' + k + '\')"....'`
    // 把 k 当作 'pending'，按其 JS 字符串字面量求值出这段字符串
    const expr = line.trim().replace(/^return\s*/, '').replace(/\+ k \+/g, "+ 'pending' +");
    // eslint-disable-next-line no-new-func
    const html = new Function('return ' + expr)();
    const attrMatch = html.match(/onclick="([^"]*)"/);
    assert.ok(attrMatch, '生成的 onclick 属性值引号必须成对（否则浏览器 SyntaxError）: ' + html);
    const js = attrMatch[1];
    assert.doesNotThrow(() => new Function(js), 'onclick 的 JS 体应可被解析: ' + js);
    assert.equal(js, "filterWritebackStatus('pending')", 'onclick 应形如 filterWritebackStatus(\'pending\')');
});
