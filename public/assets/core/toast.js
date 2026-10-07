// assets/core/toast.js
let _toastTimer = null;

/**
 * @param {string} msg  文案
 * @param {boolean} ok  true=绿色（成功），false=红色（错误）
 * @param {boolean} center 居中显示
 * @param {number} [duration=2500] 自动消失毫秒数；0 = 常驻（由下一个 toast 替换）
 */
export function toast(msg, ok, center, duration = 2500) {
    const el = document.getElementById('toast');
    // 先取消上一个 toast 的自灭定时器，否则旧定时器会把新提示一起关掉
    if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
    el.textContent = msg;
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err') + ' show' + (center ? ' toast-center' : '');
    if (duration > 0) _toastTimer = setTimeout(() => el.classList.remove('show'), duration);
}

let _confirmResolver = null;

export function confirmDialog(opts = {}) {
    return new Promise(function(resolve) {
        _confirmResolver = resolve;
        document.getElementById('confirm-title').textContent = opts.title || __.t('common.confirm');
        document.getElementById('confirm-message').textContent = opts.message || '';
        const noteEl = document.getElementById('confirm-note');
        const noteText = opts.note || '';
        if (noteEl) {
            noteEl.textContent = noteText;
            noteEl.style.display = noteText ? 'block' : 'none';
        }
        const okBtn = document.getElementById('confirm-ok-btn');
        okBtn.textContent = opts.confirmText || __.t('common.confirm');
        document.getElementById('confirm-modal').style.display = 'flex';
        setTimeout(() => okBtn.focus(), 30);
    });
}

export function resolveConfirm(ok) {
    if (!_confirmResolver) return;
    const r = _confirmResolver;
    _confirmResolver = null;
    document.getElementById('confirm-modal').style.display = 'none';
    r(!!ok);
}

document.addEventListener('keydown', function(e) {
    if (!_confirmResolver) return;
    if (document.getElementById('confirm-modal').style.display === 'none') return;
    if (e.key === 'Escape') { e.preventDefault(); resolveConfirm(false); }
    else if (e.key === 'Enter') { e.preventDefault(); resolveConfirm(true); }
});