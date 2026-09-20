import { authHeaders, handle401 } from '../core/api.js';
import { esc, truncateUrl } from '../core/utils.js';
import { platforms } from '../core/state.js';

const MAP_LIST_API = '/api/main/map/list';

let topoEntries = [];
let topoPage = 1;
let topoTotalPages = 1;
const TOPO_PER_PAGE = 10;
let topoPlatformUrls = {};

export function getTopoPage() { return topoPage; }
export function setTopoPage(v) { topoPage = v; }
export function getTopoTotalPages() { return topoTotalPages; }

export async function loadTopology() {
    const loading = document.getElementById('topo-loading');
    loading.style.display = 'block';
    loading.innerHTML = '<p style="font-size:15px;">⏳ ' + __.t('common.loading') + '</p>';
    try {
        const res = await fetch(MAP_LIST_API, { headers: authHeaders() });
        if (handle401(res)) return;
        const data = await res.json();
        topoPlatformUrls = {
            jenkins_url: (data.jenkins_url || '').replace(/\/+$/, ''),
            harbor_url:  (data.harbor_url || '').replace(/\/+$/, ''),
        };
        processTopoData(data);
    } catch(e) {
        loading.innerHTML = '<p style="color:#dc2626;">⚠️ ' + __.t('js.network_error') + '</p><p style="font-size:13px;color:#9ca3af;margin-top:6px;">' + __.t('js.load_failed') + ': ' + esc(e.message) + '</p>';
    }
}

function processTopoData(data) {
    const loading = document.getElementById('topo-loading');
    const empty   = document.getElementById('topo-empty');
    if (data && data.code) {
        loading.innerHTML = `<p style="color:#dc2626;">⚠️ ${esc(data.message || '')}</p><p style="font-size:13px;color:#9ca3af;margin-top:4px;">HTTP ${esc(data.code)}</p>`;
        return;
    }
    const projects = data.projects || data.data || data;
    topoEntries = Array.isArray(projects) ? projects : Object.entries(projects).map(([k,v]) => ({project:k, ...v}));
    topoPage = 1;
    if (topoEntries.length === 0) {
        loading.style.display = 'none';
        empty.style.display = 'block';
        return;
    }
    renderTopology();
}

export function renderTopology() {
    const loading = document.getElementById('topo-loading');
    const grid    = document.getElementById('topo-grid');
    const empty   = document.getElementById('topo-empty');
    const pagination = document.getElementById('topo-pagination');

    loading.style.display = 'none';
    grid.style.display = 'block';
    empty.style.display = 'none';

    const total = topoEntries.length;
    topoTotalPages = Math.max(1, Math.ceil(total / TOPO_PER_PAGE));
    if (topoPage > topoTotalPages) topoPage = topoTotalPages;
    const offset = (topoPage - 1) * TOPO_PER_PAGE;
    const slice  = topoEntries.slice(offset, offset + TOPO_PER_PAGE);

    grid.innerHTML = slice.map((p) => {
        const platform = p.git_platform || '—';
        const source   = p.platform_source || '';
        const method   = p.detection_method || '';
        let detectBadge = '';
        if (source === 'manual') detectBadge = '<span class="badge" style="background:#fef3c7;color:#d97706;">' + __.t('js.topo_manual') + '</span>';
        else if (method === 'fallback') detectBadge = '<span class="badge" style="background:#fef2f2;color:#dc2626;">' + __.t('js.topo_fallback') + '</span>';
        else if (method === 'exact') detectBadge = '<span class="badge" style="background:#ecfdf5;color:#065f46;">' + __.t('js.topo_exact') + '</span>';

        const gitUrl = p.git_remote || '';
        const gitDisplay = gitUrl
            ? `<a href="${esc(gitUrl)}" target="_blank" title="${esc(gitUrl)}">${esc(truncateUrl(gitUrl))}</a>`
            : '<span class="topo-empty-field">' + __.t('js.topo_not_configured') + '</span>';

        const harbor = p.harbor_repository || '';
        const harborUrl = topoPlatformUrls.harbor_url || '';
        const harborDisplay = harbor
            ? `<a href="${esc(harborUrl + '/harbor')}" target="_blank" title="${esc(__.t('js.topo_open_harbor'))}">${esc(harbor)}</a>`
            : '<span class="topo-empty-field">' + __.t('js.topo_not_linked') + '</span>';

        const build = p.build_provider || 'jenkins';
        const buildLabel = build === 'jenkins' ? __.t('build.mode_jenkins')
            : build === 'gitlab_ci' ? __.t('build.mode_gitlab_ci')
            : build === 'gitea_ci' ? __.t('build.mode_gitea_ci')
            : build === 'custom_push' ? 'Custom_Push'
            : build;
        const buildIcon = build === 'gitlab_ci' ? '🐺' : build === 'gitea_ci' ? '🦎' : build === 'custom_push' ? '📤' : '⚡';
        const buildUrl = topoPlatformUrls.jenkins_url || '';
        const projectPath = (p.project || p.current_path || '').replace(/\/+$/, '');
        const jenkinsPath = projectPath
            ? '/' + projectPath.split('/').map(s => 'job/' + encodeURIComponent(s)).join('/') + '/'
            : '';
        const buildDisplay = buildUrl
            ? `<a href="${esc(buildUrl + jenkinsPath)}" target="_blank" title="${esc(__.t('js.topo_open_jenkins'))}">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</a>`
            : `<span class="node-main">${esc(p.project || p.current_path || __.t('js.topo_unnamed'))}</span>`;
        const platformCls = platform !== '—' && platforms.includes(platform) ? 'badge-' + platform : 'badge-default';
        const buildBadgeCls = build === 'gitlab_ci' ? 'badge-gitlab' : build === 'gitea_ci' ? 'badge-gitea' : build === 'custom_push' ? 'badge-cus' : 'badge-default';

        return `<div class="topo-card">
            <div class="topo-header">
                <span class="topo-project">📦 ${esc(p.project || p.current_path || __.t('js.topo_unnamed_project'))}</span>
                <div class="topo-meta">
                    <span class="badge ${buildBadgeCls}">${buildLabel}</span>
                    <span class="badge ${platformCls}">${esc(platform)}</span>
                    ${detectBadge}
                </div>
            </div>
            <div class="topo-flow">
                <div class="topo-node">
                    <div class="node-label">🔗 ${__.t('js.topo_git_repo')}</div>
                    <div class="node-sub">${gitDisplay}</div>
                </div>
                <div class="topo-arrow">→</div>
                <div class="topo-node">
                    <div class="node-label">${buildIcon} ${__.t('js.topo_build_source')}</div>
                    <div class="node-main">${buildDisplay}</div>
                </div>
                <div class="topo-arrow">→</div>
                <div class="topo-node">
                    <div class="node-label">🐳 ${__.t('js.topo_harbor_image')}</div>
                    <div class="node-sub">${harborDisplay}</div>
                </div>
            </div>
        </div>`;
    }).join('');

    if (topoTotalPages <= 1) {
        pagination.style.display = 'none';
    } else {
        pagination.style.display = 'flex';
        let pagHtml = '<span style="color:#6b7280;">' + __.t('js.topo_total_items', {total: total}) + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="setTopoPage(1);renderTopology()" ' + (topoPage<=1?'disabled':'') + '>« ' + __.t('js.page_first') + '</button>';
        pagHtml += '<button class="btn btn-sm" onclick="setTopoPage(Math.max(1,getTopoPage()-1));renderTopology()" ' + (topoPage<=1?'disabled':'') + '>‹ ' + __.t('js.page_prev') + '</button>';
        pagHtml += '<span style="color:#374151;font-weight:600;">' + topoPage + ' / ' + topoTotalPages + '</span>';
        pagHtml += '<button class="btn btn-sm" onclick="setTopoPage(Math.min(getTopoTotalPages(),getTopoPage()+1));renderTopology()" ' + (topoPage>=topoTotalPages?'disabled':'') + '>' + __.t('js.page_next') + ' ›</button>';
        pagHtml += '<button class="btn btn-sm" onclick="setTopoPage(getTopoTotalPages());renderTopology()" ' + (topoPage>=topoTotalPages?'disabled':'') + '>' + __.t('js.page_last') + ' »</button>';
        pagination.innerHTML = pagHtml;
    }
}