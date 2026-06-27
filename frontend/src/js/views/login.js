import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';

export async function renderLogin(el) {
    setNav({ title: 'Finanzas ONG' });

    el.innerHTML = `
        <div class="row justify-content-center mt-4">
          <div class="col-12 col-sm-8 col-md-5">
            <div class="card shadow-sm border-0">
              <div class="card-body p-4">
                <h5 class="fw-semibold mb-4">Iniciar sesión</h5>
                <form id="login-form">
                  <div class="mb-3">
                    <label class="form-label small">Email o usuario</label>
                    <input type="text" id="f-login" class="form-control" autocomplete="username" required />
                  </div>
                  <div class="mb-4">
                    <label class="form-label small">Contraseña</label>
                    <input type="password" id="f-pass" class="form-control" autocomplete="current-password" required />
                  </div>
                  <div id="login-err" class="alert alert-danger d-none small py-2 mb-3"></div>
                  <button type="submit" id="login-btn" class="btn btn-primary w-100">Entrar</button>
                </form>
              </div>
            </div>
          </div>
        </div>
    `;

    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const err = document.getElementById('login-err');
        err.classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Entrando…';

        try {
            const { token } = await api.login(
                document.getElementById('f-login').value,
                document.getElementById('f-pass').value,
            );
            storage.setToken(token);
            const user = await api.me();
            storage.setUser(user);
            navigate('/projects');
        } catch (ex) {
            err.textContent = ex.message;
            err.classList.remove('d-none');
            btn.disabled = false;
            btn.textContent = 'Entrar';
        }
    });
}
