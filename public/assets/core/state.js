// assets/core/state.js
export const PULL_PROVIDERS = ['jenkins', 'gitlab_ci', 'gitea_ci', 'github_actions'];

export let currentBuildModes = PULL_PROVIDERS.slice();
export let currentBuildAvailability = {};
export let currentCpEnabled = false;
export let currentStaleCleanupEnabled = false;
export let currentBackfillEnabled = false;
export let platforms = [];

export function setBuildModes(v) { currentBuildModes = v; }
export function setBuildAvailability(v) { currentBuildAvailability = v; }
export function setCpEnabled(v) { currentCpEnabled = v; }
export function setStaleCleanup(v) { currentStaleCleanupEnabled = v; }
export function setBackfill(v) { currentBackfillEnabled = v; }
export function setPlatforms(v) { platforms = v; }

export function isPullProvider(bp) { return PULL_PROVIDERS.includes(bp); }

export function pullProviderMeta(bp) {
    switch (bp) {
        case 'jenkins':    return { icon: '⚡', label: __.t('js.mode_jenkins_name'),   cls: 'jenkins' };
        case 'gitlab_ci':  return { icon: '🐺', label: __.t('js.mode_gitlab_ci_name'), cls: 'gitlab' };
        case 'gitea_ci':   return { icon: '🦎', label: __.t('js.mode_gitea_ci_name'),  cls: 'gitea' };
        case 'github_actions': return { icon: '🐙', label: __.t('js.mode_github_actions_name'), cls: 'github' };
        case 'custom_push':return { icon: '📤', label: __.t('js.mode_custom_push_name'), cls: 'custom-push' };
        // 空 = 映射未指定构建源：明确标注，避免被当成 jenkins（构建时会因未指定而被拒绝）
        default:           return bp ? { icon: '🔧', label: bp, cls: '' } : { icon: '❓', label: __.t('js.provider_unspecified'), cls: 'unknown' };
    }
}