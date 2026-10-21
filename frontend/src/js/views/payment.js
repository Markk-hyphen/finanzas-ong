import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import { avatar, fmt, fmtDateTime } from '../ui/format.js';
import { toast } from '../ui/feedback.js';
import { amountField, bindAmountField } from '../ui/amount.js';

const AFTER = {
    required:     { icon: 'shieldCheck', text: 'Cada receptor tiene que <strong>confirmar</strong> que recibió su parte. Hasta entonces el pago queda pendiente.' },
    optimistic:   { icon: 'zap', text: 'El pago <strong>impacta en el acto</strong>. Cada receptor puede rechazar su parte si no le llegó, dentro del plazo de objeción.' },
    auto_timeout: { icon: 'hourglass', text: 'El pago queda pendiente y <strong>se confirma solo</strong> si nadie lo objeta dentro del plazo.' },
};

export async function renderPayment(el, projectId) {
    const user = storage.getUser();
    const dash = await api.getDashboard(projectId);
    const name = dash.project.name;
    setNav({
        title: 'Registrar pago',
        back: `/project/${projectId}`,
        crumbs: [{ label: 'Mis proyectos', href: '/projects' }, { label: name, href: `/project/${projectId}` }, { label: 'Registrar pago' }],
    });

    const myBalance = Number(dash.balances.find(b => b.memberId === user?.id)?.balance ?? 0);
    const payable = Math.max(0, -Number(dash.myProjectedBalance ?? myBalance));
    const mode = dash.settings?.confirmationMode ?? 'required';
    const after = AFTER[mode] ?? AFTER.required;

    if (payable < 0.01) {
        el.innerHTML = `
            <div class="form-page">
                <header class="page-head"><div><h1 class="page-title">Registrar pago</h1><p class="page-sub">${esc(name)}</p></div></header>
                <div class="card"><div class="empty">
                    <div class="empty-icon">${icon('checkCircle')}</div>
                    <div class="empty-title">No tenés nada para pagar</div>
                    <div class="empty-text">${myBalance < 0 ? 'Tus pagos pendientes ya cubren tu deuda. Esperá a que los confirmen.' : 'Estás al día en este proyecto.'}</div>
                    <a class="btn btn-secondary btn-sm" style="margin-top:.5rem" href="#/project/${esc(projectId)}">Volver al proyecto</a>
                </div></div>
            </div>`;
        return;
    }

    el.innerHTML = `
        <div class="form-page">
            <header class="page-head">
                <div>
                    <h1 class="page-title">Registrar pago</h1>
                    <p class="page-sub">${esc(name)} · Saldá tu deuda con el grupo.</p>
                </div>
            </header>

            <div class="form-layout">
                <form id="payment-form" class="card form-card" novalidate>
                    <div class="card-body">
                        <div class="owed-banner">
                            <span class="muted small">Podés pagar hasta</span>
                            <span class="owed-amount num">${fmt(payable)}</span>
                        </div>
                        ${amountField({ id: 'f-amount', label: 'Monto a pagar' })}
                        <div class="quick-row" style="margin-top:.9rem">
                            <button type="button" class="quick" data-fill="${payable.toFixed(2)}">Todo lo que debo</button>
                            ${payable >= 2 ? `<button type="button" class="quick" data-fill="${(Math.floor(payable * 50) / 100).toFixed(2)}">La mitad</button>` : ''}
                        </div>
                        <div id="payment-err" class="callout callout-neg form-error hidden" role="alert" style="margin-top:1.25rem"></div>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-ghost" href="#/project/${esc(projectId)}">Cancelar</a>
                        <button type="submit" id="btn-confirm" class="btn btn-primary btn-lg" disabled>${icon('send')} Confirmar pago</button>
                    </div>
                </form>

                <aside class="form-aside">
                    <section class="card">
                        <div class="card-header"><h2 class="card-title">${icon('send')} A quién le llega</h2></div>
                        <p class="card-desc">El pago se reparte entre quienes tienen saldo a favor, en proporción a lo que se les debe.</p>
                        <div id="allocation" class="alloc">
                            <div class="alloc-empty faint small">Ingresá un monto para ver la distribución.</div>
                        </div>
                    </section>
                    <div class="callout callout-brand">${icon(after.icon)}<div>${after.text}</div></div>
                </aside>
            </div>
        </div>
    `;

    const btn = el.querySelector('#btn-confirm');
    const err = el.querySelector('#payment-err');
    const alloc = el.querySelector('#allocation');
    let previewFor = null, timer = null, seq = 0;

    const drawPreview = (p) => {
        const max = Math.max(...p.allocations.map(a => Number(a.amount)), 1);
        alloc.innerHTML = `<ul class="list">${p.allocations.map(a => `
            <li class="list-item split-item">
                ${avatar(a.toMemberName, a.toMemberId, 'sm')}
                <div class="main">
                    <div class="title"><span class="truncate">${esc(a.toMemberName)}</span></div>
                    <div class="split-bar pos"><i style="width:${(Number(a.amount) / max) * 100}%"></i></div>
                </div>
                <div class="side num strong">${fmt(a.amount)}</div>
            </li>`).join('')}</ul>`;
    };

    const preview = async (amount) => {
        previewFor = null;
        btn.disabled = true;
        err.classList.add('hidden');
        clearTimeout(timer);
        if (!amount) {
            alloc.innerHTML = '<div class="alloc-empty faint small">Ingresá un monto para ver la distribución.</div>';
            return;
        }
        if (Number(amount) > payable + 0.001) {
            alloc.innerHTML = '';
            showErr(err, `El monto supera lo que podés pagar (${fmt(payable)}).`);
            return;
        }
        alloc.classList.add('loading');
        timer = setTimeout(async () => {
            const mine = ++seq;
            try {
                const p = await api.previewPayment(projectId, { amount });
                if (mine !== seq) return;
                drawPreview(p);
                previewFor = amount;
                btn.disabled = false;
            } catch (ex) {
                if (mine !== seq) return;
                alloc.innerHTML = '';
                showErr(err, ex.message);
            } finally {
                if (mine === seq) alloc.classList.remove('loading');
            }
        }, 350);
    };

    const field = bindAmountField(el, 'f-amount', preview);
    el.querySelectorAll('[data-fill]').forEach(b => b.addEventListener('click', () => {
        field.set(b.dataset.fill.replace('.', ','));
        field.input.focus();
    }));

    el.querySelector('#payment-form').addEventListener('submit', async e => {
        e.preventDefault();
        if (!previewFor) return;
        err.classList.add('hidden');
        btn.disabled = true;
        btn.classList.add('is-loading');

        try {
            const res = await api.createPayment(projectId, { amount: previewFor });
            const text = {
                optimistic:   'Ya impacta en los balances. Quien lo recibe puede rechazarlo si no le llegó.',
                auto_timeout: 'Está pendiente; si nadie lo objeta se confirma solo'
                              + (res.autoConfirmAt ? ` el ${fmtDateTime(res.autoConfirmAt)}` : '') + '.',
            }[res.confirmationMode] ?? 'Queda pendiente hasta que los receptores lo confirmen.';
            toast('Pago registrado', { text, duration: 5000 });
            navigate(`/project/${projectId}`);
        } catch (ex) {
            showErr(err, ex.message);
            btn.disabled = false;
            btn.classList.remove('is-loading');
        }
    });
}

function showErr(err, message) {
    err.innerHTML = `${icon('alert')}<span></span>`;
    err.querySelector('span').textContent = message;
    err.classList.remove('hidden');
}
