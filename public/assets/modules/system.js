import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import { getRole } from '../core/auth.js';
import { loadSettings } from './mode.js';

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
        const tb = document.getElementById('sys-tables');
        tb.innerHTML = '';
        const tables = d.tables || {};
        Object.keys(tables).forEach(function(name) {
            const ok = tables[name];
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;border-bottom:1px solid #f3f4f6;';
            row.innerHTML = '<span style="width:10px;height:10px;border-radius:50%;background:' + (ok ? '#16a34a' : '#dc2626') + ';"></span>'
                + esc(name)
                + '<span style="margin-left:auto;color:' + (ok ? '#16a34a' : '#dc2626') + ';">' + (ok ? '✓' : '✗') + '</span>';
            tb.appendChild(row);
        });
        const btn = document.getElementById('sys-migrate-btn');
        if (btn) btn.style.display = (getRole() === 'super_admin') ? '' : 'none';
        const bbtn = document.getElementById('sys-backup-btn');
        if (bbtn) bbtn.style.display = (getRole() === 'super_admin') ? '' : 'none';
        loading.style.display = 'none';
        body.style.display = 'block';
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
            st.textContent = '❌ ' + (d.message || __.t('sys.migrate_failed'));
            st.style.color = '#dc2626';
            toast(d.message || __.t('sys.migrate_failed'), false);
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
            st.textContent = '✅ ' + __.t('sys.backup_done') + ' · ' + d.file;
            st.style.color = '#16a34a';
            toast(__.t('sys.backup_done') + ' · ' + d.file, true);
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

export async function loadPlatformConfig() {
    const loading = document.getElementById('pc-loading');
    const body = document.getElementById('pc-body');
    loading.style.display = '';
    body.style.display = 'none';
    try {
        await loadSettings();
        const res = await fetch('/api/admin/platform_config', { headers: authHeaders() });
        if (handle401(res)) return;
        const d = await res.json();
        if (!res.ok) { toast(d.message || 'load failed', false); loading.style.display = 'none'; return; }
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
    if (tb) tb.style.display = tb.style.display === 'none' ? 'block' : 'none';
}