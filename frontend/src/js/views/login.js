import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { icon } from '../ui/icons.js';
import { loadProjects } from '../shell.js';

export async function renderLogin(el) {
    setNav({ title: 'Finanzas ONG' });

    el.innerHTML = `
        <div class="login">
            <section class="login-aside" aria-hidden="true">
                <div class="brand login-brand">
                    <span class="brand-mark">${icon('logo')}</span>
                    <span>Finanzas ONG</span>
                </div>
                <div class="login-pitch">
                    <h2>Las cuentas del grupo, claras para todos.</h2>
                    <p>Cada gasto se reparte según la participación de cada socio. Cada pago queda registrado y confirmado. Sin planillas, sin dudas.</p>
                </div>
                <div class="login-preview">
                    <div class="lp-card">
                        <div class="lp-label">Mi balance</div>
                        <div class="lp-amount">$ 19.850<span>,25</span></div>
                        <div class="lp-chip">${icon('arrowDownLeft')} Te deben</div>
                    </div>
                    <div class="lp-row"><span class="lp-av" style="--hue:160">LP</span><span class="lp-name">Lucía</span><span class="lp-bar"><i style="width:28%"></i></span><span class="lp-neg">− $ 3.930</span></div>
                    <div class="lp-row"><span class="lp-av" style="--hue:20">TP</span><span class="lp-name">Tomás</span><span class="lp-bar"><i style="width:78%"></i></span><span class="lp-neg">− $ 15.920</span></div>
                </div>
                <ul class="login-points">
                    <li>${icon('scale')} Repartos proporcionales automáticos</li>
                    <li>${icon('shieldCheck')} Historial inmutable: nada se borra</li>
                    <li>${icon('bell')} Avisos por mail y Telegram</li>
                </ul>
            </section>

            <section class="login-main">
                <div class="login-box">
                    <div class="brand login-brand-mobile">
                        <span class="brand-mark">${icon('logo')}</span>
                        <span>Finanzas ONG</span>
                    </div>
                    <h1 class="login-title">Hola de nuevo</h1>
                    <p class="muted login-sub">Entrá con tu usuario o tu email.</p>

                    <form id="login-form" novalidate>
                        <div class="field">
                            <label class="label" for="f-login">Email o usuario</label>
                            <input type="text" id="f-login" class="input" autocomplete="username" autocapitalize="none" spellcheck="false" required />
                        </div>
                        <div class="field">
                            <label class="label" for="f-pass">Contraseña</label>
                            <div class="input-group">
                                <input type="password" id="f-pass" class="input" autocomplete="current-password" required />
                                <button type="button" class="icon-btn sm input-action" id="toggle-pass" aria-label="Mostrar contraseña">${icon('eye')}</button>
                            </div>
                        </div>
                        <div id="login-err" class="callout callout-neg form-error hidden" role="alert" style="margin-top:1rem"></div>
                        <button type="submit" id="login-btn" class="btn btn-primary btn-lg btn-block" style="margin-top:1.5rem">Entrar</button>
                    </form>
                </div>
            </section>
        </div>
    `;

    const pass = document.getElementById('f-pass');
    const toggle = document.getElementById('toggle-pass');
    toggle.addEventListener('click', () => {
        const show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        toggle.innerHTML = icon(show ? 'eyeOff' : 'eye');
        toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        pass.focus();
    });

    document.getElementById('f-login').focus();

    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const err = document.getElementById('login-err');
        const login = document.getElementById('f-login');
        err.classList.add('hidden');

        if (!login.value.trim() || !pass.value) {
            showError(err, 'Completá usuario y contraseña.');
            (login.value.trim() ? pass : login).focus();
            return;
        }

        btn.disabled = true;
        btn.classList.add('is-loading');

        try {
            const { token } = await api.login(login.value, pass.value);
            storage.setToken(token);
            const user = await api.me();
            storage.setUser(user);
            loadProjects(true).catch(() => {});
            navigate('/projects');
        } catch (ex) {
            showError(err, ex.message);
            btn.disabled = false;
            btn.classList.remove('is-loading');
            pass.select();
        }
    });
}

function showError(err, message) {
    err.innerHTML = `${icon('alert')}<span></span>`;
    err.querySelector('span').textContent = message;
    err.classList.remove('hidden');
    // Restart the shake animation on repeated failures
    err.style.animation = 'none';
    void err.offsetWidth;
    err.style.animation = '';
}
