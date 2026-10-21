import Swal from 'sweetalert2';
import { api } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';

const MODES = [
    {
        value: 'required',
        label: 'Confirmación obligatoria',
        help:  'El pago no cuenta hasta que quien lo recibe lo confirma.',
    },
    {
        value: 'optimistic',
        label: 'Sin confirmación',
        help:  'El pago cuenta apenas se registra; quien lo recibe puede rechazarlo dentro del plazo de objeción.',
    },
    {
        value: 'auto_timeout',
        label: 'Autoconfirmación',
        help:  'El pago queda pendiente y se confirma solo si nadie lo objeta dentro del plazo.',
    },
];

// Admin-only: confirmation mode and windows of a project (MANIFIESTO E1)
export async function renderSettings(el, projectId) {
    setNav({ title: 'Configuración', back: `/project/${projectId}` });

    const [overview, caps] = await Promise.all([api.getOverview(projectId), api.getCapabilities()]);
    const s = overview.settings;
    // auto_timeout depends on this instance running the scheduler; keep it
    // selectable only if available or already in use.
    const autoTimeoutBlocked = !caps.autoTimeout && s.confirmationMode !== 'auto_timeout';

    el.innerHTML = `
        <form id="settings-form" class="mt-3">
            <p class="text-muted small mb-3">${esc(overview.project.name)}</p>

            <div class="mb-3">
                <label class="form-label fw-semibold">Modo de confirmación de pagos</label>
                ${MODES.map(m => {
                    const disabled = m.value === 'auto_timeout' && autoTimeoutBlocked;
                    return `
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="mode" id="mode-${m.value}" value="${m.value}"
                               ${s.confirmationMode === m.value ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                        <label class="form-check-label ${disabled ? 'text-muted' : ''}" for="mode-${m.value}">
                            ${m.label}
                            <div class="text-muted small">${m.help}</div>
                            ${disabled ? '<div class="small text-danger">No es posible configurar auto timeout en esta instancia (no hay un scheduler activo).</div>' : ''}
                        </label>
                    </div>`;
                }).join('')}
                ${s.confirmationMode === 'auto_timeout' && !caps.autoTimeout
                    ? '<div class="alert alert-warning small py-2">Este proyecto usa autoconfirmación pero el scheduler de esta instancia no está corriendo: los pagos esperan confirmación manual.</div>'
                    : ''}
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="f-objection">Plazo de objeción (horas)</label>
                <input type="number" id="f-objection" class="form-control" min="1" max="720" step="1" value="${esc(s.objectionWindowHours)}" required>
                <div class="form-text">Cuánto tiempo tiene quien recibe un pago para rechazarlo (sin confirmación) o hasta que se confirma solo (autoconfirmación).</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" for="f-self-cancel">Ventana para deshacer lo propio (minutos)</label>
                <input type="number" id="f-self-cancel" class="form-control" min="0" max="1440" step="1" value="${esc(s.selfCancelMinutes)}" required>
                <div class="form-text">Cuánto tiempo tiene cada socio para deshacer un gasto que cargó mal. 0 lo desactiva.</div>
            </div>

            <div id="settings-err" class="alert alert-danger d-none small py-2 mb-3"></div>
            <button type="submit" id="settings-btn" class="btn btn-primary w-100 py-2">Guardar</button>
        </form>
    `;

    document.getElementById('settings-form').addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('settings-btn');
        const err = document.getElementById('settings-err');
        err.classList.add('d-none');
        btn.disabled = true;

        try {
            await api.updateSettings(projectId, {
                confirmationMode:     document.querySelector('input[name="mode"]:checked').value,
                objectionWindowHours: parseInt(document.getElementById('f-objection').value, 10),
                selfCancelMinutes:    parseInt(document.getElementById('f-self-cancel').value, 10),
            });
            await Swal.fire({ icon: 'success', title: 'Configuración guardada', timer: 1200, showConfirmButton: false });
            navigate(`/project/${projectId}`);
        } catch (ex) {
            err.textContent = ex.message;
            err.classList.remove('d-none');
            btn.disabled = false;
        }
    });
}
