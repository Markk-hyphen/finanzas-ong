import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import {
    avatar, dayLabel, fmt, fmtBig, fmtDateTime, fmtPct, fmtQty, fmtTime, timeUntil,
} from '../ui/format.js';
import { confirmDialog, showMenu, toast, withLoading } from '../ui/feedback.js';
import { getCachedProjects } from '../shell.js';

const MODES = {
    required:     { label: 'Confirmación obligatoria', short: 'Confirmación obligatoria', icon: 'shieldCheck',
                    help: 'Un pago cuenta recién cuando quien lo recibe lo confirma.' },
    optimistic:   { label: 'Sin confirmación', short: 'Sin confirmación', icon: 'zap',
                    help: 'Los pagos cuentan apenas se registran; quien los recibe puede rechazarlos dentro del plazo.' },
    auto_timeout: { label: 'Autoconfirmación', short: 'Autoconfirmación', icon: 'hourglass',
                    help: 'Los pagos quedan pendientes y se confirman solos si nadie los objeta dentro del plazo.' },
};

// Button label per action, as decided by the API (CancellationPolicy)
function actionLabel(action, m) {
    if (action === 'undo')     return 'Deshacer gasto';
    if (action === 'withdraw') return m.status === 'pending' ? 'Retirar pago' : 'Deshacer pago';
    if (action === 'reject')   return 'Rechazar pago';
    return action;
}

const ACTION_ICONS = { undo: 'undo', withdraw: 'undo', reject: 'xCircle' };

export async function renderDashboard(el, projectId) {
    const user    = storage.getUser();
    const isAdmin = storage.isAdmin();

    const cachedName = getCachedProjects()?.find(p => String(p.id) === String(projectId))?.name;
    if (cachedName) setNav(navFor(cachedName, projectId));

    const [data, overview, members] = await Promise.all([
        api.getDashboard(projectId),
        api.getOverview(projectId).catch(() => null),
        api.getMembers(projectId).catch(() => []),
    ]);
    setNav(navFor(data.project.name, projectId));

    const pctOf = Object.fromEntries(members.map(m => [m.id, Number(m.percentage)]));
    const nameOf = Object.fromEntries(data.balances.map(b => [b.memberId, b.name]));
    const myPct = pctOf[user?.id] ?? Number(getCachedProjects()?.find(p => String(p.id) === String(projectId))?.percentage ?? 0);

    const myBalanceVal = Number(data.balances.find(b => b.memberId === user?.id)?.balance ?? 0);
    // Debt still payable once unconfirmed payments are reserved
    const myProjected  = Number(data.myProjectedBalance ?? myBalanceVal);
    const pendingOut   = myBalanceVal < 0 && myProjected > myBalanceVal ? myProjected - myBalanceVal : 0;
    const payable      = Math.max(0, -myProjected);

    const mode = MODES[data.settings?.confirmationMode] ?? { label: data.settings?.confirmationMode, short: data.settings?.confirmationMode, icon: 'info', help: '' };
    const expensesCount = overview ? overview.movements.filter(m => m.type === 'expense').length : null;
    const tone = Math.abs(myBalanceVal) < 0.01 ? 'zero' : myBalanceVal > 0 ? 'pos' : 'neg';

    el.innerHTML = `
        <header class="page-head">
            <div class="page-head-main">
                <h1 class="page-title">${esc(data.project.name)}</h1>
                <div class="page-meta">
                    <span class="chip chip-outline" title="${esc(mode.help)}">${icon(mode.icon)} ${esc(mode.short)}${data.settings?.confirmationMode !== 'required' ? ` · ${esc(data.settings.objectionWindowHours)} h` : ''}</span>
                    <span class="chip chip-outline">${icon('users')} ${data.balances.length} socios</span>
                </div>
            </div>
            <div class="page-actions">
                ${isAdmin ? `<a class="btn btn-ghost" href="#/project/${esc(projectId)}/settings">${icon('settings')} Configurar</a>` : ''}
                <a class="btn btn-secondary" href="#/project/${esc(projectId)}/expense">${icon('plus')} Registrar gasto</a>
                <button class="btn btn-primary" data-go="payment" ${payable < 0.01 ? 'disabled title="No tenés deuda pendiente de pago"' : ''}>${icon('send')} Pagar</button>
            </div>
        </header>

        ${data.autoConfirmStalled ? `
        <div class="callout callout-warn dash-callout">${icon('alert')}
            <div><strong>La autoconfirmación está detenida.</strong> El scheduler de esta instancia no está corriendo; los pagos pendientes se pueden confirmar a mano.</div>
        </div>` : ''}

        <section class="dash-stats">
            <div class="stat stat-hero ${tone}">
                <div class="stat-label">${icon('wallet')} Mi balance</div>
                <div class="stat-amount num">${tone === 'zero' ? '<span class="cur">$</span>0<span class="dec">,00</span>' : fmtBig(myBalanceVal)}</div>
                <div class="stat-foot">
                    ${tone === 'zero' ? `<span class="chip chip-pos">${icon('check')} Estás al día</span>`
                        : tone === 'pos' ? `<span class="chip chip-pos">${icon('arrowDownLeft')} Te deben</span><span class="stat-note">por gastos que adelantaste</span>`
                        : `<span class="chip chip-neg">${icon('arrowUpRight')} Debés</span><span class="stat-note">${pendingOut ? `${fmt(pendingOut)} esperando confirmación` : 'tu parte de los gastos del grupo'}</span>`}
                </div>
            </div>
            <div class="stat">
                <div class="stat-label">${icon('receipt')} Costo total</div>
                <div class="stat-amount sm num">${fmtBig(data.totalCost)}</div>
                <div class="stat-foot"><span class="stat-note">${expensesCount === null ? 'Gastos del proyecto' : `${expensesCount} ${expensesCount === 1 ? 'gasto registrado' : 'gastos registrados'}`}</span></div>
            </div>
            <div class="stat stat-share">
                <div>
                    <div class="stat-label">${icon('pieChart')} Mi participación</div>
                    <div class="stat-amount sm num">${fmtPct(myPct)}</div>
                    <div class="stat-foot"><span class="stat-note">≈ ${fmt(Number(data.totalCost) * myPct / 100)} del costo</span></div>
                </div>
                ${ring(myPct)}
            </div>
        </section>

        ${pendingOut && payable >= 0.01 ? `
        <div class="callout callout-info dash-callout">${icon('clock')}
            <div>Tenés <strong>${fmt(pendingOut)}</strong> en pagos esperando confirmación. Podés pagar hasta <strong>${fmt(payable)}</strong> más.</div>
        </div>` : ''}

        <div class="dash-grid">
            <div class="dash-col">
                ${data.pendingConfirmations.length ? `
                <section class="card card-attention area-pending" aria-labelledby="pending-title">
                    <div class="card-header">
                        <h2 class="card-title" id="pending-title">${icon('clock')} Pagos para confirmar <span class="count">${data.pendingConfirmations.length}</span></h2>
                    </div>
                    <p class="card-desc">Confirmá solo si la plata ya te llegó.</p>
                    <ul class="list">${data.pendingConfirmations.map(pendingRow).join('')}</ul>
                </section>` : ''}

                <section class="card area-movements" aria-labelledby="mv-title">
                    <div class="card-header mv-header">
                        <h2 class="card-title" id="mv-title">Movimientos</h2>
                        <div class="segmented" role="tablist" aria-label="Alcance">
                            <button role="tab" aria-selected="true" data-scope="mine">Míos</button>
                            ${overview ? '<button role="tab" aria-selected="false" data-scope="all">Todo el proyecto</button>' : ''}
                        </div>
                    </div>
                    <div class="mv-filters" role="group" aria-label="Filtrar">
                        <button class="filter" aria-pressed="true" data-filter="all">Todo</button>
                        <button class="filter" aria-pressed="false" data-filter="expense">Gastos</button>
                        <button class="filter" aria-pressed="false" data-filter="payment">Pagos</button>
                        <button class="filter" aria-pressed="false" data-filter="pending">Pendientes</button>
                    </div>
                    <div id="movements"></div>
                </section>
            </div>

            <div class="dash-col">
                <section class="card area-balances" aria-labelledby="bal-title">
                    <div class="card-header">
                        <h2 class="card-title" id="bal-title">Balances del grupo</h2>
                    </div>
                    <ul class="list balances">${balanceRows(data.balances, pctOf, user?.id)}</ul>
                    <div class="card-footer bal-legend">
                        <span><i class="dot neg"></i> debe</span><span><i class="dot pos"></i> le deben</span>
                    </div>
                </section>

                <section class="card area-rules" aria-labelledby="rules-title">
                    <div class="card-header">
                        <h2 class="card-title" id="rules-title">Reglas del proyecto</h2>
                        ${isAdmin ? `<a class="btn btn-ghost btn-sm" href="#/project/${esc(projectId)}/settings">Editar</a>` : ''}
                    </div>
                    <div class="card-body rules">
                        <div class="rule">
                            <span class="mv-icon expense">${icon(mode.icon)}</span>
                            <div><div class="strong">${esc(mode.label)}</div><div class="small muted">${esc(mode.help)}</div></div>
                        </div>
                        <dl class="rule-facts">
                            ${data.settings?.confirmationMode !== 'required' ? `<div><dt>Plazo de objeción</dt><dd class="num">${esc(data.settings.objectionWindowHours)} h</dd></div>` : ''}
                            <div><dt>Deshacer lo propio</dt><dd class="num">${Number(data.settings?.selfCancelMinutes) ? `${esc(data.settings.selfCancelMinutes)} min` : 'Desactivado'}</dd></div>
                        </dl>
                    </div>
                </section>
            </div>
        </div>
    `;

    // Phone action bar: the two primary actions within thumb reach
    const bar = document.createElement('div');
    bar.className = 'actionbar';
    bar.innerHTML = `
        <a class="btn btn-secondary" href="#/project/${esc(projectId)}/expense">${icon('plus')} Gasto</a>
        <button class="btn btn-primary" data-go="payment" ${payable < 0.01 ? 'disabled' : ''}>${icon('send')} Pagar</button>`;
    document.body.appendChild(bar);
    document.body.classList.add('has-actionbar');

    document.querySelectorAll('[data-go="payment"]').forEach(b =>
        b.addEventListener('click', () => navigate(`/project/${projectId}/payment`)));

    // ── Movements (scope tabs + filter chips, all client-side) ──
    const state = { scope: 'mine', filter: 'all' };
    const ctx = { user, isAdmin, projectId, nameOf };
    const host = el.querySelector('#movements');

    const draw = () => {
        const source = state.scope === 'mine' ? data.movements : (overview?.movements ?? []);
        const rows = source.filter(m =>
            state.filter === 'all' ? true
            : state.filter === 'pending' ? m.status === 'pending'
            : m.type === state.filter);
        host.innerHTML = movementList(rows, state.scope, ctx);
        bindMovementActions(host, rows, ctx);
    };

    el.querySelectorAll('[data-scope]').forEach(tab => tab.addEventListener('click', () => {
        state.scope = tab.dataset.scope;
        el.querySelectorAll('[data-scope]').forEach(t => t.setAttribute('aria-selected', String(t === tab)));
        draw();
    }));
    el.querySelectorAll('[data-filter]').forEach(f => f.addEventListener('click', () => {
        state.filter = f.dataset.filter;
        el.querySelectorAll('[data-filter]').forEach(x => x.setAttribute('aria-pressed', String(x === f)));
        draw();
    }));
    draw();

    // ── Pending confirmations ──
    el.querySelectorAll('.confirm-btn').forEach(btn => btn.addEventListener('click', async () => {
        try {
            await withLoading(btn, () => api.confirmPayment(btn.dataset.batch));
            await collapseRow(btn.closest('.list-item'));
            toast('Pago confirmado', { text: 'Ya impacta en los balances.' });
            navigate(`/project/${projectId}`);
        } catch (e) {
            toast('No se pudo confirmar', { text: e.message, type: 'error' });
        }
    }));
    el.querySelectorAll('.pending-reject').forEach(btn => btn.addEventListener('click', () =>
        runUserAction('reject', btn.dataset, projectId)));
}

function navFor(name, projectId) {
    return {
        title: name,
        back: '/projects',
        crumbs: [{ label: 'Mis proyectos', href: '/projects' }, { label: name, href: `/project/${projectId}` }],
    };
}

function ring(pct) {
    const v = Math.max(0, Math.min(100, pct));
    // pathLength=100 makes the dash math independent of the radius
    return `<svg class="ring" viewBox="0 0 56 56" aria-hidden="true">
        <circle cx="28" cy="28" r="22" class="ring-track"/>
        <circle cx="28" cy="28" r="22" class="ring-value" pathLength="100" style="stroke-dasharray:100;stroke-dashoffset:${100 - v}"/>
    </svg>`;
}

function pendingRow(p) {
    return `
        <li class="list-item pending-item">
            ${avatar(p.fromMemberName, p.fromMemberId)}
            <div class="main">
                <div class="title"><span class="truncate">${esc(p.fromMemberName)} te pagó</span></div>
                <div class="sub">
                    <span>${fmtDateTime(p.createdAt)}</span>
                    ${p.autoConfirmAt ? `<span class="chip chip-warn">${icon('hourglass')} se confirma solo ${esc(timeUntil(p.autoConfirmAt))}</span>` : ''}
                </div>
            </div>
            <div class="pending-side">
                <div class="pending-amount num">${fmt(p.amount)}</div>
                <div class="pending-actions">
                    ${(p.actions ?? []).includes('reject') ? `<button class="btn btn-danger-soft btn-sm pending-reject" data-batch="${esc(p.batchId)}">Rechazar</button>` : ''}
                    <button class="btn btn-success btn-sm confirm-btn" data-batch="${esc(p.batchId)}">${icon('check')} Confirmar</button>
                </div>
            </div>
        </li>`;
}

function balanceRows(balances, pctOf, myId) {
    const max = Math.max(1, ...balances.map(b => Math.abs(Number(b.balance))));
    // Biggest creditors first, biggest debtors last: reads like a ledger
    const sorted = [...balances].sort((a, b) => Number(b.balance) - Number(a.balance));
    return sorted.map(b => {
        const n = Number(b.balance);
        const settled = Math.abs(n) < 0.01;
        const w = settled ? 0 : Math.max(3, (Math.abs(n) / max) * 50);
        return `
        <li class="list-item bal-item">
            ${avatar(b.name, b.memberId)}
            <div class="main">
                <div class="title">
                    <span class="truncate">${esc(b.name)}</span>
                    ${b.memberId === myId ? '<span class="chip chip-brand">vos</span>' : ''}
                </div>
                <div class="bal-bar" aria-hidden="true">
                    <i class="${n >= 0 ? 'pos' : 'neg'}" style="width:${w}%;${n >= 0 ? 'left:50%' : 'right:50%'}"></i>
                </div>
            </div>
            <div class="side">
                ${settled
                    ? '<div class="bal-amt faint">Saldado</div>'
                    : `<div class="bal-amt num ${n > 0 ? 'text-pos' : 'text-neg'}">${n > 0 ? '+' : '−'} ${fmt(Math.abs(n))}</div>`}
                <div class="xsmall faint">${pctOf[b.memberId] !== undefined ? `${fmtPct(pctOf[b.memberId])} del costo` : ''}</div>
            </div>
        </li>`;
    }).join('');
}

function describe(m, scope, { user, nameOf }) {
    const meId = user?.id;
    const from = m.fromMemberName ?? nameOf[m.fromMemberId] ?? 'Alguien';
    const to = m.toMemberName ?? nameOf[m.toMemberId] ?? 'alguien';
    const detail = m.material ? `${esc(m.material)}${m.quantity ? ` × ${fmtQty(m.quantity)}` : ''}` : '';

    if (m.type === 'expense') {
        const who = scope === 'mine' || m.fromMemberId === meId ? 'Gasto' : `Gasto de ${esc(from)}`;
        return { icon: 'receipt', tone: 'expense', title: who, detail, sign: '' };
    }
    if (m.type === 'payment') {
        if (m.fromMemberId === meId) return { icon: 'arrowUpRight', tone: 'out', title: `Pagaste a ${esc(to)}`, detail, sign: '−' };
        if (m.toMemberId === meId) return { icon: 'arrowDownLeft', tone: 'in', title: `${esc(from)} te pagó`, detail, sign: '+' };
        return { icon: 'send', tone: 'neutral', title: `${esc(from)} → ${esc(to)}`, detail, sign: '' };
    }
    return { icon: 'info', tone: 'neutral', title: esc(m.type), detail, sign: '' };
}

function statusChip(m) {
    if (m.status === 'pending') {
        return m.autoConfirmAt
            ? `<span class="chip chip-warn">${icon('hourglass')} pendiente · se confirma solo ${esc(timeUntil(m.autoConfirmAt))}</span>`
            : `<span class="chip chip-warn">${icon('clock')} pendiente</span>`;
    }
    if (m.confirmedVia === 'timeout') return `<span class="chip">${icon('hourglass')} autoconfirmado</span>`;
    return '';
}

// Actions the API allows on this row (pending shares to confirm already have
// their own buttons in "Pagos para confirmar")
function rowActions(m, isAdmin) {
    const actions = (m.actions ?? []).filter(a => !(a === 'reject' && m.status === 'pending'));
    return { actions, admin: isAdmin };
}

function movementList(rows, scope, ctx) {
    if (rows.length === 0) {
        return `<div class="empty">
            <div class="empty-icon">${icon('inbox')}</div>
            <div class="empty-title">Nada por acá</div>
            <div class="empty-text">${scope === 'mine' ? 'Cuando registres un gasto o un pago, aparece en esta lista.' : 'Este proyecto todavía no tiene movimientos con este filtro.'}</div>
        </div>`;
    }

    let html = '', lastDay = null;
    rows.forEach((m, i) => {
        const day = dayLabel(m.createdAt);
        if (day !== lastDay) {
            if (lastDay !== null) html += '</ul>';
            html += `<div class="list-group-label">${esc(day)}</div><ul class="list">`;
            lastDay = day;
        }
        const d = describe(m, scope, ctx);
        const { actions, admin } = rowActions(m, ctx.isAdmin);
        const hasMenu = actions.length > 0 || admin;
        html += `
            <li class="list-item with-icon mv-item ${m.status === 'pending' ? 'is-pending' : ''}">
                <span class="mv-icon ${d.tone}">${icon(d.icon)}</span>
                <div class="main">
                    <div class="title"><span class="truncate">${d.title}</span></div>
                    <div class="sub">
                        ${d.detail ? `<span class="truncate">${d.detail}</span><span class="sep">·</span>` : ''}
                        <span>${fmtTime(m.createdAt)}</span>
                        ${statusChip(m)}
                    </div>
                </div>
                <div class="side mv-amount num ${d.tone === 'in' ? 'text-pos' : ''}">${d.sign ? d.sign + ' ' : ''}${fmt(m.amount)}</div>
                ${hasMenu
                    ? `<button class="icon-btn sm mv-menu" data-i="${i}" aria-label="Acciones" aria-haspopup="menu">${icon('more')}</button>`
                    : '<span class="mv-menu-spacer"></span>'}
            </li>`;
    });
    return html + '</ul>';
}

function bindMovementActions(host, rows, { isAdmin, projectId }) {
    host.querySelectorAll('.mv-menu').forEach(btn => btn.addEventListener('click', () => {
        const m = rows[Number(btn.dataset.i)];
        const { actions } = rowActions(m, isAdmin);
        const items = actions.map(a => ({
            label: actionLabel(a, m),
            icon: ACTION_ICONS[a] ?? 'undo',
            danger: a === 'reject',
            onClick: () => runUserAction(a, { id: m.id, batch: m.batchId }, projectId),
        }));
        if (isAdmin) {
            if (items.length) items.push('sep');
            items.push({ label: 'Anular (admin)', icon: 'ban', danger: true, onClick: () => adminCancel(m.id, projectId) });
        }
        showMenu(btn, items);
    }));
}

async function adminCancel(id, projectId) {
    const reason = await confirmDialog({
        title: 'Anular movimiento',
        text: 'El movimiento queda anulado y se revierte su efecto en los balances. Sigue visible en el historial.',
        tone: 'danger',
        iconName: 'ban',
        confirmText: 'Anular',
        input: { label: 'Motivo', placeholder: 'Ej: error en el monto', required: true },
    });
    if (!reason) return;
    try {
        await api.cancelMovement(id, { reason });
        toast('Movimiento anulado');
        navigate(`/project/${projectId}`);
    } catch (e) {
        toast('No se pudo anular', { text: e.message, type: 'error' });
    }
}

// undo / withdraw / reject: always a new cancellation row, never an edit
async function runUserAction(action, { id, batch }, projectId) {
    let call, done;
    if (action === 'reject') {
        const reason = await confirmDialog({
            title: 'Rechazar pago',
            text: 'Usalo si la plata no te llegó. Tu parte del pago queda anulada y quien pagó vuelve a deberla.',
            tone: 'danger',
            iconName: 'xCircle',
            confirmText: 'Rechazar',
            input: { label: 'Motivo', placeholder: 'Ej: no me llegó la transferencia', required: false },
        });
        if (reason === false) return;
        call = () => api.rejectPayment(batch, reason ? { reason } : {});
        done = 'Pago rechazado';
    } else {
        const isUndo = action === 'undo';
        const ok = await confirmDialog({
            title: isUndo ? 'Deshacer gasto' : 'Retirar pago',
            text: isUndo
                ? 'El gasto queda anulado (sigue en el historial).'
                : 'Tus partes de este pago que todavía no se confirmaron quedan anuladas (siguen en el historial).',
            tone: 'warn',
            iconName: 'undo',
            confirmText: isUndo ? 'Deshacer' : 'Retirar',
            cancelText: 'Cerrar',
        });
        if (!ok) return;
        call = () => isUndo ? api.undoExpense(id) : api.withdrawPayment(batch);
        done = isUndo ? 'Gasto deshecho' : 'Pago retirado';
    }

    try {
        await call();
        toast(done);
        navigate(`/project/${projectId}`);
    } catch (e) {
        toast('No se pudo completar', { text: e.message, type: 'error' });
    }
}

function collapseRow(li) {
    if (!li) return Promise.resolve();
    li.style.transition = 'opacity 200ms ease, transform 200ms ease';
    li.style.opacity = '0';
    li.style.transform = 'translateX(12px)';
    return new Promise(r => setTimeout(r, 200));
}
