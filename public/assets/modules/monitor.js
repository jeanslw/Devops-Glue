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
        if (name && ver) name.innerHTML = esc(name.dataset.base || name.textContent) + ' <span class="svc-ver">' + esc(ver) + '</span>';
    }

    // 构建平台卡片：列出所有已启用的 CI 平台。数据来自后端 ci_sources（git 平台派生的 CI 能力 + 独立 CI），
    // 每项带 enabled（是否启用）与 configured（底层平台是否已配置）；jenkins 额外用 /api/health 的探测结果。
    function renderBuildPlatforms(staticData, chk) {
        if (!staticData) return; // 静态数据缺失时保留“检测中”占位，避免误报“无已启用 CI”
        const rows = document.getElementById('build-rows');
        const dot  = document.getElementById('dot-build');
        if (!rows) return;

        const sources = Array.isArray(staticData.ci_sources) ? staticData.ci_sources : [];
        const items = [];

        for (const s of sources) {
            if (!s.enabled) continue; // 只展示已启用的 CI 平台
            const name = __.t('build.mode_' + s.ci);

            if (s.ci === 'jenkins') {
                if (!s.configured) {
                    items.push({ icon: '⚪', name: name, ver: '', stat: __.t('common.not_configured'), key: 'common.not_configured', cls: 'off', state: 'off' });
                } else {
                    const raw = chk.jenkins;
                    const ok = raw === true;
                    const na = raw === null;
                    items.push({
                        icon: ok ? '✅' : (na ? '⚪' : '❌'),
                        name: name,
                        ver: ok && chk.jenkins_version ? 'v' + chk.jenkins_version : '',
                        stat: __.t(ok ? 'common.ok' : (na ? 'common.na' : 'common.unreachable')),
                        key: ok ? 'common.ok' : (na ? 'common.na' : 'common.unreachable'),
                        cls: ok ? 'ok' : (na ? 'off' : 'err'),
                        state: ok ? 'ok' : (na ? 'off' : 'err'),
                    });
                }
            } else if (s.kind === 'git') {
                // git 平台派生的 CI：未配置时显式提示（「开了 CI 但平台没配好」）
                items.push(s.configured
                    ? { icon: '✅', name: name, ver: '', stat: __.t('common.enabled'), key: 'common.enabled', cls: 'ok', state: 'ok' }
                    : { icon: '⚪', name: name, ver: '', stat: __.t('common.not_configured'), key: 'common.not_configured', cls: 'off', state: 'off' });
            } else {
                // 独立 CI（custom_push 等）
                items.push({ icon: '✅', name: name, ver: '', stat: __.t('common.enabled'), key: 'common.enabled', cls: 'ok', state: 'ok' });
            }
        }

        if (items.length === 0) {
            if (dot) dot.className = 'dot dot-off';
            rows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">⚪</span><span class="svc-name">' + __.t('monitor.build_platform') + '</span><span class="svc-stat off" data-i18n="monitor.build_no_ci">' + __.t('monitor.build_no_ci') + '</span></div>';
            return;
        }

        if (dot) dot.className = items.every(i => i.state === 'ok') ? 'dot dot-ok' : 'dot dot-err';
        rows.innerHTML = items.map(i =>
            '<div class="svc-row child">' +
                '<span class="svc-icon">' + i.icon + '</span>' +
                '<span class="svc-name">' + esc(i.name) + (i.ver ? '<span class="svc-ver">' + esc(i.ver) + '</span>' : '') + '</span>' +
                '<span class="svc-stat ' + i.cls + '" data-i18n="' + i.key + '">' + i.stat + '</span>' +
            '</div>'
        ).join('');
    }

    const staticReq = fetch(HEALTH_STATIC_API, { headers: authHeaders() });
    const probeReq  = fetch(HEALTH_API, { headers: authHeaders() });

    let staticData = null;

    try {
        const res = await staticReq;
        if (handle401(res)) return;
        const data = await res.json();
        staticData = data;
        await __.ready();
        const st = data.stats || {};

        document.getElementById('stat-total').textContent = st.total_maps ?? '—';
        document.getElementById('stat-active').textContent = st.active_maps ?? '—';
        document.getElementById('stat-platforms').textContent = st.git_platforms ?? '—';
        document.getElementById('stat-repos').textContent = st.harbor_repos ?? '—';

        // DB 驱动展示：优先原始驱动名，缺失时回退到未知的翻译文案（__.t 签名只有 (key, params)，不支持默认值第三参）
        const bmLabel = (Array.isArray(data.build_modes) && data.build_modes.length > 0)
            ? data.build_modes.map(m => {
                switch (m) {
                    case 'jenkins':   return __.t('build.mode_jenkins');
                    case 'gitlab_ci': return __.t('build.mode_gitlab_ci');
                    case 'gitea_ci':  return __.t('build.mode_gitea_ci');
                    case 'github_actions': return __.t('build.mode_github_actions');
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
        if (sysDbType)    sysDbType.textContent    = data.db_driver ? data.db_driver : __.t('common.unknown');
        if (sysAppVer)    sysAppVer.textContent    = data.app_version ? 'v' + data.app_version : '—';
        if (sysEnvType)   sysEnvType.textContent   = data.app_env || '—';
        if (sysTime)      sysTime.textContent      = (data.time ? String(data.time).substring(0, 16) : '—');

        applyBuildRecordsMenuVisibility(data.custom_push_enabled || false);
    } catch (e) {}

    try {
        const res = await probeReq;
        if (handle401(res)) return;
        const data = await res.json();
        const chk = data.checks || {};

        renderBuildPlatforms(staticData, chk);

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
        const buildRows = document.getElementById('build-rows');
        const dotBuild = document.getElementById('dot-build');
        if (dotBuild) dotBuild.className = 'dot dot-err';
        if (buildRows) buildRows.innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">' + __.t('monitor.build_platform') + '</span><span class="svc-stat err" data-i18n="' + ek + '">' + msg + '</span></div>';
        document.getElementById('dot-git').className = 'dot dot-err';
        document.getElementById('git-rows').innerHTML = '<div class="svc-row parent"><span class="svc-icon">❌</span><span class="svc-name">' + __.t('monitor.git_platforms') + '</span><span class="svc-stat err" data-i18n="' + ek + '">' + msg + '</span></div>';
        setSvc('icon-harbor', 'name-harbor', 'stat-harbor', 'dot-harbor', false, '', msg, '', ek);
    }
}