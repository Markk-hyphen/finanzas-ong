import Swal from 'sweetalert2';
import { api } from '../api.js';
import { setNav } from '../nav.js';
import { esc } from '../html.js';

const POLL_MS    = 4000;
const POLL_LIMIT = 30; // ~2 minutes of auto-checking; after that the manual button still works

let pollTimer = null;

// Delivery channels for notifications (MANIFIESTO E3): mail (mandatory) and Telegram (opt-in)
export async function renderAccount(el) {
    setNav({ title: 'Mi cuenta', back: '/projects', logout: true });
    stopPolling();

    const channels = await api.getNotificationChannels();
    render(el, channels);
}

function render(el, channels, pendingLink = null) {
    el.innerHTML = `
        <div class="mt-3">
            <h6 class="fw-semibold">Avisos</h6>
            <p class="text-muted small">Te avisamos cuando, si no te enterás, podés perder plata o la chance de objetar un pago.</p>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="fw-semibold">Mail</div>
                    ${channels.email.enabled
                        ? `<div class="small">Los avisos te llegan a <strong>${esc(channels.email.address)}</strong>.</div>
                           <div class="small text-muted">Este canal no se puede desactivar.</div>`
                        : '<div class="small text-muted">Esta instancia no tiene configurado el envío de mails.</div>'}
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="fw-semibold">Telegram</div>
                    ${telegramBody(channels.telegram, pendingLink)}
                </div>
            </div>
        </div>
    `;

    el.querySelector('#btn-tg-link')?.addEventListener('click', async () => {
        const { link } = await api.linkTelegram();
        render(el, channels, link);
        startPolling(el);
    });

    el.querySelector('#btn-tg-check')?.addEventListener('click', () => check(el, true));

    el.querySelector('#btn-tg-unlink')?.addEventListener('click', async () => {
        const res = await Swal.fire({
            title: '¿Desvincular Telegram?',
            text: 'Vas a dejar de recibir los avisos por Telegram (el mail sigue).',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Desvincular',
            cancelButtonText: 'Cancelar',
        });
        if (res.isConfirmed) render(el, await api.unlinkTelegram());
    });
}

function telegramBody(tg, pendingLink) {
    if (!tg.enabled) {
        return '<div class="small text-muted">Esta instancia no ofrece avisos por Telegram.</div>';
    }
    if (tg.linked) {
        return `<div class="small text-success mb-2">Vinculado: los avisos también te llegan por Telegram.</div>
                <button id="btn-tg-unlink" class="btn btn-sm btn-outline-danger">Desvincular</button>`;
    }
    if (pendingLink) {
        return `<ol class="small ps-3 mb-2">
                    <li>Abrí el bot y apretá <strong>Iniciar / Start</strong>.</li>
                    <li>Volvé acá: se confirma solo en unos segundos.</li>
                </ol>
                <a href="${esc(pendingLink)}" target="_blank" rel="noopener" class="btn btn-sm btn-primary me-2">Abrir Telegram</a>
                <button id="btn-tg-check" class="btn btn-sm btn-outline-secondary">Ya apreté Start</button>
                <div class="small text-muted mt-2">El link vence en 15 minutos.</div>`;
    }
    return `<div class="small mb-2">Opcional: recibí los mismos avisos también por Telegram.</div>
            <button id="btn-tg-link" class="btn btn-sm btn-primary">Vincular Telegram</button>`;
}

async function check(el, manual = false) {
    try {
        const channels = await api.checkTelegram();
        if (channels.telegram.linked) {
            stopPolling();
            render(el, channels);
        } else if (manual) {
            Swal.fire({ icon: 'info', text: 'Todavía no vemos tu Start. Esperá unos segundos y probá de nuevo.' });
        }
    } catch (e) {
        if (manual) Swal.fire({ icon: 'error', text: e.message });
    }
}

function startPolling(el) {
    stopPolling();
    let ticks = 0;
    pollTimer = setInterval(() => {
        if (location.hash !== '#/account' || ++ticks > POLL_LIMIT) {
            stopPolling();
            return;
        }
        check(el);
    }, POLL_MS);
}

function stopPolling() {
    clearInterval(pollTimer);
    pollTimer = null;
}
