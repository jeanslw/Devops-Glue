import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import { getRole } from '../core/auth.js';
import { applyTagSettings, applyApiLogSettings } from './mode.js';

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
        // 迁移模式：按 DB_AUTO_MIGRATE 实际生效值渲染自动/手动语义；手动模式橙色提示「建表责任在运维」
        const mm = document.getElementById('sys-migrate-mode');
        const auto = d.auto_migrate === true;
        mm.textContent = auto ? __.t('sys.migrate_mode_auto') : __.t('sys.migrate_mode_manual');
        mm.style.color = auto ? '#16a34a' : '#d97706';
        // 探测清单自检（Database::schemaListDrift()，纯反射、与库无关）：为「升级/打补丁绕过了 CI」的
        // 实例把「后台表清单比真实库少表」这件事红字点名——旧版本里它只能靠人偶然发现。
        // 字段缺失时不渲染（保留占位符 `-`）：没有证据就不宣称「一致」。
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
        loadIntegratedServices();
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
            // 409 = 账号无 DDL 权限（可操作提示，非故障）：橙色 + ⚠️ + warn 级 toast，与真正的失败(红)区分
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

// ═══════════ 集成服务（integrated_services 契约；CI 唯一写入方）═══════════
// 当前仅一个服务 cd；页面只展示状态 + 启停，启用向导一次性展示自动签发的服务 token。
let _isvcTokenPlain = '';

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
    const btnEnable = document.getElementById('isvc-cd-enable');
    const btnDisable = document.getElementById('isvc-cd-disable');
    if (!dot || !status || !meta) return;

    const enabled = d.enabled === true;
    const isAuto = d.provision !== 'manual'; // 缺省/未知按 auto 语义（行不存在时后端给 auto）
    const isSuper = getRole() === 'super_admin';

    dot.style.background = !enabled ? '#9ca3af' : (isAuto ? '#16a34a' : '#2563eb');
    status.textContent = !enabled
        ? __.t('sys.isvc_off')
        : (isAuto ? __.t('sys.isvc_on_auto') : __.t('sys.isvc_on_manual'));
    status.style.color = !enabled ? '#9ca3af' : (isAuto ? '#16a34a' : '#2563eb');

    const lines = [];
    lines.push('<span>' + esc(__.t('sys.isvc_provision')) + '：'
        + (isAuto ? esc(__.t('sys.isvc_provision_auto')) : esc(__.t('sys.isvc_provision_manual'))) + '</span>');
    if (enabled) {
        const tokenLabel = d.token_name ? (esc(d.token_name) + (d.token_id ? ' (#' + d.token_id + ')' : '')) : '-';
        const tokenState = d.token_active
            ? '<span style="color:#16a34a;">' + esc(__.t('sys.isvc_token_active')) + '</span>'
            : '<span style="color:#dc2626;">' + esc(__.t('sys.isvc_token_invalid')) + '</span>';
        lines.push('<span>' + esc(__.t('sys.isvc_token')) + '：' + tokenLabel + ' · ' + tokenState + '</span>');
        if (d.updated_by || d.updated_at) {
            lines.push('<span>' + esc(__.t('sys.isvc_updated')) + '：'
                + esc(d.updated_by || '-') + ' · ' + esc(d.updated_at || '') + '</span>');
        }
    } else if (d.default_provision) {
        // 启用前提示：点启用那一刻将按哪种方式写入契约
        lines.push('<span style="color:#9ca3af;">' + esc(__.t('sys.isvc_will_provision')) + '：'
            + (d.default_provision === 'manual' ? esc(__.t('sys.isvc_provision_manual')) : esc(__.t('sys.isvc_provision_auto')))
            + '</span>');
    }
    meta.innerHTML = lines.join('');

    if (btnEnable) btnEnable.style.display = (isSuper && !enabled) ? '' : 'none';
    if (btnDisable) btnDisable.style.display = (isSuper && enabled) ? '' : 'none';
}

export async function enableIntegratedService(service) {
    // provision 不传：后端在点启用那一刻按 CI 当前 DB_AUTO_MIGRATE 模式写入（auto/manual）
    // 提示走纯文本 + \n 换行：confirmDialog 的 message/note 由 core/toast.js 用 textContent 渲染，
    // 这里塞 HTML 只会把 <div> 标签原样显示出来（曾如此），断行交给 .modal-note 的 white-space:pre-line。
    const note = [
        '① ' + __.t('sys.isvc_enable_step1'),
        '② ' + __.t('sys.isvc_enable_step2'),
        '③ ' + __.t('sys.isvc_enable_step3')
    ].join('\n');
    if (!await confirmDialog({
        title: __.t('sys.isvc_enable'),
        message: __.t('sys.isvc_enable_confirm', { name: __.t('sys.isvc_cd_name') }),
        note: note,
        confirmText: __.t('sys.isvc_enable')
    })) return;
    try {
        const res = await fetch('/api/admin/integrated_services/' + encodeURIComponent(service) + '/enable', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: '{}'
        });
        if (handle401(res)) return;
        const d = await res.json();
        if (res.ok && d.token) {
            _isvcTokenPlain = d.token;
            const box = document.getElementById('isvc-token-box');
            const val = document.getElementById('isvc-token-value');
            if (box) box.style.display = 'block';
            if (val) val.textContent = d.token;
            toast(__.t('sys.isvc_enabled'), true);
            loadIntegratedServices();
        } else {
            toast(d.message || __.t('js.network_error'), false, false, 6000);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

export async function disableIntegratedService(service) {
    if (!await confirmDialog({
        title: __.t('sys.isvc_disable'),
        message: __.t('sys.isvc_disable_confirm', { name: __.t('sys.isvc_cd_name') }),
        confirmText: __.t('sys.isvc_disable')
    })) return;
    try {
        const res = await fetch('/api/admin/integrated_services/' + encodeURIComponent(service) + '/disable', {
            method: 'POST',
            headers: authHeaders()
        });
        if (handle401(res)) return;
        const d = await res.json();
        if (res.ok) {
            hideIntegratedTokenBox();
            toast(__.t('sys.isvc_disabled'), true);
            loadIntegratedServices();
        } else {
            toast(d.message || __.t('js.network_error'), false, false, 6000);
        }
    } catch (e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
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