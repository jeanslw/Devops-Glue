import { authHeaders, handle401 } from '../core/api.js';
import { esc } from '../core/utils.js';
import { toast, confirmDialog } from '../core/toast.js';
import {
    PULL_PROVIDERS,
    currentBuildModes, setBuildModes,
    currentBuildAvailability, setBuildAvailability,
    currentCpEnabled, setCpEnabled,
    currentStaleCleanupEnabled, setStaleCleanup,
    currentBackfillEnabled, setBackfill,
    pullProviderMeta
} from '../core/state.js';

let currentApiLogCleanupEnabled = true;

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
        const customNames = data.custom_providers || [];
        const source = data.source || 'env';

        setBuildModes(modes.slice());
        setBuildAvailability(availability);
        setCpEnabled(cpEnabled);

        const srcLabel = source === 'database'
            ? '<span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_persisted') + '">✓ ' + __.t('js.mode_persisted') + '</span>'
            : '<span class="badge" style="background:#fefce8;color:#ca8a04;font-size:11px;margin-left:6px;" title="' + __.t('js.mode_temp') + '">⚠️ ' + __.t('js.mode_temp') + '</span>';

        renderBuildModeCheckboxes(availability, modes);
        if (cpToggle) cpToggle.checked = cpEnabled;

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
            ? '<span class="badge" style="background:#fef3c7;color:#d97706;font-size:11px;margin-left:6px;">📤 ' + customNames.map(esc).join(', ') + ' ✓</span>'
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
            body: JSON.stringify({modes: newModes, custom_push_enabled: currentCpEnabled})
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
            body: JSON.stringify({modes: currentBuildModes, custom_push_enabled: newEnabled})
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
        const res = await fetch('/api/admin/platform_config', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({stale_tag_cleanup_enabled: newEnabled})
        });
        if (handle401(res)) { staleToggle.checked = oldEnabled; return; }
        if (res.ok) {
            setStaleCleanup(newEnabled);
            syncTagRunButtons();
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

/**
 * 手动立即执行 tag 任务（清理 / 回填）的通用前端动作。
 * 共享：开关状态已在卡片渲染时同步，按钮 disabled 反映可执行性；
 * 执行前二次确认 → 禁用按钮 + 「执行中…」→ 成功后按结果填充状态文本。
 * opts: {btnId, statusId, url, confirmKey, noteKey, runningKey, labelKey, buildMessage, refreshAfter}
 */
async function runTagJob(opts) {
    const btn = document.getElementById(opts.btnId);
    const statusEl = document.getElementById(opts.statusId);
    if (!btn || btn.disabled) return;

    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: __.t(opts.confirmKey),
        note: __.t(opts.noteKey)
    })) return;

    btn.disabled = true;
    btn.textContent = __.t(opts.runningKey);
    if (statusEl) { statusEl.style.color = '#6b7280'; statusEl.style.display = 'inline'; statusEl.textContent = __.t(opts.runningKey); }

    try {
        const res = await fetch(opts.url, {
            method: 'POST',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders())
        });
        if (handle401(res)) return;
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
            if (statusEl) {
                statusEl.style.color = '#16a34a';
                statusEl.textContent = opts.buildMessage(data);
            }
            toast(opts.buildMessage(data));
            if (opts.refreshAfter) opts.refreshAfter();
        } else {
            // 开关未开启 / Harbor 未配置（409）等：错误文本就地展示，保持按钮可再点
            if (statusEl) {
                statusEl.style.color = '#dc2626';
                statusEl.textContent = data.message || __.t('js.save_failed');
            }
            toast(data.message || __.t('js.save_failed'), false);
        }
    } catch(e) {
        if (statusEl) { statusEl.style.color = '#dc2626'; statusEl.style.display = 'inline'; statusEl.textContent = __.t('js.network_error') + ': ' + e.message; }
        toast(__.t('js.network_error') + ': ' + e.message, false);
    } finally {
        // 结果文本保留在按钮右侧；按钮禁用状态交回开关状态决定（幂等，可重复执行）
        btn.textContent = __.t(opts.labelKey);
        syncTagRunButtons();
    }
}

/** 后端统计字段缺失/非数字时回退为 0（与全站 `|| 默认值` 风格保持一致，不使用 ??) */
function num(v) {
    const n = Number(v);
    return isFinite(n) ? n : 0;
}

/** 「立即清理一次」：POST /api/admin/tag_cleanup */
export async function runStaleTagCleanup() {
    await runTagJob({
        btnId: 'stale-tag-run-btn',
        statusId: 'stale-tag-run-status',
        url: '/api/admin/tag_cleanup',
        confirmKey: 'build.stale_tag_cleanup_run_confirm',
        noteKey: 'build.stale_tag_cleanup_run_note',
        runningKey: 'build.stale_tag_cleanup_running',
        labelKey: 'build.stale_tag_cleanup_run_btn',
        buildMessage: (d) => __.t('build.stale_tag_cleanup_done', {
            checked: num(d.checked), deleted: num(d.deleted),
            unreachable: num(d.unreachable), unverifiable: num(d.unverifiable)
        })
    });
}

/** 「立即回填一次」：POST /api/admin/tag_backfill */
export async function runTagBackfill() {
    await runTagJob({
        btnId: 'backfill-tag-run-btn',
        statusId: 'backfill-tag-run-status',
        url: '/api/admin/tag_backfill',
        confirmKey: 'build.tag_backfill_run_confirm',
        noteKey: 'build.tag_backfill_run_note',
        runningKey: 'build.tag_backfill_running',
        labelKey: 'build.tag_backfill_run_btn',
        buildMessage: (d) => __.t('build.tag_backfill_done', {
            checked: num(d.checked), promoted: num(d.promoted), skipped: num(d.skipped),
            unreachable: num(d.unreachable), unverifiable: num(d.unverifiable)
        })
    });
}

/**
 * 同步「立即清理一次 / 立即回填一次」两个按钮的可用性：
 * 仅当对应开关已开启时才允许手动执行（与后端 409 校验一致），
 * 关闭时按钮置灰并给出 title 提示，避免用户点了才被拒绝。
 */
export function syncTagRunButtons() {
    const map = [
        ['stale-tag-run-btn',    currentStaleCleanupEnabled, 'build.tag_cleanup_disabled'],
        ['backfill-tag-run-btn', currentBackfillEnabled, 'build.tag_backfill_disabled'],
    ];
    map.forEach(([id, enabled, disabledKey]) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.disabled = !enabled;
        if (enabled) btn.removeAttribute('title');
        else btn.setAttribute('title', __.t(disabledKey));
    });
}

export async function onTagLogKeywordChange() {
    const input = document.getElementById('tag-log-keyword');
    const statusEl = document.getElementById('tag-log-keyword-status');
    if (!input) return;
    const kw = input.value.trim();
    try {
        const res = await fetch('/api/admin/platform_config', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({tag_log_keyword: kw})
        });
        if (handle401(res)) return;
        if (res.ok) {
            const data = await res.json();
            input.value = data.tag_log_keyword || '';
            if (statusEl) {
                statusEl.style.display = 'inline';
                setTimeout(() => statusEl.style.display = 'none', 2000);
            }
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
    }
}

export async function onBackfillTagToggle() {
    const backfillToggle = document.getElementById('backfill-tag-toggle');
    const newEnabled = backfillToggle.checked;
    const oldEnabled = currentBackfillEnabled;

    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: newEnabled ? __.t('js.backfill_tag_enable_confirm') : __.t('js.backfill_tag_disable_confirm'),
        note: __.t('js.backfill_tag_note')
    })) { backfillToggle.checked = oldEnabled; return; }

    const statusEl = document.getElementById('backfill-tag-status');
    statusEl.style.display = 'none';
    try {
        const res = await fetch('/api/admin/platform_config', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({backfill_tag_enabled: newEnabled})
        });
        if (handle401(res)) { backfillToggle.checked = oldEnabled; return; }
        if (res.ok) {
            setBackfill(newEnabled);
            syncTagRunButtons();
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
        } else {
            const data = await res.json();
            toast(data.message || __.t('js.save_failed'), false);
            backfillToggle.checked = oldEnabled;
        }
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        backfillToggle.checked = oldEnabled;
    }
}

// 平台管理页填充 tag 相关设置（清理 / 回填 / 日志关键字），并同步共享 state。
export function applyTagSettings(data) {
    const staleToggle = document.getElementById('stale-tag-cleanup-toggle');
    const backfillToggle = document.getElementById('backfill-tag-toggle');
    const kwInput = document.getElementById('tag-log-keyword');
    if (staleToggle) staleToggle.checked = !!data.stale_tag_cleanup_enabled;
    if (backfillToggle) backfillToggle.checked = !!data.backfill_tag_enabled;
    if (kwInput) kwInput.value = data.tag_log_keyword || '';
    setStaleCleanup(!!data.stale_tag_cleanup_enabled);
    setBackfill(!!data.backfill_tag_enabled);
    syncTagRunButtons();
}

// ── 日志设置（API 调用日志 + 操作日志统一策略；部署日志暂不清理）──

// 平台管理页填充日志设置（写入级别 / 保留天数 / 清理开关）
export function applyApiLogSettings(data) {
    const levelSel = document.getElementById('apilog-set-level');
    const retainSel = document.getElementById('apilog-set-retain');
    const retainCustom = document.getElementById('apilog-set-retain-custom');
    if (levelSel && data.api_access_log_level) levelSel.value = data.api_access_log_level;
    const days = parseInt(data.api_access_log_retain_days, 10) || 90;
    if (retainSel) {
        if ([90, 60, 30].includes(days)) {
            retainSel.value = String(days);
            if (retainCustom) retainCustom.style.display = 'none';
        } else {
            retainSel.value = 'custom';
            if (retainCustom) { retainCustom.style.display = ''; retainCustom.value = days; }
        }
    }
    const cleanupToggle = document.getElementById('apilog-cleanup-toggle');
    if (cleanupToggle) {
        cleanupToggle.checked = data.api_access_log_cleanup_enabled !== false;
        currentApiLogCleanupEnabled = cleanupToggle.checked;
    }
}

// 保留天数下拉切换：选「自定义」只展开输入框等用户输入，其余档位直接保存
export function onApiLogRetainChange() {
    const retainSel = document.getElementById('apilog-set-retain');
    const retainCustom = document.getElementById('apilog-set-retain-custom');
    if (!retainSel || !retainCustom) return;
    if (retainSel.value === 'custom') {
        retainCustom.style.display = '';
        retainCustom.focus();
        return;
    }
    retainCustom.style.display = 'none';
    saveApiLogSettings();
}

// 定时清理开关：弹窗确认后再保存（与 tag 清理/回填开关行为一致）
export async function onApiLogCleanupToggle() {
    const cleanupToggle = document.getElementById('apilog-cleanup-toggle');
    const newEnabled = cleanupToggle.checked;
    const oldEnabled = currentApiLogCleanupEnabled;

    if (!await confirmDialog({
        title: __.t('common.confirm'),
        message: newEnabled ? __.t('js.apilog_cleanup_enable_confirm') : __.t('js.apilog_cleanup_disable_confirm'),
        note: __.t('js.apilog_cleanup_note')
    })) { cleanupToggle.checked = oldEnabled; return; }

    currentApiLogCleanupEnabled = newEnabled;
    const ok = await saveApiLogSettings();
    if (!ok) {
        currentApiLogCleanupEnabled = oldEnabled;
        cleanupToggle.checked = oldEnabled;
    }
}

export async function saveApiLogSettings() {
    const levelSel = document.getElementById('apilog-set-level');
    const retainSel = document.getElementById('apilog-set-retain');
    const retainCustom = document.getElementById('apilog-set-retain-custom');
    const statusEl = document.getElementById('apilog-settings-status');
    if (!levelSel || !retainSel) return;
    let days;
    if (retainSel.value === 'custom') {
        days = parseInt(retainCustom.value, 10);
        if (!days || days < 1 || days > 3650) { toast(__.t('apilog.retain_invalid'), false); return; }
    } else {
        days = parseInt(retainSel.value, 10);
    }
    const cleanupToggle = document.getElementById('apilog-cleanup-toggle');
    try {
        const res = await fetch('/api/admin/platform_config', {
            method: 'PUT',
            headers: Object.assign({'Content-Type':'application/json'}, authHeaders()),
            body: JSON.stringify({
                api_access_log_level: levelSel.value,
                api_access_log_retain_days: days,
                api_access_log_cleanup_enabled: cleanupToggle ? cleanupToggle.checked : true,
            })
        });
        if (handle401(res)) return false;
        const data = await res.json();
        if (!res.ok) { toast(data.message || __.t('js.save_failed'), false); return false; }
        applyApiLogSettings(data); // 回显服务端归一化后的值
        if (statusEl) {
            statusEl.textContent = '✅ ' + __.t('build.mode_saved');
            statusEl.style.display = 'inline';
            setTimeout(() => statusEl.style.display = 'none', 2000);
        }
        return true;
    } catch(e) {
        toast(__.t('js.network_error') + ': ' + e.message, false);
        return false;
    }
}