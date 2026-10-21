import { api, storage } from '../api.js';
import { setNav } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import { avatar } from '../ui/format.js';
import { confirmDialog, toast, withLoading } from '../ui/feedback.js';
import { getTheme, logout, setTheme } from '../shell.js';

const POLL_MS    = 4000;
const POLL_LIMIT = 30; // ~2 minutes of auto-checking; after that the manual button still works

let pollTimer = null;
let onThemeChange = null;

// Profile, appearance and delivery channels for notifications (MANIFIESTO E3):
// mail (mandatory) and Telegram (opt-in)
export async function renderAccount(el) {
    setNav({ title: 'Mi cuenta', back: '/projects', crumbs: [{ label: 'Mi cuenta' }] });
    stopPolling();

    const channels = await api.getNotificationChannels();
    const user = storage.getUser();

    el.innerHTML = `
        <div class="form-page narrow">
            <header class="page-head">
                <div><h1 class="page-title">Mi cuenta</h1></div>
            </header>

            <section class="card profile-card">
                ${avatar(user?.name, user?.id, 'xl')}
                <div class="profile-who">
                    <div class="profile-name">${esc(user?.name ?? '')}</div>
                    <div class="muted small">${esc(user?.email ?? '')}</div>
                    <div class="profile-chips">
                        ${storage.isAdmin() ? `<span class="chip chip-brand">${icon('shield')} Administrador</span>` : `<span class="chip">${icon('user')} Socio</span>`}
                    </div>
                </div>
            </section>

            <section class="card settings-section">
                <div class="card-header"><h2 class="card-title">Apariencia</h2></div>
                <div class="card-body">
                    <div class="segmented block" role="group" aria-label="Tema" id="theme-picker">
                        <button type="button" data-theme-opt="auto">${icon('monitor')} Automático</button>
                        <button type="button" data-theme-opt="light">${icon('sun')} Claro</button>
                        <button type="button" data-theme-opt="dark">${icon('moon')} Oscuro</button>
                    </div>
                    <p class="hint" style="margin-top:.6rem">“Automático” sigue la configuración de tu dispositivo.</p>
                </div>
            </section>

            <section class="card settings-section">
                <div class="card-header"><h2 class="card-title">Avisos</h2></div>
                <p class="card-desc">Te avisamos cuando, si no te enterás, podés perder plata o la chance de objetar un pago.</p>
                <div id="channels"></div>
            </section>

            <button class="btn btn-danger-soft btn-lg btn-block" id="btn-logout">${icon('logOut')} Cerrar sesión</button>
        </div>
    `;

    const syncTheme = () => el.querySelectorAll('[data-theme-opt]').forEach(b =>
        b.setAttribute('aria-pressed', String(b.dataset.themeOpt === getTheme())));
    el.querySelectorAll('[data-theme-opt]').forEach(b => b.addEventListener('click', () => {
        setTheme(b.dataset.themeOpt);
        syncTheme();
    }));
    syncTheme();
    window.removeEventListener('themechange', onThemeChange);
    onThemeChange = syncTheme;
    window.addEventListener('themechange', onThemeChange);

    el.querySelector('#btn-logout').addEventListener('click', logout);

    renderChannels(el.querySelector('#channels'), channels);
}

function renderChannels(host, channels, pendingLink = null) {
    const email = channels.email;
    host.innerHTML = `
        <ul class="list channels">
            <li class="list-item with-icon">
                <span class="mv-icon expense">${icon('mail')}</span>
                <div class="main">
                    <div class="title">Mail ${email.enabled ? `<span class="chip chip-pos chip-dot">Activo</span>` : '<span class="chip">No disponible</span>'}</div>
                    <div class="sub">${email.enabled
                        ? `Llegan a&nbsp;<strong>${esc(email.address)}</strong>`
                        : 'Esta instancia no tiene configurado el envío de mails.'}</div>
                    ${email.enabled ? '<div class="hint" style="margin-top:.25rem">Es el canal obligatorio: no se puede desactivar.</div>' : ''}
                </div>
            </li>
            <li class="list-item with-icon tg-item">
                <span class="mv-icon telegram">${icon('send')}</span>
                <div class="main">
                    ${telegramBody(channels.telegram, pendingLink)}
                </div>
            </li>
        </ul>`;

    host.querySelector('#btn-tg-link')?.addEventListener('click', async (e) => {
        try {
            const { link } = await withLoading(e.currentTarget, () => api.linkTelegram());
            renderChannels(host, channels, link);
            startPolling(host);
        } catch (ex) {
            toast('No se pudo generar el link', { text: ex.message, type: 'error' });
        }
    });

    host.querySelector('#btn-tg-check')?.addEventListener('click', (e) => withLoading(e.currentTarget, () => check(host, true)));

    host.querySelector('#btn-tg-unlink')?.addEventListener('click', async () => {
        const ok = await confirmDialog({
            title: '¿Desvincular Telegram?',
            text: 'Vas a dejar de recibir los avisos por Telegram (el mail sigue).',
            tone: 'warn',
            iconName: 'unlink',
            confirmText: 'Desvincular',
        });
        if (!ok) return;
        try {
            renderChannels(host, await api.unlinkTelegram());
            toast('Telegram desvinculado');
        } catch (ex) {
            toast('No se pudo desvincular', { text: ex.message, type: 'error' });
        }
    });
}

function telegramBody(tg, pendingLink) {
    if (!tg.enabled) {
        return `<div class="title">Telegram <span class="chip">No disponible</span></div>
                <div class="sub">Esta instancia no ofrece avisos por Telegram.</div>`;
    }
    if (tg.linked) {
        return `<div class="title">Telegram <span class="chip chip-pos chip-dot">Vinculado</span></div>
                <div class="sub">Los avisos también te llegan por Telegram.</div>
                <div class="tg-actions"><button id="btn-tg-unlink" class="btn btn-secondary btn-sm">${icon('unlink')} Desvincular</button></div>`;
    }
    if (pendingLink) {
        return `<div class="title">Telegram <span class="chip chip-warn"><span class="pulse"></span> Esperando</span></div>
                <ol class="steps">
                    <li><span class="step-n">1</span> Abrí el bot y apretá <strong>Iniciar / Start</strong>.</li>
                    <li><span class="step-n">2</span> Volvé acá: se confirma solo en unos segundos.</li>
                </ol>
                <div class="tg-actions">
                    <a href="${esc(pendingLink)}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">${icon('external')} Abrir Telegram</a>
                    <button id="btn-tg-check" class="btn btn-secondary btn-sm">Ya apreté Start</button>
                </div>
                <div class="hint" style="margin-top:.5rem">El link vence en 15 minutos.</div>`;
    }
    return `<div class="title">Telegram <span class="chip">Opcional</span></div>
            <div class="sub">Recibí los mismos avisos también por Telegram.</div>
            <div class="tg-actions"><button id="btn-tg-link" class="btn btn-soft btn-sm">${icon('link')} Vincular Telegram</button></div>`;
}

async function check(host, manual = false) {
    try {
        const channels = await api.checkTelegram();
        if (channels.telegram.linked) {
            stopPolling();
            renderChannels(host, channels);
            toast('Telegram vinculado', { text: 'Desde ahora los avisos te llegan también por ahí.' });
        } else if (manual) {
            toast('Todavía no vemos tu Start', { text: 'Esperá unos segundos y probá de nuevo.', type: 'info' });
        }
    } catch (e) {
        if (manual) toast('Error', { text: e.message, type: 'error' });
    }
}

function startPolling(host) {
    stopPolling();
    let ticks = 0;
    pollTimer = setInterval(() => {
        if (location.hash !== '#/account' || ++ticks > POLL_LIMIT) {
            stopPolling();
            return;
        }
        check(host);
    }, POLL_MS);
}

function stopPolling() {
    clearInterval(pollTimer);
    pollTimer = null;
}
