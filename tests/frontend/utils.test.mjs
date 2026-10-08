/**
 * public/assets/core/utils.js 的单元测试（Node 内置 node:test，零依赖）。
 *
 * 这些是纯函数，不依赖 DOM / sessionStorage / fetch，可在 Node 直接 import。
 * 高危关注点：safeUrl 的协议伪装绕过、esc/escJs/js 的注入封口。
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    esc,
    safeUrl,
    escJs,
    js,
    truncateUrl,
    encodePath,
    fmtYmd,
    normalizePerms,
} from '../../public/assets/core/utils.js';

test('esc 转义 HTML 关键字符', () => {
    assert.equal(esc('<script>alert("x")</script>'), '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
    assert.equal(esc('a&b'), 'a&amp;b');
    assert.equal(esc(''), '');
    assert.equal(esc(null), '');
    assert.equal(esc(0), ''); // 0 是 falsy，走 !s 分支返回空串
    assert.equal(esc(123), '123');
    // ⚠️ esc 故意不转义单引号：onclick 里的组合是 escJs(esc(x))，esc 负责 HTML 属性
    //    （双引号）这一层，单引号由 escJs 负责。若这里改成 &#39;，escJs 会以为引号已处理，
    //    浏览器解码实体后反而逃出 JS 字符串字面量。勿"顺手修复"。
    assert.equal(esc(`a'b`), `a'b`);
});

test('safeUrl 放行 http/https/相对路径，拦 javascript: 等危险协议', () => {
    // 放行
    assert.equal(safeUrl('https://example.com/a'), 'https://example.com/a');
    assert.equal(safeUrl('http://example.com'), 'http://example.com');
    assert.equal(safeUrl('/rel/path'), '/rel/path');
    assert.equal(safeUrl('#anchor'), '#anchor');
    assert.equal(safeUrl('?query=1'), '?query=1');
    assert.equal(safeUrl('foo/bar.git'), 'foo/bar.git');
    // 拒绝：危险协议
    assert.equal(safeUrl('javascript:alert(1)'), '');
    assert.equal(safeUrl('JavaScript:alert(1)'), '');
    assert.equal(safeUrl('vbscript:x'), '');
    assert.equal(safeUrl('data:text/html,<script>alert(1)</script>'), '');
    assert.equal(safeUrl('//evil.com'), '');
});

test('safeUrl 挡掉制表/换行伪装的 javascript: 协议', () => {
    // WHATWG URL 判协议前会剔除 ASCII tab/CR/LF，旧的纯正则会漏判
    assert.equal(safeUrl('java\nscript:alert(1)'), '');
    assert.equal(safeUrl('java\tscript:alert(1)'), '');
    assert.equal(safeUrl('java\rscript:alert(1)'), '');
    assert.equal(safeUrl('\tjavascript:alert(1)'), '');
});

test('safeUrl 挡掉 HTML 字符引用伪装的 javascript: 协议', () => {
    // HTML 解析器会把 &#58; / &#x3a; / &colon; 还原成 ':'，旧的纯正则会漏判
    assert.equal(safeUrl('javascript&#58;alert(1)'), '');
    assert.equal(safeUrl('javascript&#x3a;alert(1)'), '');
    assert.equal(safeUrl('javascript&#X3A;alert(1)'), '');
    assert.equal(safeUrl('javascript&colon;alert(1)'), '');
    assert.equal(safeUrl('javascript&#058;alert(1)'), '');
    assert.equal(safeUrl('javascript&#x0003a;alert(1)'), '');
});

test('safeUrl 挡掉 NUL 等控制字符', () => {
    assert.equal(safeUrl('java\u0000script:alert(1)'), '');
    assert.equal(safeUrl('http\u0000://evil.com'), '');
});

test('safeUrl 对空值与空串返回空串', () => {
    assert.equal(safeUrl(''), '');
    assert.equal(safeUrl(null), '');
    assert.equal(safeUrl(undefined), '');
    assert.equal(safeUrl('   '), '');
});

test('escJs 封口引号与反斜杠，防 onclick 属性逃逸', () => {
    assert.equal(escJs(`a'b"c`), "a\\'b\\\"c");
    assert.equal(escJs('x\\y'), 'x\\\\y');
});

test('js 输出 JSON 并对 & " \' 转义，可安全嵌入 HTML 属性', () => {
    // 键名的引号也被转义，这是既有行为（用于嵌进 onclick 属性，属性值本身用单引号）
    assert.equal(js({ a: 1 }), '{&quot;a&quot;:1}');
    const out = js({ x: `'a"b&c` });
    assert.ok(out.includes('&#39;'));
    assert.ok(out.includes('&quot;'));
    assert.ok(out.includes('&amp;'));
    // 不逃逸 JSON 结构本身
    assert.ok(out.startsWith('{'));
});

test('truncateUrl 取仓库尾两段并剥 .git / 尾斜杠', () => {
    assert.equal(truncateUrl('https://gitlab.com/group/repo.git'), 'group/repo');
    assert.equal(truncateUrl('https://gitlab.com/group/repo'), 'group/repo');
    assert.equal(truncateUrl('https://gitlab.com/group/repo/'), 'group/repo');
    assert.equal(truncateUrl('repo'), 'repo');
});

test('encodePath 逐段 encodeURIComponent（保留斜杠分隔）', () => {
    assert.equal(encodePath('group/repo'), 'group/repo');
    assert.equal(encodePath('group/re po'), 'group/re%20po');
    assert.equal(encodePath('a/b/c'), 'a/b/c');
});

test('fmtYmd 把 epoch 秒格式化为 YYYY/MM/DD', () => {
    // 2020-01-05 12:34:56 UTC = 1578227696
    assert.equal(fmtYmd(1578227696), '2020/01/05');
});

test('normalizePerms 归一化权限数组 / 通配符', () => {
    assert.deepEqual(normalizePerms(['a', 'b']), ['a', 'b']);
    assert.deepEqual(normalizePerms('*'), ['*']);
    assert.deepEqual(normalizePerms(''), []);
    assert.deepEqual(normalizePerms(null), []);
    assert.deepEqual(normalizePerms({}), []);
});

/**
 * 模拟浏览器解析属性值时的 HTML 字符引用还原。
 * 顺序关键：具名实体先解，&amp; 最后解，否则 &amp;lt; 会被二次解码成 '<'。
 */
function decodeEntities(s) {
    return String(s)
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}

test('esc + escJs 组合封住 onclick 里的 XSS 载荷（端到端）', () => {
    // 真实用法（见 public/assets/modules/mapping.js:126）：
    //   onclick="deleteMap('${escJs(esc(name))}')"
    const payload = `x'); doEvil(); //"<img src=x onerror=alert(1)>`;
    const attrValue = `deleteMap('${escJs(esc(payload))}')`;

    // 浏览器还原实体后，JS 引擎看到的源码
    const seenByJs = decodeEntities(attrValue);

    // 载荷必须仍是一个完整的单引号字符串字面量，且内容原样
    const m = seenByJs.match(/^deleteMap\('((?:\\.|[^'\\])*)'\)$/);
    assert.ok(m, `载荷逃出了 JS 字符串字面量: ${seenByJs}`);
    assert.equal(m[1].replace(/\\(.)/g, '$1'), payload);
});

test('js 输出解码后可被 JSON.parse 还原（onclick 属性场景）', () => {
    const obj = { job_name: `a'b"c&d<e`, n: 1 };
    // 浏览器还原实体后，属性里传给 activateMap 的第二参数
    const seenByJs = decodeEntities(js(obj));
    assert.deepEqual(JSON.parse(seenByJs), obj);
});
