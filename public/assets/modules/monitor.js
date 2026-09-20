import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { applyBuildRecordsMenuVisibility } from './records.js';

const HEALTH_API = '/api/health';
const HEALTH_STATIC_API = '/api/health/static';

export async function loadMonitor() {
    function setSvc(iconId, nameId, statId, dotId, ok, ver, label, title, i18nKey) {
        const icon = document.getElementById(iconId);
        const name = document.getElementById(nameId);
        const stat = document.getElementById(statId);
        const dot  = document.getElementById(dotId);
        if (icon) icon.textContent = ok === true ? '✅' : ok === null ? '⚪' : '❌';
        if (stat) {
            stat.textContent = label;
            stat.className = 'svc-stat ' + (ok===true?'ok':ok===null?'off':'err');
            if (title) stat.title = title; else stat.removeAttribute('title');
            if (i18nKey) stat.setAttribute('data-i18n', i18nKey);
            else stat.removeAttribute('data-i18n');
        }
        if (dot) dot.className = 'dot ' + (ok===true?'dot-ok':ok===null?'dot-off':'dot-err');
        if (name && ver) name.innerHTML = (name.dataset.base || name.textContent) + ' <span class="svc-ver">' + esc(ver) + '</span>';
    }

    const staticReq = fetch(HEALTH_STATIC_API, { headers: authHeaders() });
    const probeReq  = fetch(HEALTH_API, { headers: authHeaders() });

    try {
        const res = await staticReq;
        if (handle401(res)) return;
        const data = await res.json();
        await __.ready();
        const st = data.stats || {};

        document.getElementById('stat-total').textContent = st.total_maps ?? '—';
        document.getElementById('stat-active').textContent = st.active_maps ?? '—';
        document.getElementById('stat-platforms').textContent = st.git_platforms ?? '—';
        document.getElementById('stat-repos').textContent = st.harbor_repos ?? '—';

        const dbMap = { 'mysql': 'system.db_mysql', 'sqlite': 'system.db_sqlite' };
        const dbKey = dbMap[(data.db_driver || '').toLowerCase()] || 'common.unknown';
        const bmLabel = (Array.isArray(data.build_modes) && data.build_modes.length > 0)
            ? data.build_modes.map(m => {
                switch (m) {
                    case 'jenkins':   return __.t('build.mode_jenkins');
                    case 'gitlab_ci': return __.t('build.mode_gitlab_ci');
                    case 'gitea_ci':  return __.t('build.mode_gitea_ci');
                    default:          return m;
                }
            }).join(' + ')
            : (data.build_mode || __.t('js.mode_none'));
        const sysBuildMode = document.getElementById('sys-build-mode');
        const sysDbType    = document.getElementById('sys-db-type');
        const sysAppVer    = document.getElementById('sys-app-version');
        const sysEnvType   = document.getElementById('sys-env-type');
        const sysTime      = document.getElementById('sys-system-time');
        if (sysBuildMode) sysBuildMode.textContent = bmLabel;
        if (sysDbType)    sysDbType.textContent    = __.t(dbKey, null, data.db_driver || '—');
        if (sysAppVer)    sysAppVer.textContent    = data.app_version ? 'v' + data.app_version : '—';
        if (sysEnvType)   sysEnvType.textContent   = data.app_env || '—';
        if (sysTime)      sysTime.textContent      = (data.time ? String(data.time).substring(0, 16) : '—');

        const cpProviders = data.custom_push_providers || [];
        const cpEnabled = data.custom_push_enabled || false;
        const cpOk = cpEnabled && cpProviders.length > 0;
        const cpLabel = cpEnabled ? __.t('common.enabled') : __.t('common.disabled');
        const cpKey = cpEnabled ? 'common.enabled' : 'common.disabled';
        setSvc('icon-custom-push', 'name-custom-push', 'stat-custom-push', 'dot-custom-push', cpOk || null, '', cpLabel, '', cpKey);
        applyBuildRecordsMenuVisibility(cpEnabled);
    } catch (e) {}

    try {
        const res = await probeReq;
        if (handle401(res)) return;
        const data = await res.json();
        const chk = data.checks || {};

        const jRaw = chk.jenkins;
        const jOk  = jRaw === true;
        const jVer = chk.jenkins_version || '';
        const jLabel = jOk ? __.t('common.ok') : jRaw===null ? __.t('common.na') : __.t('common.unreachable');
        const jKey = jOk ? 'common.ok' : (jRaw === null ? 'common.na' : 'common.unreachable');
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', jRaw, jVer ? 'v'+jVer : '', jLabel, '', jKey);

        const gitRows = document.getElementById('git-rows');
        const gitData = chk.git;
        const dotGit = document.getElementById('dot-git');
        if (gitData === null || gitData === undefined) {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat off" data-i18n="monitor.git_no_ref">' + __.t('monitor.git_no_ref') + '</span></div>';
        } else if (Array.isArray(gitData) && gitData.length > 0) {
            dotGit.className = gitData.every(g=>g.reachable) ? 'dot dot-ok' : 'dot dot-err';
            gitRows.innerHTML = gitData.map(g => {
                const ok = g.reachable;
                const okKey = ok ? 'monitor.git_reachable' : 'monitor.git_unreachable';
                const label = __.t(okKey);
                return '<div class="svc-row child">' +
                    '<span class="svc-icon">' + (ok ? '✅' : '❌') + '</span>' +
                    '<span class="svc-name">' + esc(g.name) + '<span class="svc-ver">' + esc(g.api_version || '') + '</span></span>' +
                    '<span class="svc-stat ' + (ok?'ok':'err') + '" data-i18n="' + okKey + '">' + label + '</span>' +
                '</div>';
            }).join('') || '<div class="svc-row child"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('js.no_configured_platform') + '</span></div>';
        } else {
            dotGit.className = 'dot dot-off';
            gitRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat off" data-i18n="common.unknown">' + __.t('common.unknown') + '</span></div>';
        }

        const hOkRaw = chk.harbor;
        const hOk = hOkRaw === true;
        const hVer = chk.harbor_version || '';
        const hLabel = hOk ? __.t('common.ok') : hOkRaw===null ? __.t('common.not_configured') : __.t('common.unreachable');
        const hKey = hOk ? 'common.ok' : (hOkRaw === null ? 'common.not_configured' : 'common.unreachable');
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', hOk, hVer, hLabel, '', hKey);
    } catch (e) {
        const ek = e.name === 'AbortError' ? 'js.timeout' : 'js.cannot_connect';
        const msg = __.t(ek);
        setSvc('icon-jenkins', 'name-jenkins', 'stat-jenkins', 'dot-jenkins', false, '', msg, '', ek);
        document.getElementById('dot-git').className = 'dot dot-err';
        document.getElementById('git-rows').innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat err" data-i18n="' + ek + '">' + msg + '</span></div>';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', false, '', msg, '', ek);
    }
}