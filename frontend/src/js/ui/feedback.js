import { esc } from '../html.js';
import { icon } from './icons.js';

// ───────────── Toasts ─────────────

const TOAST_ICONS = { success: 'checkCircle', error: 'xCircle', warning: 'alert', info: 'info' };

export function toast(title, { text = '', type = 'success', duration = 3200 } = {}) {
    const host = document.getElementById('toasts');
    if (!host) return;
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `
        ${icon(TOAST_ICONS[type] ?? 'info')}
        <div>
            <div class="toast-title">${esc(title)}</div>
            ${text ? `<div class="toast-text">${esc(text)}</div>` : ''}
        </div>`;
    host.appendChild(el);
    const close = () => {
        el.classList.add('leaving');
        el.addEventListener('animationend', () => el.remove(), { once: true });
    };
    const t = setTimeout(close, duration);
    el.addEventListener('click', () => { clearTimeout(t); close(); });
}

// ───────────── Dialog ─────────────

/**
 * Modal confirmation (bottom sheet on phones). Resolves to:
 *   - false when dismissed
 *   - true when confirmed (no input)
 *   - the trimmed input string when `input` is set
 */
export function confirmDialog({
    title,
    text = '',
    tone = 'default',            // default | danger | warn
    iconName = null,
    confirmText = 'Confirmar',
    cancelText = 'Cancelar',
    input = null,                // { label, placeholder, required }
}) {
    return new Promise(resolve => {
        const dlg = document.createElement('dialog');
        dlg.className = 'dialog';
        const glyph = iconName ?? (tone === 'danger' ? 'alert' : tone === 'warn' ? 'alert' : 'info');
        dlg.innerHTML = `
            <form method="dialog">
                <div class="dialog-body">
                    <div class="dialog-icon ${tone}">${icon(glyph)}</div>
                    <h2 class="dialog-title">${esc(title)}</h2>
                    ${text ? `<p class="dialog-text">${esc(text)}</p>` : ''}
                    ${input ? `
                    <div class="field">
                        <label class="label" for="dlg-input">${esc(input.label ?? '')}
                            ${input.required ? '' : '<span class="opt">opcional</span>'}</label>
                        <input id="dlg-input" class="input" type="text" autocomplete="off" placeholder="${esc(input.placeholder ?? '')}">
                        <div class="dialog-error" aria-live="polite"></div>
                    </div>` : ''}
                </div>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-secondary" value="cancel">${esc(cancelText)}</button>
                    <button type="submit" class="btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}" value="ok">${esc(confirmText)}</button>
                </div>
            </form>`;
        document.body.appendChild(dlg);

        let result = false;
        const field = dlg.querySelector('#dlg-input');
        const err = dlg.querySelector('.dialog-error');

        dlg.querySelector('[value="cancel"]').addEventListener('click', () => dlg.close());
        dlg.querySelector('form').addEventListener('submit', e => {
            if (field) {
                const v = field.value.trim();
                if (input.required && !v) {
                    e.preventDefault();
                    err.textContent = 'Este campo es obligatorio.';
                    field.setAttribute('aria-invalid', 'true');
                    field.focus();
                    return;
                }
                result = v;
            } else {
                result = true;
            }
        });
        // Click on the backdrop dismisses
        dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
        dlg.addEventListener('close', () => {
            dlg.remove();
            resolve(result);
        });

        dlg.showModal();
        (field ?? dlg.querySelector('[value="ok"]')).focus();
    });
}

// ───────────── Popover menu ─────────────

let openMenu = null;

export function closeMenu() {
    openMenu?.();
    openMenu = null;
}

/**
 * Small action menu anchored to a button. items: [{ label, icon, danger, onClick } | 'sep']
 */
export function showMenu(anchor, items, { title = '' } = {}) {
    closeMenu();
    const menu = document.createElement('div');
    menu.className = 'menu';
    menu.setAttribute('role', 'menu');
    menu.innerHTML = (title ? `<div class="menu-title">${esc(title)}</div>` : '') + items.map((it, i) => it === 'sep'
        ? '<div class="menu-sep"></div>'
        : `<button type="button" role="menuitem" class="menu-item ${it.danger ? 'danger' : ''}" data-i="${i}">${it.icon ? icon(it.icon) : ''}<span>${esc(it.label)}</span></button>`
    ).join('');
    document.body.appendChild(menu);

    // Position: below the anchor, right-aligned, flipped up if no room
    const r = anchor.getBoundingClientRect();
    const mw = menu.offsetWidth, mh = menu.offsetHeight;
    let left = Math.min(window.innerWidth - mw - 8, Math.max(8, r.right - mw));
    let top = r.bottom + 6;
    if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';

    const onDoc = e => { if (!menu.contains(e.target) && e.target !== anchor && !anchor.contains(e.target)) closeMenu(); };
    const onKey = e => {
        if (e.key === 'Escape') { closeMenu(); anchor.focus(); }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const btns = [...menu.querySelectorAll('.menu-item')];
            const idx = btns.indexOf(document.activeElement);
            btns[(idx + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length]?.focus();
        }
    };
    setTimeout(() => document.addEventListener('pointerdown', onDoc), 0);
    document.addEventListener('keydown', onKey);
    // Ignore the scroll a tap itself may cause; close on real scrolling only
    setTimeout(() => window.addEventListener('scroll', closeMenu, { once: true, passive: true }), 250);

    menu.querySelectorAll('.menu-item').forEach(btn => btn.addEventListener('click', () => {
        const it = items[Number(btn.dataset.i)];
        closeMenu();
        it.onClick?.();
    }));

    openMenu = () => {
        document.removeEventListener('pointerdown', onDoc);
        document.removeEventListener('keydown', onKey);
        menu.remove();
        anchor.setAttribute('aria-expanded', 'false');
    };
    anchor.setAttribute('aria-expanded', 'true');
    menu.querySelector('.menu-item')?.focus({ preventScroll: true });
}

// ───────────── Buttons in flight ─────────────

export async function withLoading(btn, fn) {
    btn.disabled = true;
    btn.classList.add('is-loading');
    try {
        return await fn();
    } finally {
        if (btn.isConnected) {
            btn.disabled = false;
            btn.classList.remove('is-loading');
        }
    }
}
