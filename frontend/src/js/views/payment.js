import Swal from 'sweetalert2';
import { api } from '../api.js';
import { setNav, navigate } from '../nav.js';

function fmt(amount) {
    return '$ ' + Number(amount).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export async function renderPayment(el, projectId) {
    setNav({ title: 'Registrar pago', back: `/project/${projectId}` });

    let previewData = null;

    el.innerHTML = `
        <form id="payment-form" class="mt-3">
            <div class="mb-3">
                <label class="form-label fw-semibold">Monto a pagar <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" id="f-amount" class="form-control form-control-lg"
                           placeholder="0.00" min="0.01" step="0.01" required inputmode="decimal" />
                </div>
            </div>

            <div id="preview-section" class="d-none mb-3">
                <div class="card border-0 bg-light">
                    <div class="card-body py-2 px-3">
                        <div class="small fw-semibold mb-2 text-muted">Distribución del pago</div>
                        <ul id="allocation-list" class="mb-0 ps-3 small"></ul>
                    </div>
                </div>
            </div>

            <div id="payment-err" class="alert alert-danger d-none small py-2 mb-3"></div>

            <div class="d-grid gap-2">
                <button type="button" id="btn-preview" class="btn btn-outline-primary py-2">Ver cómo se distribuye</button>
                <button type="submit"  id="btn-confirm" class="btn btn-primary py-2 d-none">Confirmar pago</button>
            </div>
        </form>
    `;

    document.getElementById('btn-preview').addEventListener('click', async () => {
        const amount = document.getElementById('f-amount').value;
        const err    = document.getElementById('payment-err');
        err.classList.add('d-none');

        if (!amount || Number(amount) <= 0) {
            err.textContent = 'Ingresá un monto válido.';
            err.classList.remove('d-none');
            return;
        }

        const btn = document.getElementById('btn-preview');
        btn.disabled = true;
        btn.textContent = 'Calculando…';

        try {
            previewData = await api.previewPayment(projectId, { amount });

            const list = document.getElementById('allocation-list');
            list.innerHTML = previewData.allocations.map(a =>
                `<li>${a.toMemberName}: <strong>${fmt(a.amount)}</strong></li>`
            ).join('');

            document.getElementById('preview-section').classList.remove('d-none');
            document.getElementById('btn-confirm').classList.remove('d-none');
            btn.textContent = 'Recalcular';
        } catch (ex) {
            err.textContent = ex.message;
            err.classList.remove('d-none');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('payment-form').addEventListener('submit', async e => {
        e.preventDefault();
        if (!previewData) return;

        const btn = document.getElementById('btn-confirm');
        const err = document.getElementById('payment-err');
        err.classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Registrando…';

        try {
            await api.createPayment(projectId, { amount: document.getElementById('f-amount').value });
            await Swal.fire({
                icon: 'success',
                title: 'Pago registrado',
                text: 'Está pendiente de confirmación por los receptores.',
                timer: 2500,
                showConfirmButton: false,
            });
            navigate(`/project/${projectId}`);
        } catch (ex) {
            err.textContent = ex.message;
            err.classList.remove('d-none');
            btn.disabled = false;
            btn.textContent = 'Confirmar pago';
        }
    });
}
