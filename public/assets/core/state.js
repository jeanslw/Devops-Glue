// assets/core/state.js
export const PULL_PROVIDERS = ['jenkins', 'gitlab_ci', 'gitea_ci'];

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
        case 'jenkins':   return { icon: '⚡', label: __.t('js.mode_jenkins_name'),   cls: 'jenkins' };
        case 'gitlab_ci': return { icon: '🐺', label: __.t('js.mode_gitlab_ci_name'), cls: 'gitlab' };
        case 'gitea_ci':  return { icon: '🦎', label: __.t('js.mode_gitea_ci_name'),  cls: 'gitea' };
        default:          return { icon: '🔧', label: bp, cls: '' };
    }
}