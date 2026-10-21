import { api, storage } from './api.js';
import { esc } from './html.js';
import { icon } from './ui/icons.js';
import { timeAgo } from './ui/format.js';

// Text + icon tone per domain event
const EVENTS = {
    payment_created_optimistic:   { text: 'Tenés un pago nuevo (ya confirmado). Podés rechazarlo si no te llegó.', icon: 'arrowDownLeft', tone: 'in' },
    payment_created_auto_timeout: { text: 'Tenés un pago pendiente: si no lo rechazás antes del plazo, se confirma solo.', icon: 'hourglass', tone: 'neutral' },
    payment_auto_confirmed:       { text: 'Un pago pendiente se confirmó solo: venció el plazo sin objeciones.', icon: 'checkCircle', tone: 'in' },
    payment_withdrawn:            { text: 'El pagador retiró un pago pendiente que esperabas.', icon: 'undo', tone: 'neutral' },
    payment_rejected:             { text: 'Tu pago fue rechazado por el receptor.', icon: 'xCircle', tone: 'out' },
};

const POLL_MS = 30000;

let eventSource = null;
let pollTimer   = null;
let started     = false;
let latest      = [];

// Called on every route change (cheap: no-ops once already connected for this
// session). Only (re)connects when the login state actually flips.
export function initNotifications() {
    const btn   = document.getElementById('btn-notifications');
    const panel = document.getElementById('notifications-panel');
    if (!btn || !panel) return;

    if (!storage.getToken()) {
        if (started) stopNotifications();
        btn.classList.add('hidden');
        return;
    }

    btn.classList.remove('hidden');
    btn.onclick = (e) => { e.stopPropagation(); togglePanel(panel); };

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
    closePanel();
}

async function connectLive(panel) {
    try {
        const { token } = await api.getNotificationsToken();
        eventSource?.close();
        const url = new URL('/.well-known/mercure', window.location.origin);
        url.searchParams.append('authorization', token);
        // The hub rejects a subscription without topics (400); the ones this
        // member may read are the token's own `subscribe` claim.
        for (const topic of subscribeTopics(token)) url.searchParams.append('topic', topic);
        eventSource = new EventSource(url);
        eventSource.onmessage = () => refresh(panel);
        eventSource.onerror = () => { eventSource?.close(); eventSource = null; };
    } catch {
        // No live push (e.g. logged out mid-request); polling still covers it.
    }
}

function subscribeTopics(jwt) {
    try {
        const payload = JSON.parse(atob(jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
        return payload.mercure?.subscribe ?? [];
    } catch {
        return [];
    }
}

async function refresh(panel) {
    if (!storage.getToken()) return;
    try {
        const { unreadCount, notifications } = await api.getNotifications();
        latest = notifications;
        updateBadge(unreadCount);
        if (!panel.classList.contains('hidden')) {
            renderPanel(panel, notifications);
        }
    } catch {
        // Transient failure; next poll retries.
    }
}

function updateBadge(count) {
    const badge = document.getElementById('notifications-badge');
    const btn   = document.getElementById('btn-notifications');
    if (!badge) return;
    const text = count > 9 ? '9+' : String(count);
    if (badge.textContent !== text || badge.classList.contains('hidden') !== (count === 0)) {
        badge.textContent = text;
        badge.classList.toggle('hidden', count === 0);
        // Re-trigger the pop animation when the count changes
        badge.style.animation = 'none';
        void badge.offsetWidth;
        badge.style.animation = '';
    }
    btn?.setAttribute('aria-label', count ? `Notificaciones (${count} sin leer)` : 'Notificaciones');
}

let backdrop = null;

export function closePanel() {
    document.getElementById('notifications-panel')?.classList.add('hidden');
    document.getElementById('btn-notifications')?.setAttribute('aria-expanded', 'false');
    backdrop?.remove();
    backdrop = null;
    document.removeEventListener('keydown', onKey);
}

function onKey(e) {
    if (e.key === 'Escape') closePanel();
}

function togglePanel(panel) {
    if (!panel.classList.contains('hidden')) {
        closePanel();
        return;
    }
    backdrop = document.createElement('div');
    backdrop.className = 'backdrop dim';
    backdrop.addEventListener('click', closePanel);
    document.body.appendChild(backdrop);
    panel.classList.remove('hidden');
    document.getElementById('btn-notifications')?.setAttribute('aria-expanded', 'true');
    document.addEventListener('keydown', onKey);
    renderPanel(panel, latest);
    refresh(panel);
}

function renderPanel(panel, notifications) {
    const unread = notifications.filter(n => !n.read);

    panel.innerHTML = `
        <div class="notif-head">
            <h2>Notificaciones</h2>
            ${unread.length ? `<button class="btn btn-ghost btn-sm" id="notif-read-all">${icon('checkCheck')} Marcar todas</button>` : ''}
        </div>
        <div class="notif-list">
            ${notifications.length === 0 ? `
                <div class="empty">
                    <div class="empty-icon">${icon('inbox')}</div>
                    <div class="empty-title">Todo al día</div>
                    <div class="empty-text">Acá te avisamos cuando haya pagos para revisar o cambios que te afecten.</div>
                </div>` : notifications.map(n => {
                    const ev = EVENTS[n.eventType] ?? { text: n.eventType, icon: 'bell', tone: 'neutral' };
                    return `
                    <button class="notif ${n.read ? '' : 'unread'}" data-id="${esc(n.id)}" data-project="${esc(n.projectId ?? '')}">
                        <span class="mv-icon ${ev.tone}">${icon(ev.icon)}</span>
                        <span>
                            <div class="notif-text">${esc(ev.text)}</div>
                            <div class="notif-time">${esc(timeAgo(n.createdAt))}</div>
                        </span>
                    </button>`;
                }).join('')}
        </div>`;

    panel.querySelector('#notif-read-all')?.addEventListener('click', async (e) => {
        e.currentTarget.disabled = true;
        await Promise.allSettled(unread.map(n => api.markNotificationRead(n.id)));
        refresh(panel);
    });

    panel.querySelectorAll('.notif').forEach(item => {
        item.addEventListener('click', async () => {
            const { id, project } = item.dataset;
            if (item.classList.contains('unread')) {
                item.classList.remove('unread');
                api.markNotificationRead(id).then(() => refresh(panel)).catch(() => {});
            }
            if (project) {
                closePanel();
                location.hash = `#/project/${project}`;
            }
        });
    });
}
