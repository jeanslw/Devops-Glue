import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import {
    PULL_PROVIDERS,
    currentBuildModes, setBuildModes,
    currentBuildAvailability, setBuildAvailability,
    currentCpEnabled, setCpEnabled,
    currentStaleCleanupEnabled, setStaleCleanup,
    pullProviderMeta
} from '../core/state.js';

export function renderBuildModeCheckboxes(availability, selected) {
    const box = document.getElementById('build-mode-checkboxes');
    if (!box) return;
    const availablePull = PULL_PROVIDERS.filter(bp => availability[bp]);
    if (availablePull.length === 0) {
        box.innerHTML = '<span style="color:#9ca3af;font-size:13px;">' + __.t('js.no_pull_ci') + '</span>';
        return;
    }
    let html = '';
    availablePull.forEach(bp => {
        const meta = pullProviderMeta(bp);
        const checked = selected.includes(bp) ? ' checked' : '';
        html += '<label class="bm-check"><input type="checkbox" class="bm-item" value="' + bp + '"' + checked + ' onchange="onBuildModesChange()"><span>' + meta.icon + ' ' + esc(meta.label) + '</span></label>';
    });
    box.innerHTML = html;
}

function getCheckedBuildModes() {
    return Array.from(document.querySelectorAll('#build-mode-checkboxes .bm-item:checked')).map(cb => cb.value);
}

export async function loadSettings() {
    const display = document.getElementById('mode-display');
    const configPanel = document.getElementById('mode-config');
    const cpToggle = document.getElementById('custom-push-toggle');
    const statusEl = document.getElementById('build-mode-status');
    try {
        const res = await fetch('/api/admin/build_mode', { headers: authHeaders() });
        if (res.status === 401) { display.innerHTML = '<span style="color:#9ca3af;">' + __.t('auth.please_login_first') + '</span>'; return; }
        const data = await res.json();
        const modes = Array.isArray(data.modes) ? data.modes : [];
        const availability = {
            jenkins:   !!data.has_jenkins,
            gitlab_ci: !!data.has_gitlab_ci,
            gitea_ci:  !!data.has_gitea_ci,
        };
        const hasCustom = (data.custom_providers || []).length > 0;
        const cpEnabled = data.custom_push_enabled || false;
        const staleCleanup = data.stale_tag_cleanup_enabled || false;
        const customNames = data.custom_providers || [];
        const source = data.source || 'env';

        setBuildModes(modes.slice());
        setBuildAvailability(availability);
        setCpEnabled(cpEnabled);
        setStaleCleanup(staleCleanup);

        const srcLabel = source === 'database'
            ? '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_persisted') + '">✓ ' + __.t('js.mode_persisted') + '</span>'
            : '<span class="badge" style="background:#fefce8;color:#ca8a04;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_temp') + '">⚠️ ' + __.t('js.mode_temp') + '</span>';

        renderBuildModeCheckboxes(availability, modes);
        if (cpToggle) cpToggle.checked = cpEnabled;
        const staleToggle = document.getElementById('stale-tag-cleanup-toggle');
        if (staleToggle) staleToggle.checked = staleCleanup;

        if (window.applyBuildRecordsMenuVisibility) window.applyBuildRecordsMenuVisibility(cpEnabled);
        configPanel.style.display = 'block';
        statusEl.style.display = 'none';

        let modeBadge = '', flow = '';
        const node = (cls, icon, label, fontSize) =>
            '<div class="mf-node ' + cls + '">'
            + '<div class="mf-node-icon" style="font-size:' + (fontSize || 24) + 'px;">' + icon + '</div>'
            + '<div class="mf-node-name">' + label + '</div>'
            + '</div>';
        const arrow = '<div class="mf-arrow">→</div>';
        const split = '<div class="mf-arrow">/</div>';
        const customBadge = (cpEnabled && hasCustom)
            ? '<span class="badge" style="background:#fef3c7;color:#d97706;font-size:11px;margin-left:6px;">📤 ' + customNames.join(', ') + ' ✓</span>'
            : '';

        const enabledPull = PULL_PROVIDERS.filter(bp => availability[bp] && modes.includes(bp));
        const hasPull = enabledPull.length > 0;
        let pullInner = '';
        if (hasPull) {
            modeBadge = '<span class="badge" style="background:#dbeafe;color:#1d4ed8;font-size:13px;">'
                + enabledPull.map(bp => pullProviderMeta(bp).icon + ' ' + pullProviderMeta(bp).label).join(' + ')
                + ' ' + __.t('js.mode_mode') + '</span>';
            const ciNodes = enabledPull.map(bp => {
                const m = pullProviderMeta(bp);
                return node(m.cls, m.icon, m.label);
            }).join(split);
            pullInner = node('git', '🌿', __.t('js.mode_git_repo'), 22)
                + arrow
                + ciNodes
                + arrow
                + node('harbor', '🐳', __.t('js.mode_harbor_name'));
        }

        const hasPush = cpEnabled && hasCustom;
        let pushInner = '';
        if (hasPush) {
            pushInner = node('user-ci', '📤', __.t('js.mode_user_ci'))
                + arrow
                + node('harbor', '🐳', __.t('js.mode_harbor_name'))
                + arrow
                + node('glue', '📋', 'Devops-Glue');
        }

        const side = (title, desc, inner, cls) =>
            '<div class="mf-side' + (cls ? ' ' + cls : '') + '">'
            + '<div class="mf-side-title">' + title + '</div>'
            + '<div class="mf-side-desc">' + desc + '</div>'
            + '<div class="mf-flow">' + inner + '</div>'
            + '</div>';

        if (hasPull && hasPush) {
            flow = '<div class="mf-row">'
                + side(__.t('js.mode_pull_title'), __.t('js.mode_pull_desc'), pullInner, 'mf-side--pull')
                + '<div class="mf-divider"></div>'
                + side(__.t('js.mode_push_title'), __.t('js.mode_push_desc'), pushInner, 'mf-side--push')
                + '</div>';
        } else if (hasPull) {
            flow = '<div class="mf-row mf-row--single">'
                + side(__.t('js.mode_pull_title'), __.t('js.mode_pull_desc'), pullInner)
                + '</div>';
        } else if (hasPush) {
            flow = '<div class="mf-row mf-row--single">'
                + side(__.t('js.mode_push_title'), __.t('js.mode_push_desc'), pushInner)
                + '</div>';
        }

        display.innerHTML = '<div class="mf-badges">' + modeBadge + srcLabel + customBadge + '</div>' + flow;
        if (window.updateDiscoverButton) window.updateDiscoverButton();
    } catch(e) {
        display.innerHTML = '<span style="color:#9ca3af;">' + __.t('js.mode_cannot_detect') + '</span>';
    }
}

function arraysEqual(a, b) {
    if (a.length !== b.length) return false;
    const sa = a.slice().sort(), sb = b.slice().sort();
    return sa.every((v, i) => v === sb[i]);
}

export async function onBuildModesChange() {
    const newModes = getCheckedBuildModes();
    const oldModes = currentBuildModes.slice();
    if (arraysEqual(newModes, oldModes)) return;

    const modeLabel = newModes.length > 0
        ? newModes.map(bp => pullProviderMeta(bp).label).join(' + ')
        : __.t('js.mode_none');

    if (!await confirmDialog({
        title: __.t('build.mode_label'),
        message: __.t('js.mode_switch_confirm', {mode: modeLabel}),
        note: __.t('js.mode_switch_note')
    })) {
        renderBuildModeCheckboxes(currentBuildAvailability, oldModes);
        return;
    }

    const statusEl = document.getElementById('build-mode-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({modes: newModes, custom_push_enabled: currentCpEnabled, stale_tag_cleanup_enabled: currentStaleCleanupEnabled})
        });
        if (handle401(res)) { renderBuildModeCheckboxes(currentBuildAvailability, oldModes); return; }
        if (res.ok) {
            setBuildModes(newModes.slice());
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
            loadSettings();
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            renderBuildModeCheckboxes(currentBuildAvailability, oldModes);
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        renderBuildModeCheckboxes(currentBuildAvailability, oldModes);
    }
}

export async function onCustomPushToggle() {
    const cpToggle = document.getElementById('custom-push-toggle');
    const newEnabled = cpToggle.checked;
    const oldEnabled = currentCpEnabled;

    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: newEnabled ? __.t('js.custom_push_enable_confirm') : __.t('js.custom_push_disable_confirm'),
        note: __.t('js.custom_push_toggle_note')
    })) { cpToggle.checked = oldEnabled; return; }

    const statusEl = document.getElementById('custom-push-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({modes: currentBuildModes, custom_push_enabled: newEnabled, stale_tag_cleanup_enabled: currentStaleCleanupEnabled})
        });
        if (handle401(res)) { cpToggle.checked = oldEnabled; return; }
        if (res.ok) {
            setCpEnabled(newEnabled);
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
            loadSettings();
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            cpToggle.checked = oldEnabled;
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        cpToggle.checked = oldEnabled;
    }
}

export async function onStaleTagCleanupToggle() {
    const staleToggle = document.getElementById('stale-tag-cleanup-toggle');
    const newEnabled = staleToggle.checked;
    const oldEnabled = currentStaleCleanupEnabled;

    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: newEnabled ? __.t('js.stale_tag_cleanup_enable_confirm') : __.t('js.stale_tag_cleanup_disable_confirm'),
        note: __.t('js.stale_tag_cleanup_note')
    })) { staleToggle.checked = oldEnabled; return; }

    const statusEl = document.getElementById('stale-tag-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/build_mode', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({modes: currentBuildModes, custom_push_enabled: currentCpEnabled, stale_tag_cleanup_enabled: newEnabled})
        });
        if (handle401(res)) { staleToggle.checked = oldEnabled; return; }
        if (res.ok) {
            setStaleCleanup(newEnabled);
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            staleToggle.checked = oldEnabled;
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        staleToggle.checked = oldEnabled;
    }
}