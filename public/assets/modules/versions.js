import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast } from '../core/toast.js';

const VERSIONS_API = '/api/admin/platform_versions';
const VER_INFO = {
    gitlab: {label:'GitLab', desc: function(){return __.t('platform.gitlab_desc');}},
    gitee:  {label:'Gitee',  desc: function(){return __.t('platform.gitee_desc');}},
    github: {label:'GitHub', desc: function(){return __.t('platform.github_desc');}},
    gitea:  {label:'Gitea',  desc: function(){return __.t('platform.gitea_desc');}},
    harbor: {label:'Harbor', desc: function(){return __.t('platform.harbor_desc');}},
};
let currentVersions = {};

export async function loadVersions() {
    document.getElementById('ver-loading').style.display = 'block';
    document.getElementById('ver-table-wrap').style.display = 'none';

    // 列表（配置态，毫秒级）+ 版本探测（慢）并发：探测结果后台补齐，避免平台不可达时整页卡住
    const listReq  = fetch(VERSIONS_API, { headers: authHeaders() });
    const probeReq = fetch(VERSIONS_API + '/probe', { headers: authHeaders() });

    try {
        const res = await listReq;
        if (handle401(res)) return;
        const data = await res.json();
        const raw = data.versions || {};
        currentVersions = {};
        Object.entries(raw).forEach(([k, v]) => {
            currentVersions[k] = typeof v === 'object' ? v : {value: v, source: 'unknown'};
        });
        document.getElementById('ver-loading').style.display = 'none';
        document.getElementById('ver-table-wrap').style.display = 'block';
        const tbody = document.getElementById('ver-tbody');
        tbody.innerHTML = Object.entries(VER_INFO).map(([key, info]) => {
            const entry = currentVersions[key] || {value: '', source: 'default'};
            const val = entry.value || '';
            const src = entry.source;
            const defVer = getDefaultVer(key);
            const isDefault = val === defVer || val === '';

            let srcBadge;
            if (src === 'config')  srcBadge = '<span class="badge" style="background:#fde8e8;color:#c81e1e;">' + __.t('js.version_source_config') + '</span>';
            else if (src === 'json') srcBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;">' + __.t('js.version_source_admin') + '</span>';
            else srcBadge = '<span class="badge" style="background:#f3f4f6;color:#6b7280;">' + __.t('js.version_source_default') + '</span>';

            const readonly = src === 'config';
            const displayVal = readonly ? val : (isDefault ? '' : val);
            const ph = readonly ? val : defVer;
            const inputStyle = readonly
                ? 'width:120px;padding:6px 10px;background:#f9fafb;color:#9ca3af;cursor:not-allowed;'
                : (isDefault ? 'width:120px;padding:6px 10px;' : 'width:120px;padding:6px 10px;border-color:#f59e0b;background:#fffbeb;');

            return `<tr>
                <td><strong>${info.label}</strong> <span style="color:#9ca3af;font-size:11px;">(${key})</span></td>
                <td><code style="font-size:12px;color:#6b7280;">${esc(defVer)}</code></td>
                <td>${srcBadge}</td>
                <td><input data-platform="${key}" value="${esc(displayVal)}" placeholder="${esc(ph)}"
                      style="${inputStyle}" ${readonly ? 'readonly title="' + esc(__.t('js.version_config_readonly_title')) + '"' : ''}></td>
                <td style="font-size:12px;color:#6b7280;">${info.desc()}</td>
            </tr>`;
        }).join('');
        renderHarborCompat();
        renderJenkinsCompat();
    } catch(e) {
        document.getElementById('ver-loading').innerHTML = '<p style="color:#dc2626;">' + __.t('js.load_failed') + ': ' + esc(e.message) + '</p>';
    }

    // 慢探测：后台补齐实际版本号 + Harbor 机器人支持情况（失败/超时保持「未知版本」占位）
    try {
        const res = await probeReq;
        if (handle401(res)) return;
        const data = await res.json();
        if (data && data.harbor) {
            const h = currentVersions.harbor || (currentVersions.harbor = {});
            if (data.harbor.detected_version != null) h.detected_version = data.harbor.detected_version;
            if (data.harbor.robot_support) h.robot_support = data.harbor.robot_support;
        }
        if (data && data.jenkins) {
            const j = currentVersions.jenkins || (currentVersions.jenkins = {});
            if (data.jenkins.detected_version != null) j.detected_version = data.jenkins.detected_version;
        }
        renderHarborCompat();
        renderJenkinsCompat();
    } catch(e) {}
}

function renderHarborCompat() {
    const el = document.getElementById('harbor-compat');
    if (!el) return;
    const h = currentVersions.harbor || {};
    const ver = h.detected_version || null;
    const support = h.robot_support || 'unknown';
    const isRobot = !!h.robot_account;
    let badge, text;
    if (support === 'supported') { badge = '✅'; text = __.t('js.harbor_robot_supported'); }
    else if (support === 'unsupported') { badge = '⚠️'; text = __.t('js.harbor_robot_unsupported'); }
    else { badge = '❓'; text = __.t('js.harbor_robot_unknown'); }
    const verText = ver ? ver : __.t('js.harbor_version_unknown');
    let extra = '';
    if (isRobot && support === 'unsupported') extra = ' <strong style="color:#c81e1e;">' + __.t('js.harbor_robot_warning') + '</strong>';
    el.style.display = 'block';
    el.innerHTML = '<strong>🐳 Harbor</strong> · ' + __.t('js.harbor_detected_version')
        + ': <code>' + esc(verText) + '</code> · ' + badge + ' ' + text + extra;
}

function renderJenkinsCompat() {
    const el = document.getElementById('jenkins-compat');
    if (!el) return;
    const j = currentVersions.jenkins || {};
    if (!j.configured) { el.style.display = 'none'; return; }
    const ver = j.detected_version || null;
    const verText = ver ? ver : __.t('js.jenkins_version_unknown');
    el.style.display = 'block';
    el.innerHTML = '<strong>⚡ Jenkins</strong> · ' + __.t('js.jenkins_detected_version')
        + ': <code>' + esc(verText) + '</code>';
}

function getDefaultVer(key) {
    const defaults = { gitlab:'v4', gitee:'v5', github:'v3', gitea:'v1', harbor:'v2.0' };
    return defaults[key] || '';
}

export async function saveVersions() {
    const versions = {};
    document.querySelectorAll('#ver-tbody input').forEach(inp => {
        const val = inp.value.trim();
        if (val) versions[inp.dataset.platform] = val;
    });
    if (Object.keys(versions).length === 0) { toast(__.t('js.no_version_changes'), true); return; }
    try {
        const res = await fetch(VERSIONS_API, {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({versions})
        });
        if (handle401(res)) return;
        const data = await res.json();
        if (res.ok) {
            const st = document.getElementById('ver-status');
            st.style.display = 'inline';
            setTimeout(() => st.style.display = 'none', 2000);
            currentVersions = data.versions || {};
            loadVersions();
        } else {
            toast(data.message || __.t('js.save_failed'), false);
        }
    } catch(e) { toast(__.t('js.network_error') + ': ' + e.message, false); }
}