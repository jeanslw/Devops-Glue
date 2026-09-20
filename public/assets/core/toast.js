// assets/core/toast.js
export function toast(msg, ok, center) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err') + ' show' + (center ? ' toast-center' : '');
    setTimeout(() => el.classList.remove('show'), 2500);
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