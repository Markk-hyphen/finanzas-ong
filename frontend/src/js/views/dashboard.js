import Swal from 'sweetalert2';
import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';

function fmt(amount) {
    return '$ ' + Number(amount).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function balanceChip(balance) {
    const n = Number(balance);
    if (Math.abs(n) < 0.01) return '<span class="badge bg-success">Saldado</span>';
    if (n > 0) return `<span class="text-success fw-semibold">${fmt(balance)}</span><span class="text-muted small ms-1">te deben</span>`;
    return `<span class="text-danger fw-semibold">${fmt(Math.abs(n))}</span><span class="text-muted small ms-1">debés</span>`;
}

function fmtDate(iso) {
    return new Date(iso).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function fmtDateTime(iso) {
    return new Date(iso).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

const MODE_LABELS = {
    required:     'Confirmación obligatoria',
    optimistic:   'Pagos sin confirmación (se pueden rechazar)',
    auto_timeout: 'Autoconfirmación',
};

function modeLabel(settings) {
    if (!settings) return '';
    const base = MODE_LABELS[settings.confirmationMode] ?? esc(settings.confirmationMode);
    return settings.confirmationMode === 'required' ? base : `${base} · ${settings.objectionWindowHours} h`;
}

// Button label per action, as decided by the API (CancellationPolicy)
function actionLabel(action, m) {
    if (action === 'undo')     return 'Deshacer';
    if (action === 'withdraw') return m.status === 'pending' ? 'Retirar' : 'Deshacer';
    if (action === 'reject')   return 'Rechazar';
    return action;
}

function movementLine(m, userId) {
    if (m.type === 'expense') return 'Gasto';
    if (m.type !== 'payment') return esc(m.type);
    return m.fromMemberId === userId
        ? `Pago a ${esc(m.toMemberName)}`
        : `Pago de ${esc(m.fromMemberName)}`;
}

function statusBadge(m) {
    if (m.status === 'pending') {
        const when = m.autoConfirmAt ? ` · se confirma solo ${fmtDateTime(m.autoConfirmAt)}` : '';
        return `<span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">pendiente${when}</span>`;
    }
    if (m.confirmedVia === 'timeout') {
        return '<span class="badge bg-secondary ms-1" style="font-size:.65rem">autoconfirmado</span>';
    }
    return '';
}

export async function renderDashboard(el, projectId) {
    const user    = storage.getUser();
    const isAdmin = storage.isAdmin();

    const data = await api.getDashboard(projectId);
    setNav({ title: data.project.name, back: '/projects', logout: true });

    const myBalance    = data.balances.find(b => b.memberId === user?.id);
    const myBalanceVal = myBalance ? Number(myBalance.balance) : 0;
    // Debt still payable once unconfirmed payments are reserved
    const myProjected  = Number(data.myProjectedBalance ?? myBalanceVal);
    const hasPendingOut = myBalanceVal < 0 && myProjected > myBalanceVal;

    let heroBg = 'bg-success', heroLabel = 'Saldado', heroAmount = '';
    if (Math.abs(myBalanceVal) >= 0.01) {
        if (myBalanceVal > 0) {
            heroBg = 'bg-success'; heroLabel = 'Te deben'; heroAmount = fmt(myBalance.balance);
        } else {
            heroBg = 'bg-danger'; heroLabel = 'Debés'; heroAmount = fmt(Math.abs(myBalanceVal));
        }
    }

    el.innerHTML = `
        <!-- Mi balance -->
        <div class="card text-white ${heroBg} border-0 shadow-sm mb-3">
            <div class="card-body py-3 text-center">
                <div class="small opacity-75">Mi balance</div>
                <div class="fs-2 fw-bold mt-1">${heroAmount || '—'}</div>
                <div class="small opacity-75">${heroLabel}</div>
            </div>
        </div>

        <!-- Modo del proyecto -->
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">${modeLabel(data.settings)}</span>
            ${isAdmin ? '<button id="btn-settings" class="btn btn-link btn-sm p-0">Configurar</button>' : ''}
        </div>
        ${data.autoConfirmStalled ? `<div class="alert alert-warning small py-2 mb-3">La autoconfirmación está detenida en esta instancia (el scheduler no está corriendo). Los pagos pendientes se pueden confirmar a mano.</div>` : ''}

        <!-- Acciones -->
        <div class="d-flex gap-2 mb-3">
            <button id="btn-expense" class="btn btn-outline-primary flex-fill">+ Gasto</button>
            <button id="btn-payment" class="btn btn-outline-danger flex-fill" ${myProjected >= 0 ? 'disabled title="No tenés deuda pendiente de pago"' : ''}>Pagar</button>
        </div>
        ${hasPendingOut ? `<div class="alert alert-warning small py-2 mb-3">Tenés pagos esperando confirmación. Podés pagar hasta ${fmt(Math.max(0, -myProjected))} más.</div>` : ''}

        <!-- Pagos pendientes de confirmar -->
        <div id="pending-section" class="card border-0 shadow-sm mb-3 ${data.pendingConfirmations.length === 0 ? 'd-none' : ''}">
            <div class="card-header bg-white py-2 px-3">
                <span class="fw-semibold small text-warning">⚠ Pagos por confirmar</span>
            </div>
            <ul class="list-group list-group-flush" id="pending-list"></ul>
        </div>

        <!-- Balances del proyecto -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3">
                <span class="fw-semibold small">Balances · Costo total ${fmt(data.totalCost)}</span>
            </div>
            <ul class="list-group list-group-flush" id="balances-list"></ul>
        </div>

        <!-- Mis movimientos -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 px-3">
                <span class="fw-semibold small">Mis movimientos</span>
            </div>
            <ul class="list-group list-group-flush" id="movements-list"></ul>
        </div>
    `;

    // Balances
    const balancesList = document.getElementById('balances-list');
    for (const b of data.balances) {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center py-2';
        li.innerHTML = `
            <span class="small">${esc(b.name)}${b.memberId === user?.id ? ' <em class="text-muted">(yo)</em>' : ''}</span>
            <span>${balanceChip(b.balance)}</span>
        `;
        balancesList.appendChild(li);
    }

    // Mis movimientos
    const movList = document.getElementById('movements-list');
    if (data.movements.length === 0) {
        movList.innerHTML = '<li class="list-group-item text-muted small py-2 text-center">Sin movimientos</li>';
    } else {
        for (const m of data.movements) {
            const li = document.createElement('li');
            li.className = 'list-group-item py-2';
            // Pending shares to confirm already have their own buttons in "Pagos por confirmar"
            const actions = (m.actions ?? []).filter(a => !(a === 'reject' && m.status === 'pending'));
            const actionButtons = actions.map(a =>
                `<button class="btn btn-link btn-sm p-0 ms-2 text-danger user-action-btn" data-action="${esc(a)}" data-id="${esc(m.id)}" data-batch="${esc(m.batchId)}" style="font-size:.72rem">${actionLabel(a, m)}</button>`
            ).join('');
            li.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="small fw-semibold">${movementLine(m, user?.id)}</span>${statusBadge(m)}
                        ${m.material ? `<br><span class="text-muted small">${esc(m.material)}${m.quantity ? ' × ' + esc(m.quantity) : ''}</span>` : ''}
                        <br><span class="text-muted" style="font-size:.72rem">${fmtDate(m.createdAt)}</span>
                    </div>
                    <div class="text-end">
                        <span class="fw-semibold small text-nowrap">${fmt(m.amount)}</span>
                        <br>${actionButtons}
                        ${isAdmin ? `<button class="btn btn-link btn-sm p-0 ms-2 text-danger cancel-btn" data-id="${esc(m.id)}" style="font-size:.72rem">Anular</button>` : ''}
                    </div>
                </div>
            `;
            movList.appendChild(li);
        }
    }

    // Pendientes de confirmar
    const pendingList = document.getElementById('pending-list');
    for (const p of data.pendingConfirmations) {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center py-2';
        li.innerHTML = `
            <div class="small">
                <span class="fw-semibold">${fmt(p.amount)}</span> de ${esc(p.fromMemberName)}
                <br><span class="text-muted" style="font-size:.72rem">${fmtDate(p.createdAt)}${p.autoConfirmAt ? ` · se confirma solo ${fmtDateTime(p.autoConfirmAt)}` : ''}</span>
            </div>
            <div class="d-flex gap-1">
                ${(p.actions ?? []).includes('reject') ? `<button class="btn btn-outline-danger btn-sm user-action-btn" data-action="reject" data-batch="${esc(p.batchId)}">Rechazar</button>` : ''}
                <button class="btn btn-success btn-sm confirm-btn" data-batch="${esc(p.batchId)}">Confirmar</button>
            </div>
        `;
        pendingList.appendChild(li);
    }

    // Botones
    document.getElementById('btn-expense').addEventListener('click', () => navigate(`/project/${projectId}/expense`));
    document.getElementById('btn-payment').addEventListener('click', () => navigate(`/project/${projectId}/payment`));
    document.getElementById('btn-settings')?.addEventListener('click', () => navigate(`/project/${projectId}/settings`));

    document.querySelectorAll('.confirm-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = '…';
            try {
                await api.confirmPayment(btn.dataset.batch);
                navigate(`/project/${projectId}`);
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Confirmar';
                Swal.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        });
    });

    document.querySelectorAll('.user-action-btn').forEach(btn => {
        btn.addEventListener('click', () => runUserAction(btn.dataset.action, btn.dataset, projectId));
    });

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const { value: reason } = await Swal.fire({
                title: 'Anular movimiento',
                input: 'text',
                inputLabel: 'Motivo (obligatorio)',
                inputPlaceholder: 'Ej: Error en el monto',
                showCancelButton: true,
                cancelButtonText: 'Cerrar',
                confirmButtonText: 'Anular',
                confirmButtonColor: '#dc3545',
                inputValidator: v => !v.trim() ? 'El motivo es obligatorio.' : null,
            });
            if (!reason) return;
            try {
                await api.cancelMovement(btn.dataset.id, { reason });
                navigate(`/project/${projectId}`);
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        });
    });
}

// undo / withdraw / reject: always a new cancellation row, never an edit
async function runUserAction(action, { id, batch }, projectId) {
    let call;
    if (action === 'reject') {
        const { isConfirmed, value } = await Swal.fire({
            title: 'Rechazar pago',
            text: 'Usalo si la plata no te llegó. Tu parte del pago queda anulada y quien pagó vuelve a deberla.',
            input: 'text',
            inputPlaceholder: 'Motivo (opcional)',
            showCancelButton: true,
            cancelButtonText: 'Cerrar',
            confirmButtonText: 'Rechazar',
            confirmButtonColor: '#dc3545',
        });
        if (!isConfirmed) return;
        call = () => api.rejectPayment(batch, value?.trim() ? { reason: value.trim() } : {});
    } else {
        const isUndo = action === 'undo';
        const { isConfirmed } = await Swal.fire({
            title: isUndo ? 'Deshacer gasto' : 'Retirar pago',
            text: isUndo
                ? 'El gasto queda anulado (sigue en el historial).'
                : 'Tus partes de este pago que todavía no se confirmaron quedan anuladas (siguen en el historial).',
            icon: 'warning',
            showCancelButton: true,
            cancelButtonText: 'Cerrar',
            confirmButtonText: isUndo ? 'Deshacer' : 'Retirar',
            confirmButtonColor: '#dc3545',
        });
        if (!isConfirmed) return;
        call = () => isUndo ? api.undoExpense(id) : api.withdrawPayment(batch);
    }

    try {
        await call();
        navigate(`/project/${projectId}`);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error', text: e.message });
    }
}
