import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import { avatar, fmt, fmtPct, parseAmount } from '../ui/format.js';
import { toast } from '../ui/feedback.js';
import { amountField, bindAmountField } from '../ui/amount.js';

export async function renderExpense(el, projectId) {
    const user = storage.getUser();
    const [dash, members] = await Promise.all([
        api.getDashboard(projectId),
        api.getMembers(projectId).catch(() => []),
    ]);
    const name = dash.project.name;
    setNav({
        title: 'Registrar gasto',
        back: `/project/${projectId}`,
        crumbs: [{ label: 'Mis proyectos', href: '/projects' }, { label: name, href: `/project/${projectId}` }, { label: 'Registrar gasto' }],
    });
    const undoMin = Number(dash.settings?.selfCancelMinutes ?? 0);

    el.innerHTML = `
        <div class="form-page">
            <header class="page-head">
                <div>
                    <h1 class="page-title">Registrar gasto</h1>
                    <p class="page-sub">${esc(name)} · Lo que pagaste vos para el proyecto.</p>
                </div>
            </header>

            <div class="form-layout">
                <form id="expense-form" class="card form-card" novalidate>
                    <div class="card-body">
                        ${amountField({ id: 'f-amount', label: 'Monto del gasto' })}

                        <div class="field" style="margin-top:1.5rem">
                            <label class="label" for="f-material">Material <span class="opt">opcional</span></label>
                            <div class="combo">
                                <div class="input-group">
                                    <input type="text" id="f-material" class="input" placeholder="Buscar material…" autocomplete="off"
                                           role="combobox" aria-expanded="false" aria-controls="material-suggestions" aria-autocomplete="list" />
                                    <span class="suffix" id="material-state">${icon('search')}</span>
                                </div>
                                <div id="material-suggestions" class="combo-list hidden" role="listbox"></div>
                            </div>
                            <input type="hidden" id="f-material-id" />
                        </div>

                        <div class="field">
                            <label class="label" for="f-qty">Cantidad <span class="opt">opcional</span></label>
                            <input type="text" id="f-qty" class="input" placeholder="Ej: 2,5" inputmode="decimal" autocomplete="off" />
                        </div>

                        <div id="expense-err" class="callout callout-neg form-error hidden" role="alert" style="margin-top:1.25rem"></div>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-ghost" href="#/project/${esc(projectId)}">Cancelar</a>
                        <button type="submit" id="expense-btn" class="btn btn-primary btn-lg">${icon('check')} Registrar gasto</button>
                    </div>
                </form>

                <aside class="form-aside">
                    <section class="card">
                        <div class="card-header"><h2 class="card-title">${icon('scale')} Cómo se reparte</h2></div>
                        <p class="card-desc">Cada socio aporta según su participación en el proyecto.</p>
                        <ul class="list split-list" id="split-list"></ul>
                    </section>
                    <div class="callout">${icon('info')}
                        <div>${undoMin ? `Si te equivocás, podés <strong>deshacerlo durante ${undoMin} minutos</strong> desde tus movimientos.` : 'En este proyecto no se puede deshacer un gasto por tu cuenta: pedile a un administrador que lo anule.'}</div>
                    </div>
                </aside>
            </div>
        </div>
    `;

    // ── Live split preview ──
    const splitList = el.querySelector('#split-list');
    const drawSplit = () => {
        const amount = Number(parseAmount(el.querySelector('#f-amount').value) ?? 0);
        splitList.innerHTML = members.length ? members.map(m => {
            const share = amount * Number(m.percentage) / 100;
            return `
            <li class="list-item split-item">
                ${avatar(m.name, m.id, 'sm')}
                <div class="main">
                    <div class="title"><span class="truncate">${esc(m.name)}</span>${m.id === user?.id ? '<span class="chip chip-brand">vos</span>' : ''}</div>
                    <div class="split-bar"><i style="width:${Math.min(100, Number(m.percentage))}%"></i></div>
                </div>
                <div class="side">
                    <div class="num strong ${amount ? '' : 'faint'}">${fmt(share)}</div>
                    <div class="xsmall faint">${fmtPct(m.percentage)}</div>
                </div>
            </li>`;
        }).join('') : '<li class="list-item faint small">No pudimos cargar los socios.</li>';
    };
    bindAmountField(el, 'f-amount', drawSplit);
    drawSplit();

    // ── Material autocomplete (keyboard friendly) ──
    const input = el.querySelector('#f-material');
    const hidden = el.querySelector('#f-material-id');
    const list = el.querySelector('#material-suggestions');
    const stateIcon = el.querySelector('#material-state');
    let results = [], active = -1, timer = null, seq = 0;

    const close = () => { list.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); active = -1; };
    const pick = (m) => {
        input.value = m.name;
        hidden.value = m.id;
        stateIcon.innerHTML = `<span class="text-pos">${icon('checkCircle')}</span>`;
        close();
        el.querySelector('#f-qty').focus();
    };
    const highlight = (text, q) => {
        const i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i < 0) return esc(text);
        return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length));
    };
    const drawList = (q) => {
        if (!results.length) {
            list.innerHTML = `<div class="combo-option faint" aria-disabled="true">Sin resultados para “${esc(q)}”</div>`;
        } else {
            list.innerHTML = results.map((m, i) =>
                `<button type="button" class="combo-option" role="option" id="mat-${i}" data-i="${i}" aria-selected="${i === active}">${icon('package')}<span>${highlight(m.name, q)}</span></button>`
            ).join('');
            list.querySelectorAll('[data-i]').forEach(b => b.addEventListener('mousedown', e => {
                e.preventDefault(); // keep focus in the input
                pick(results[Number(b.dataset.i)]);
            }));
        }
        list.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-activedescendant', active >= 0 ? `mat-${active}` : '');
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        hidden.value = '';
        stateIcon.innerHTML = icon('search');
        const q = input.value.trim();
        if (q.length < 2) { close(); return; }
        timer = setTimeout(async () => {
            const mine = ++seq;
            try {
                const r = await api.getMaterials(q);
                if (mine !== seq) return;
                results = r; active = r.length ? 0 : -1;
                drawList(q);
            } catch { close(); }
        }, 220);
    });
    input.addEventListener('keydown', e => {
        if (list.classList.contains('hidden') || !results.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            drawList(input.value.trim());
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            pick(results[active]);
        } else if (e.key === 'Escape') {
            close();
        }
    });
    input.addEventListener('blur', () => setTimeout(close, 120));

    // ── Submit ──
    el.querySelector('#expense-form').addEventListener('submit', async e => {
        e.preventDefault();
        const btn = el.querySelector('#expense-btn');
        const err = el.querySelector('#expense-err');
        err.classList.add('hidden');

        const amount = parseAmount(el.querySelector('#f-amount').value);
        if (!amount) {
            el.querySelector('.amount-field').classList.add('invalid');
            el.querySelector('#f-amount').focus();
            return;
        }
        const qtyRaw = el.querySelector('#f-qty').value.trim().replace(',', '.');
        if (qtyRaw && !(Number(qtyRaw) > 0)) {
            showErr(err, 'La cantidad tiene que ser un número mayor a cero.');
            return;
        }
        if (input.value.trim() && !hidden.value) {
            showErr(err, 'Elegí un material de la lista, o dejá el campo vacío.');
            input.focus();
            return;
        }

        const body = { amount };
        if (hidden.value) body.materialId = parseInt(hidden.value, 10);
        if (qtyRaw)       body.quantity   = qtyRaw;

        btn.disabled = true;
        btn.classList.add('is-loading');
        try {
            const res = await api.createExpense(projectId, body);
            if (res.warning) toast('Gasto registrado', { text: res.warning, type: 'warning', duration: 6000 });
            else toast('Gasto registrado', { text: `${fmt(amount)} repartidos entre ${members.length || 'los'} socios.` });
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
