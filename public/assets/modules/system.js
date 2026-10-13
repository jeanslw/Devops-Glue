import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import { getRole } from '../core/auth.js';
import { applyTagSettings, applyApiLogSettings } from './mode.js';
import { loadApiTokens } from './apiTokens.js';

export async function loadSystemInfo() {
    const loading = document.getElementById('sys-loading');
    const body = document.getElementById('sys-body');
    loading.style.display = '';
    body.style.display = 'none';
    try {
        const res = await fetch('/api/admin/system_info', { headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (!res.ok) { toast(d.message || 'load failed', false); loading.style.display = 'none'; return; }
        document.getElementById('sys-driver').textContent = d.driver || '-';
        document.getElementById('sys-schema-version').textContent = d.schema_version || __.t('sys.none');
        document.getElementById('sys-php-version').textContent = d.php_version || '—';
        const cur = document.getElementById('sys-is-current');
        cur.textContent = d.is_current ? __.t('sys.current') : __.t('sys.outdated');
        cur.style.color = d.is_current ? '#16a34a' : '#dc2626';
        // 迁移模式：按 DB_AUTO_MIGRATE 实际生效值渲染自动/手动语义；手动模式用橙色提示建表责任在运维
        const mm = document.getElementById('sys-migrate-mode');
        const auto = d.auto_migrate === true;
        mm.textContent = auto ? __.t('sys.migrate_mode_auto') : __.t('sys.migrate_mode_manual');
        mm.style.color = auto ? '#16a34a' : '#d97706';
        // 探测清单自检（Database::schemaListDrift()，纯反射、与库无关）：对升级或打补丁绕过 CI 的实例，
        // 把后台表清单比真实库少表的情况用红字指出；旧版本里这类问题只能靠人工偶然发现。
        // 字段缺失时不渲染（保留占位符 `-`）：没有证据就不显示一致。
        const sl = document.getElementById('sys-schema-list');
        const driftInfo = d.schema_list_drift;
        if (sl && driftInfo) {
            const drift = driftInfo.tables || [];
            sl.textContent = drift.length
                ? __.t('sys.schema_list_drift', { tables: drift.join(', ') })
                : __.t('sys.schema_list_ok');
            sl.style.color = drift.length ? '#dc2626' : '#16a34a';
        }
        const tb = document.getElementById('sys-tables');
        tb.innerHTML = '';
        const tables = d.tables || {};
        Object.keys(tables).forEach(function(name) {
            const ok = tables[name];
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;border-bottom:1px solid #f3f4f6;';
            row.innerHTML = '<span style="width:10px;height:10px;border-radius:50%;background:' + (ok ? '#16a34a' : '#dc2626') + ';flex-shrink:0;"></span>'
                + '<span style="min-width:0;word-break:break-all;">' + esc(name) + '</span>'
                + '<span style="margin-left:auto;color:' + (ok ? '#16a34a' : '#dc2626') + ';white-space:nowrap;">' + (ok ? '✓' : '✗') + '</span>';
            tb.appendChild(row);
        });
        const cnt = document.getElementById('sys-tables-count');
        if (cnt) cnt.textContent = '(' + Object.keys(tables).length + ')';
        const btn = document.getElementById('sys-migrate-btn');
        if (btn) btn.style.display = (getRole() === 'super_admin') ? '' : 'none';
        const bbtn = document.getElementById('sys-backup-btn');
        if (bbtn) bbtn.style.display = (getRole() === 'super_admin') ? '' : 'none';
        if (getRole() === 'super_admin') { loadBackups(); }
        loading.style.display = 'none';
        body.style.display = 'block';
        // 集成服务面板已迁至 API 管理页（Token 列表上方；由 admin.js 的 api-tokens 分支加载）：
        // 本页只负责库结构 / 备份，不再请求集成服务状态。
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        loading.style.display = 'none';
    }
}

export async function doMigrate() {
    if (!await confirmDialog({ title: __.t('common.confirm'), message: __.t('sys.migrate_confirm'), note: __.t('sys.migrate_note'), confirmText: __.t('sys.migrate_btn') })) return;
    const st = document.getElementById('sys-migrate-status');
    st.textContent = '⏳ …';
    st.style.color = '#6b7280';
    try {
        const res = await fetch('/api/admin/migrate', { method: 'POST', headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (res.ok) {
            st.textContent = '✅ ' + __.t('sys.migrated');
            st.style.color = '#16a34a';
            toast(__.t('sys.migrated'), true);
            loadSystemInfo();
        } else {
            const denied = res.status === 409;
            // 409 = 账号无 DDL 权限（可操作提示，非故障）：橙色 + warn 级 toast，与真正的失败(红)区分
            st.textContent = (denied ? '⚠️ ' : '❌ ') + (d.message || __.t('sys.migrate_failed'));
            st.style.color = denied ? '#d97706' : '#dc2626';
            toast(d.message || __.t('sys.migrate_failed'), denied ? 'warn' : false);
        }
    } catch(e) {
        st.textContent = '❌ ' + e.message;
        st.style.color = '#dc2626';
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

export async function doBackup() {
    if (!await confirmDialog({ title: __.t('common.confirm'), message: __.t('sys.backup_confirm'), confirmText: __.t('sys.backup_btn') })) return;
    const st = document.getElementById('sys-backup-status');
    st.textContent = '⏳ …';
    st.style.color = '#6b7280';
    try {
        const res = await fetch('/api/admin/backup', { method: 'POST', headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (res.ok) {
            const names = (Array.isArray(d.files) ? d.files : []).map(function(f) { return f.name || ''; }).filter(Boolean);
            const label = names.length ? names.join(' · ') : (d.file || '');
            const warns = Array.isArray(d.warnings) ? d.warnings.filter(Boolean) : [];
            if (warns.length) {
                st.textContent = '⚠️ ' + __.t('sys.backup_done') + ' · ' + label + ' — ' + warns.join(' ');
                st.style.color = '#d97706';
                toast('⚠️ ' + warns.join(' '), false, false, 6000);
            } else {
                st.textContent = '✅ ' + __.t('sys.backup_done') + ' · ' + label;
                st.style.color = '#16a34a';
                toast(__.t('sys.backup_done') + ' · ' + label, true);
            }
        } else {
            st.textContent = '❌ ' + (d.message || __.t('sys.backup_failed'));
            st.style.color = '#dc2626';
            toast(d.message || __.t('sys.backup_failed'), false);
        }
    } catch(e) {
        st.textContent = '❌ ' + e.message;
        st.style.color = '#dc2626';
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

function formatSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let n = bytes, i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + units[i];
}

export async function loadBackups() {
    const el = document.getElementById('sys-backups');
    if (!el) return;
    try {
        const res = await fetch('/api/admin/backups', { headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (!res.ok || !Array.isArray(d.files) || d.files.length === 0) { el.style.display = 'none'; return; }
        const items = d.files.map(function(f) {
            const dt = new Date(f.mtime * 1000);
            const t = dt.getFullYear() + '/' + String(dt.getMonth() + 1).padStart(2, '0') + '/' + String(dt.getDate()).padStart(2, '0')
                + ' ' + String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0') + ':' + String(dt.getSeconds()).padStart(2, '0');
            return '<div style="padding:6px 0;border-bottom:1px solid #f3f4f6;">'
                + '<div style="display:flex;align-items:center;gap:6px;">'
                + '<span>🗜️</span>'
                + '<span style="overflow-wrap:anywhere;min-width:0;font-size:13px;">' + esc(f.name) + '</span>'
                + '</div>'
                + '<div style="display:flex;gap:8px;color:#9ca3af;font-size:11px;margin-top:3px;padding-left:20px;">'
                + '<span style="white-space:nowrap;">' + formatSize(f.size) + '</span>'
                + '<span style="white-space:nowrap;">' + esc(t) + '</span>'
                + '</div>'
                + '</div>';
        }).join('');
        el.innerHTML = '<div style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:10px 12px;font-weight:600;font-size:13px;background:#f9fafb;user-select:none;" onclick="toggleSysBackups()">'
            + '<span>🗜️ ' + __.t('sys.backup_list') + '</span>'
            + '<span style="color:#6b7280;font-weight:400;">(' + d.files.length + ')</span>'
            + '<span id="sys-backups-arrow" style="margin-left:auto;">▶</span>'
            + '</div>'
            + '<div id="sys-backups-list" style="padding:10px 12px;display:none;">' + items + '</div>';
        el.style.display = '';
    } catch(e) {
        el.style.display = 'none';
    }
}

export function toggleSysBackups() {
    const list = document.getElementById('sys-backups-list');
    const arrow = document.getElementById('sys-backups-arrow');
    if (!list) return;
    const hidden = list.style.display === 'none';
    list.style.display = hidden ? 'block' : 'none';
    if (arrow) arrow.textContent = hidden ? '▼' : '▶';
}

export async function loadPlatformConfig() {
    const loading = document.getElementById('pc-loading');
    const body = document.getElementById('pc-body');
    loading.style.display = '';
    body.style.display = 'none';
    try {
        const res = await fetch('/api/admin/platform_config', { headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (!res.ok) { toast(d.message || 'load failed', false); loading.style.display = 'none'; return; }
        applyTagSettings(d);
        applyApiLogSettings(d);
        const wrap = document.getElementById('pc-platforms');
        wrap.innerHTML = '';
        const platforms = d.platforms || {};
        const defs = [
            ['jenkins', '🤖 Jenkins'],
            ['gitlab', '🦊 GitLab'],
            ['github', '🐙 GitHub'],
            ['gitee', '🐈 Gitee'],
            ['gitea', '🦎 Gitea'],
            ['harbor', '🐳 Harbor'],
        ];
        defs.forEach(function(def) {
            const label = def[1];
            const p = platforms[def[0]] || {};
            const ok = !!p.configured;
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 0;font-size:13px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;';
            row.innerHTML = '<span style="width:10px;height:10px;border-radius:50%;background:' + (ok ? '#16a34a' : '#dc2626') + ';flex-shrink:0;"></span>'
                + '<span style="font-weight:600;min-width:120px;">' + esc(label) + '</span>'
                + '<span style="margin-left:auto;color:' + (ok ? '#16a34a' : '#dc2626') + ';font-weight:600;">' + (ok ? '✓ ' : '✗ ') + (ok ? __.t('sys.configured') : __.t('sys.not_configured')) + '</span>';
            wrap.appendChild(row);
        });
        loading.style.display = 'none';
        body.style.display = 'block';
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        loading.style.display = 'none';
    }
}

export function toggleSysTables() {
    const tb = document.getElementById('sys-tables');
    const arrow = document.getElementById('sys-tables-arrow');
    if (!tb) return;
    const hidden = tb.style.display === 'none';
    tb.style.display = hidden ? 'block' : 'none';
    if (arrow) arrow.textContent = hidden ? '▼' : '▶';
}

// ═══════════ 集成服务（integrated_services 契约；唯一写入方为 CI）═══════════
// 当前仅一个服务 cd；页面只展示状态并用启用勾选框启停（勾选=启用、取消=禁用），
// 启用向导一次性展示自动签发的服务 token。
// 勾选态 = 可用 = 契约行 enabled 且配套凭据有效：凭据在下方 Token 列表里被撤销/删除后
// 勾选框自动回到未勾选（服务已不可用），再勾选一次即补发新凭据（后端 enable 对故障态走补发）。
let _isvcTokenPlain = '';
let _isvcState = null; // 最近一次状态响应：enable 据此判断走「首次启用」还是「补发」文案

export async function loadIntegratedServices() {
    const loading = document.getElementById('isvc-loading');
    const body = document.getElementById('isvc-body');
    if (!loading || !body) return;
    loading.style.display = '';
    body.style.display = 'none';
    try {
        const res = await fetch('/api/admin/integrated_services/cd', { headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (!res.ok) { toast(d.message || 'load failed', false); loading.style.display = 'none'; return; }
        renderCdService(d);
        loading.style.display = 'none';
        body.style.display = 'block';
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        loading.style.display = 'none';
    }
}

function renderCdService(d) {
    const dot = document.getElementById('isvc-cd-dot');
    const status = document.getElementById('isvc-cd-status');
    const meta = document.getElementById('isvc-cd-meta');
    const toggle = document.getElementById('isvc-cd-toggle');
    const toggleWrap = document.getElementById('isvc-cd-toggle-wrap');
    if (!dot || !status || !meta) return;

    const enabled = d.enabled === true;
    const tokenActive = d.token_active === true;
    // 凭据要分三态看：还在 / 未被停用 / 未过期。绑定凭据还在、只是被停用且未过期，即上一次禁用留下的冻结状态，
    // 再启用即恢复同一枚（原明文继续有效，CD 无需改配置）；已作废的（撤销 / 删除 / 过期）只能补发新凭据。
    const tokenFrozen = d.token_exists === true && d.token_enabled !== true && d.token_expired !== true;
    // 可用 = 契约行已启用且配套凭据有效。凭据被作废后即不可用，
    // 勾选框随之回到未勾选：再勾选一次即补发新凭据（后端对已启用但凭据失效放行走补发）。
    const usable = enabled && tokenActive;
    const isAuto = d.provision !== 'manual'; // 缺省/未知按 auto 语义（行不存在时后端给 auto）
    const isSuper = getRole() === 'super_admin';

    // 三态：未启用（灰）/ 可用（绿=自动建表、蓝=手动建表）/ 已启用但凭据失效（红，等补发）
    const stateColor = !enabled ? '#9ca3af' : (usable ? (isAuto ? '#16a34a' : '#2563eb') : '#dc2626');
    dot.style.background = stateColor;
    status.textContent = !enabled
        ? __.t('sys.isvc_off')
        : (usable ? (isAuto ? __.t('sys.isvc_on_auto') : __.t('sys.isvc_on_manual')) : __.t('sys.isvc_token_dead'));
    status.style.color = stateColor;

    const lines = [];
    lines.push('<span>' + esc(__.t('sys.isvc_provision')) + '：'
        + (isAuto ? esc(__.t('sys.isvc_provision_auto')) : esc(__.t('sys.isvc_provision_manual'))) + '</span>');
    if (enabled) {
        const tokenLabel = d.token_name ? (esc(d.token_name) + (d.token_id ? ' (#' + d.token_id + ')' : '')) : '-';
        const tokenState = tokenActive
            ? '<span style="color:#16a34a;">' + esc(__.t('sys.isvc_token_active')) + '</span>'
            : '<span style="color:#dc2626;">' + esc(__.t('sys.isvc_token_invalid')) + '</span>';
        lines.push('<span>' + esc(__.t('sys.isvc_token')) + '：' + tokenLabel + ' · ' + tokenState + '</span>');
        if (d.updated_by || d.updated_at) {
            lines.push('<span>' + esc(__.t('sys.isvc_updated')) + '：'
                + esc(d.updated_by || '-') + ' · ' + esc(d.updated_at || '') + '</span>');
        }
        if (!tokenActive) {
            // 故障态（凭据被作废）的自助恢复路径：此刻勾选框是未勾选态，勾一下即补发（不必先取消勾选再勾选）
            lines.push('<span style="color:#dc2626;">' + esc(__.t('sys.isvc_reissue_hint')) + '</span>');
        }
    } else {
        if (tokenFrozen) {
            // 冻结状态（上一次禁用留下的结果）：说明再启用即恢复同一枚，避免运营以为每次启用都会换 Token
            const frozenToken = d.token_name ? (d.token_name + (d.token_id ? ' (#' + d.token_id + ')' : '')) : '-';
            lines.push('<span style="color:#9ca3af;">'
                + esc(__.t('sys.isvc_token_frozen', { token: frozenToken })) + '</span>');
        }
        if (d.default_provision) {
            // 启用前提示：点启用那一刻将按哪种方式写入契约
            lines.push('<span style="color:#9ca3af;">' + esc(__.t('sys.isvc_will_provision')) + '：'
                + (d.default_provision === 'manual' ? esc(__.t('sys.isvc_provision_manual')) : esc(__.t('sys.isvc_provision_auto')))
                + '</span>');
        }
    }
    meta.innerHTML = lines.join('');

    // 启用勾选框是唯一启停入口（与平台管理各功能开关同一交互）：
    // 勾选=启用（勾选瞬间签发服务 Token，故一律走确认弹窗），取消=冻结并撤销该 Token。
    // 勾选态取可用而非裸 enabled：凭据失效时必须显示为未勾选，否则故障态勾选框停在勾选位、
    // 点不动也变不了，用户只能看红字而无法自助补发。
    // 非 super_admin 不显示勾选框（状态仍可见）。
    if (toggle) { toggle.checked = usable; toggle.disabled = !isSuper; }
    if (toggleWrap) toggleWrap.style.display = isSuper ? '' : 'none';
    _isvcState = d; // 供 enableIntegratedService 判断「首次启用」还是「补发」
}

export async function enableIntegratedService(service) {
    // 调用方：onIntegratedServiceToggle（启用勾选框被勾上时）
    // 返回值：true=已启用（明文 Token 已在弹窗就绪）；false=用户取消 / 请求失败，调用方据此把勾选框退回
    // provision 不传：后端在点启用那一刻按 CI 当前 DB_AUTO_MIGRATE 模式写入（auto/manual）
    // 提示走纯文本 + \n 换行：confirmDialog 的 message/note 由 core/toast.js 用 textContent 渲染，
    // 这里塞 HTML 只会把 <div> 标签原样显示出来（曾如此），断行交给 .modal-note 的 white-space:pre-line。
    // 勾选框只有一种动作，但后端有三条路径（首次启用 / 冻结恢复 / 作废补发），弹窗必须事先说清是哪一条。
    // 弹窗只讲要点与结果、不解释原因（原因在卡片文案与文档里）：
    //   - 恢复：复用同一枚 Token（CD 的 CI_API_TOKEN 无需改动），弹窗首行即恢复提示；
    //   - 补发：新明文必须同步到 CD，弹窗首行即补发提示；
    // 两种情况下面的两条固定要点都不变。
    const st = _isvcState || {};
    const reuse = st.enabled !== true && st.token_exists === true && st.token_enabled !== true && st.token_expired !== true;
    const reissue = st.enabled === true && st.token_active !== true;
    const head = reuse
        ? ['♻ ' + __.t('sys.isvc_reuse_note')]
        : (reissue ? ['⓪ ' + __.t('sys.isvc_reissue_note')] : []);
    const note = head.concat([
        '① ' + __.t('sys.isvc_enable_step1'),
        '② ' + __.t('sys.isvc_enable_step2')
    ]).join('\n');
    if (!await confirmDialog({
        title: __.t('sys.isvc_enable'),
        message: __.t('sys.isvc_enable_confirm', { name: __.t('sys.isvc_cd_name') }),
        note: note,
        confirmText: __.t('sys.isvc_enable')
    })) return false;
    try {
        const res = await fetch('/api/admin/integrated_services/' + encodeURIComponent(service) + '/enable', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: '{}'
        });
        if (handle401(res)) return false;
        const d = await res.json();
        if (res.ok && d.reused) {
            // 恢复：同一枚凭据重新生效。明文不重发（CD 手上就是这一枚），故不弹 Token 框，也不显示为已签发
            hideIntegratedTokenBox();
            toast(__.t('sys.isvc_restored'), true);
            loadIntegratedServices();
            loadApiTokens(); // 同屏：那枚 cd-service 在下方列表里由「已禁用」回到「有效」
            return true;
        }
        if (res.ok && d.token) {
            _isvcTokenPlain = d.token;
            const box = document.getElementById('isvc-token-box');
            const val = document.getElementById('isvc-token-value');
            if (box) box.style.display = 'block';
            if (val) val.textContent = d.token;
            toast(__.t('sys.isvc_enabled'), true);
            loadIntegratedServices();
            loadApiTokens(); // 同屏：刚签发的 cd-service 立即出现在下方 Token 列表里
            return true;
        }
        toast(d.message || __.t('js.network_error'), false, false, 6000);
        return false;
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        return false;
    }
}

export async function disableIntegratedService(service) {
    // 调用方：onIntegratedServiceToggle（启用勾选框被取消时）
    // 返回值：true=已禁用（配套 Token 已停用，即仅冻结）；false=用户取消 / 请求失败，调用方把勾选框重新勾上
    // 弹窗与启用一致：正文一句问句，要点走 note 的 ①② 多行（纯文本 + \n 断行，见 ConfirmDialogPlainTextTest）
    const note = [
        '① ' + __.t('sys.isvc_disable_step1'),
        '② ' + __.t('sys.isvc_disable_step2')
    ].join('\n');
    if (!await confirmDialog({
        title: __.t('sys.isvc_disable'),
        message: __.t('sys.isvc_disable_confirm', { name: __.t('sys.isvc_cd_name') }),
        note: note,
        confirmText: __.t('sys.isvc_disable')
    })) return false;
    try {
        const res = await fetch('/api/admin/integrated_services/' + encodeURIComponent(service) + '/disable', {
            method: 'POST',
            headers: authHeaders()
        });
        if (handle401(res)) return false;
        const d = await res.json();
        if (res.ok) {
            hideIntegratedTokenBox();
            toast(__.t('sys.isvc_disabled'), true);
            loadIntegratedServices();
            loadApiTokens(); // 同屏：被撤销的 cd-service 状态立即在下方列表里更新
            return true;
        }
        toast(d.message || __.t('js.network_error'), false, false, 6000);
        return false;
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        return false;
    }
}

// 启用勾选框的 onchange 入口（唯一的启停入口）。
// 勾选框是即时控件，而切换会签发/撤销凭据，故确认弹窗放在 enable/disable 内部，
// 这里只负责请求期间禁用防连点、以及取消或失败时把勾选框退回原状态，
// 成功后由 loadIntegratedServices() 按后端返回的状态重绘（勾选态与 disabled 都以服务端为准）。
export async function onIntegratedServiceToggle(service) {
    const toggle = document.getElementById('isvc-cd-toggle');
    if (!toggle || toggle.disabled) return;
    const want = toggle.checked;
    toggle.disabled = true;
    const ok = want
        ? await enableIntegratedService(service)
        : await disableIntegratedService(service);
    if (!ok) toggle.checked = !want;
    toggle.disabled = getRole() !== 'super_admin';
}

export function copyIntegratedToken() {
    if (!_isvcTokenPlain) return;
    navigator.clipboard.writeText(_isvcTokenPlain).then(
        function () { toast(__.t('api_token.copied'), true); },
        function () { toast(__.t('api_token.copy_failed'), false); }
    );
}

export function hideIntegratedTokenBox() {
    const box = document.getElementById('isvc-token-box');
    if (box) box.style.display = 'none';
    _isvcTokenPlain = '';
    const val = document.getElementById('isvc-token-value');
    if (val) val.textContent = '';
}