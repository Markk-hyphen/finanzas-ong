import Swal from 'sweetalert2';
import { api } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';

export async function renderExpense(el, projectId) {
    setNav({ title: 'Registrar gasto', back: `/project/${projectId}` });

    el.innerHTML = `
        <form id="expense-form" class="mt-3">
            <div class="mb-3">
                <label class="form-label fw-semibold">Monto <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" id="f-amount" class="form-control form-control-lg"
                           placeholder="0.00" min="0.01" step="0.01" required inputmode="decimal" />
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Material <span class="text-muted fw-normal small">(opcional)</span>
                </label>
                <div class="autocomplete-wrapper">
                    <input type="text" id="f-material" class="form-control"
                           placeholder="Buscar material…" autocomplete="off" />
                    <div id="material-suggestions" class="autocomplete-suggestions list-group shadow-sm d-none"></div>
                </div>
                <input type="hidden" id="f-material-id" />
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">
                    Cantidad <span class="text-muted fw-normal small">(opcional)</span>
                </label>
                <input type="number" id="f-qty" class="form-control"
                       placeholder="Ej: 2.5" min="0" step="0.001" inputmode="decimal" />
            </div>

            <div id="expense-err" class="alert alert-danger d-none small py-2 mb-3"></div>
            <button type="submit" id="expense-btn" class="btn btn-primary w-100 py-2">Registrar gasto</button>
        </form>
    `;

    // Autocomplete
    let matTimer = null;
    document.getElementById('f-material').addEventListener('input', e => {
        clearTimeout(matTimer);
        const q = e.target.value.trim();
        document.getElementById('f-material-id').value = '';
        const sug = document.getElementById('material-suggestions');
        if (q.length < 2) { sug.classList.add('d-none'); return; }
        matTimer = setTimeout(async () => {
            try {
                const results = await api.getMaterials(q);
                if (!results.length) { sug.classList.add('d-none'); return; }
                sug.innerHTML = results.map(m =>
                    `<button type="button" class="list-group-item list-group-item-action small py-2"
                             data-id="${esc(m.id)}" data-name="${esc(m.name)}">${esc(m.name)}</button>`
                ).join('');
                sug.classList.remove('d-none');
                sug.querySelectorAll('button').forEach(b => {
                    b.addEventListener('click', () => {
                        document.getElementById('f-material').value    = b.dataset.name;
                        document.getElementById('f-material-id').value = b.dataset.id;
                        sug.classList.add('d-none');
                    });
                });
            } catch {}
        }, 300);
    });

    // Submit
    document.getElementById('expense-form').addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('expense-btn');
        const err = document.getElementById('expense-err');
        err.classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Registrando…';

        const body = { amount: document.getElementById('f-amount').value };
        const matId = document.getElementById('f-material-id').value;
        const qty   = document.getElementById('f-qty').value;
        if (matId) body.materialId = parseInt(matId);
        if (qty)   body.quantity   = qty;

        try {
            const res = await api.createExpense(projectId, body);
            if (res.warning) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Gasto registrado',
                    text: res.warning,
                    confirmButtonText: 'Entendido',
                });
            } else {
                await Swal.fire({ icon: 'success', title: 'Gasto registrado', timer: 1200, showConfirmButton: false });
            }
            navigate(`/project/${projectId}`);
        } catch (ex) {
            err.textContent = ex.message;
            err.classList.remove('d-none');
            btn.disabled = false;
            btn.textContent = 'Registrar gasto';
        }
    });
}
