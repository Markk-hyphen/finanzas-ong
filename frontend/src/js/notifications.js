import { api, storage } from './api.js';
import { esc } from './html.js';

const LABELS = {
    payment_created_optimistic: 'Tenés un pago nuevo (ya confirmado) — podés rechazarlo si no te llegó.',
    payment_created_auto_timeout: 'Tenés un pago pendiente: si no lo rechazás antes del plazo, se confirma solo.',
    payment_auto_confirmed:    'Un pago pendiente se confirmó solo: venció el plazo sin objeciones.',
    payment_withdrawn:          'El pagador retiró un pago pendiente que esperabas.',
    payment_rejected:           'Tu pago fue rechazado por el receptor.',
};

const POLL_MS = 30000;

let eventSource = null;
let pollTimer   = null;
let started     = false;

// Called on every route change (cheap: no-ops once already connected for this
// session). Only (re)connects when the login state actually flips.
export function initNotifications() {
    const btn   = document.getElementById('btn-notifications');
    const panel = document.getElementById('notifications-panel');
    if (!btn || !panel) return;

    if (!storage.getToken()) {
        if (started) stopNotifications();
        btn.classList.add('d-none');
        return;
    }

    btn.classList.remove('d-none');
    btn.onclick = () => togglePanel(panel);

    if (started) return;
    started = true;

    refresh(panel);
    pollTimer = setInterval(() => refresh(panel), POLL_MS);
    connectLive(panel);
}

export function stopNotifications() {
    clearInterval(pollTimer);
    pollTimer = null;
    eventSource?.close();
    eventSource = null;
    started = false;
}

async function connectLive(panel) {
    try {
        const { token } = await api.getNotificationsToken();
        eventSource?.close();
        const url = new URL('/.well-known/mercure', window.location.origin);
        url.searchParams.append('authorization', token);
        eventSource = new EventSource(url);
        eventSource.onmessage = () => refresh(panel);
        eventSource.onerror = () => { eventSource?.close(); eventSource = null; };
    } catch {
        // No live push (e.g. logged out mid-request); polling still covers it.
    }
}

async function refresh(panel) {
    if (!storage.getToken()) return;
    try {
        const { unreadCount, notifications } = await api.getNotifications();
        updateBadge(unreadCount);
        if (!panel.classList.contains('d-none')) {
            renderPanel(panel, notifications);
        }
    } catch {
        // Transient failure; next poll retries.
    }
}

function updateBadge(count) {
    const badge = document.getElementById('notifications-badge');
    if (!badge) return;
    badge.textContent = count > 9 ? '9+' : String(count);
    badge.classList.toggle('d-none', count === 0);
}

function togglePanel(panel) {
    const hidden = panel.classList.contains('d-none');
    panel.classList.toggle('d-none', !hidden);
    if (hidden) refresh(panel);
}

function renderPanel(panel, notifications) {
    if (notifications.length === 0) {
        panel.innerHTML = '<div class="p-3 text-muted small">Sin notificaciones.</div>';
        return;
    }

    panel.innerHTML = notifications.map(n => `
        <div class="notification-item ${n.read ? '' : 'notification-unread'} p-2 border-bottom small" data-id="${n.id}">
            <div>${esc(LABELS[n.eventType] ?? n.eventType)}</div>
            <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="text-muted">${new Date(n.createdAt).toLocaleString()}</span>
                ${n.read ? '' : '<button class="btn btn-sm btn-link p-0 notification-mark-read">Marcar leída</button>'}
            </div>
        </div>
    `).join('');

    panel.querySelectorAll('.notification-mark-read').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const id = e.target.closest('.notification-item').dataset.id;
            await api.markNotificationRead(id);
            refresh(panel);
        });
    });
}
