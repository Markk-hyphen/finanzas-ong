import { api } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import { toast } from '../ui/feedback.js';

const MODES = [
    {
        value: 'required',
        label: 'Confirmación obligatoria',
        help:  'El pago no cuenta hasta que quien lo recibe lo confirma.',
        icon:  'shieldCheck',
    },
    {
        value: 'optimistic',
        label: 'Sin confirmación',
        help:  'El pago cuenta apenas se registra; quien lo recibe puede rechazarlo dentro del plazo de objeción.',
        icon:  'zap',
    },
    {
        value: 'auto_timeout',
        label: 'Autoconfirmación',
        help:  'El pago queda pendiente y se confirma solo si nadie lo objeta dentro del plazo.',
        icon:  'hourglass',
    },
];

// Admin-only: confirmation mode and windows of a project (MANIFIESTO E1)
export async function renderSettings(el, projectId) {
    const [overview, caps] = await Promise.all([api.getOverview(projectId), api.getCapabilities()]);
    const s = overview.settings;
    const name = overview.project.name;
    setNav({
        title: 'Configuración',
        back: `/project/${projectId}`,
        crumbs: [{ label: 'Mis proyectos', href: '/projects' }, { label: name, href: `/project/${projectId}` }, { label: 'Configuración' }],
    });
    // auto_timeout depends on this instance running the scheduler; keep it
    // selectable only if available or already in use.
    const autoTimeoutBlocked = !caps.autoTimeout && s.confirmationMode !== 'auto_timeout';

    el.innerHTML = `
        <div class="form-page narrow">
            <header class="page-head">
                <div>
                    <h1 class="page-title">Configuración</h1>
                    <p class="page-sub">${esc(name)} · Cómo se confirman los pagos y cuánto margen hay para corregir.</p>
                </div>
            </header>

            <form id="settings-form" novalidate>
                <section class="card settings-section">
                    <div class="card-header"><h2 class="card-title">Modo de confirmación de pagos</h2></div>
                    <div class="card-body">
                        <div class="option-list" role="radiogroup">
                        ${MODES.map(m => {
                            const disabled = m.value === 'auto_timeout' && autoTimeoutBlocked;
                            return `
                            <label class="option">
                                <input type="radio" name="mode" value="${m.value}" ${s.confirmationMode === m.value ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                                <span class="option-icon">${icon(m.icon)}</span>
                                <span style="flex:1;min-width:0">
                                    <span class="option-title">${m.label}</span>
                                    <span class="option-help" style="display:block">${m.help}</span>
                                    ${disabled ? '<span class="option-note" style="display:block">No disponible en esta instancia: no hay un scheduler activo.</span>' : ''}
                                </span>
                                <span class="option-check"></span>
                            </label>`;
                        }).join('')}
                        </div>
                        ${s.confirmationMode === 'auto_timeout' && !caps.autoTimeout
                            ? `<div class="callout callout-warn" style="margin-top:1rem">${icon('alert')}<div>Este proyecto usa autoconfirmación pero el scheduler de esta instancia no está corriendo: los pagos esperan confirmación manual.</div></div>`
                            : ''}
                    </div>
                </section>

                <section class="card settings-section">
                    <div class="card-header"><h2 class="card-title">Plazos</h2></div>
                    <div class="card-body settings-windows">
                        <div class="field" id="objection-field">
                            <label class="label" for="f-objection">Plazo de objeción</label>
                            <div class="input-group">
                                <input type="number" id="f-objection" class="input" min="1" max="720" step="1" value="${esc(s.objectionWindowHours)}" required inputmode="numeric">
                                <span class="suffix">horas</span>
                            </div>
                            <div class="hint" id="objection-hint">Cuánto tiempo tiene quien recibe un pago para rechazarlo (sin confirmación) o hasta que se confirma solo (autoconfirmación). Entre 1 y 720.</div>
                        </div>
                        <div class="field">
                            <label class="label" for="f-self-cancel">Ventana para deshacer lo propio</label>
                            <div class="input-group">
                                <input type="number" id="f-self-cancel" class="input" min="0" max="1440" step="1" value="${esc(s.selfCancelMinutes)}" required inputmode="numeric">
                                <span class="suffix">minutos</span>
                            </div>
                            <div class="hint">Cuánto tiempo tiene cada socio para deshacer un gasto que cargó mal. 0 lo desactiva.</div>
                        </div>
                    </div>
                </section>

                <div id="settings-err" class="callout callout-neg form-error hidden" role="alert" style="margin-bottom:1rem"></div>

                <div class="settings-actions">
                    <a class="btn btn-ghost" href="#/project/${esc(projectId)}">Cancelar</a>
                    <button type="submit" id="settings-btn" class="btn btn-primary btn-lg">${icon('check')} Guardar cambios</button>
                </div>
            </form>
        </div>
    `;

    // The objection window only matters for the two non-required modes
    const syncObjection = () => {
        const mode = el.querySelector('input[name="mode"]:checked')?.value;
        el.querySelector('#objection-field').classList.toggle('is-muted', mode === 'required');
    };
    el.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', syncObjection));
    syncObjection();

    el.querySelector('#settings-form').addEventListener('submit', async e => {
        e.preventDefault();
        const btn = el.querySelector('#settings-btn');
        const err = el.querySelector('#settings-err');
        err.classList.add('hidden');
        btn.disabled = true;
        btn.classList.add('is-loading');

        try {
            await api.updateSettings(projectId, {
                confirmationMode:     el.querySelector('input[name="mode"]:checked').value,
                objectionWindowHours: parseInt(el.querySelector('#f-objection').value, 10),
                selfCancelMinutes:    parseInt(el.querySelector('#f-self-cancel').value, 10),
            });
            toast('Configuración guardada');
            navigate(`/project/${projectId}`);
        } catch (ex) {
            err.innerHTML = `${icon('alert')}<span></span>`;
            err.querySelector('span').textContent = ex.message;
            err.classList.remove('hidden');
            btn.disabled = false;
            btn.classList.remove('is-loading');
        }
    });
}
