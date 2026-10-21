import { api, storage } from '../api.js';
import { setNav } from '../nav.js';
import { esc } from '../html.js';
import { icon } from '../ui/icons.js';
import { avatar, fmt, fmtPct, hueFor } from '../ui/format.js';
import { loadProjects } from '../shell.js';

function greeting() {
    const h = new Date().getHours();
    if (h < 6) return 'Buenas noches';
    if (h < 13) return 'Buen día';
    if (h < 20) return 'Buenas tardes';
    return 'Buenas noches';
}

export async function renderProjects(el) {
    setNav({ title: 'Mis proyectos', crumbs: [{ label: 'Mis proyectos' }] });

    const user = storage.getUser();
    const projects = await loadProjects(true);
    const firstName = (user?.name ?? '').split(' ')[0];

    if (projects.length === 0) {
        el.innerHTML = `
            <header class="page-head">
                <div>
                    <h1 class="page-title">${greeting()}, ${esc(firstName)}</h1>
                    <p class="page-sub">Todavía no participás en ningún proyecto.</p>
                </div>
            </header>
            <div class="card">
                <div class="empty">
                    <div class="empty-icon">${icon('folder')}</div>
                    <div class="empty-title">Sin proyectos por ahora</div>
                    <div class="empty-text">Cuando un administrador te sume a un proyecto, va a aparecer acá con tu balance.</div>
                </div>
            </div>`;
        return;
    }

    el.innerHTML = `
        <header class="page-head">
            <div>
                <h1 class="page-title">${greeting()}, ${esc(firstName)}</h1>
                <p class="page-sub" id="projects-summary">Participás en ${projects.length} ${projects.length === 1 ? 'proyecto' : 'proyectos'}.</p>
            </div>
        </header>
        <div class="project-grid">
            ${projects.map(p => `
            <a class="project-card" href="#/project/${esc(p.id)}" style="--hue:${hueFor('p' + p.id)}" data-id="${esc(p.id)}">
                <div class="pc-top">
                    <span class="pc-icon">${icon('folder')}</span>
                    <span class="chip">${icon('pieChart')} ${fmtPct(p.percentage)}</span>
                </div>
                <div class="pc-name">${esc(p.name)}</div>
                <div class="pc-foot" data-slot="foot">
                    <div class="skel skel-line" style="width:45%"></div>
                </div>
                <span class="pc-arrow">${icon('chevronRight')}</span>
            </a>`).join('')}
        </div>
    `;

    // Balances load progressively: the grid is usable right away
    let owedToMe = 0, iOwe = 0;
    await Promise.all(projects.map(async p => {
        const foot = el.querySelector(`.project-card[data-id="${p.id}"] [data-slot="foot"]`);
        try {
            const d = await api.getDashboard(p.id);
            const mine = Number(d.balances.find(b => b.memberId === user?.id)?.balance ?? 0);
            if (mine > 0) owedToMe += mine; else iOwe += -mine;
            const pending = d.pendingConfirmations.length;
            const state = Math.abs(mine) < 0.01
                ? '<span class="chip chip-pos chip-dot">Saldado</span>'
                : mine > 0
                    ? `<span class="pc-bal text-pos num">${fmt(mine)}</span><span class="faint small">te deben</span>`
                    : `<span class="pc-bal text-neg num">${fmt(-mine)}</span><span class="faint small">debés</span>`;
            if (!foot?.isConnected) return;
            foot.innerHTML = `
                <div class="pc-balance">${state}</div>
                <div class="pc-meta">
                    ${pending ? `<span class="chip chip-warn">${icon('clock')} ${pending} por confirmar</span>` : ''}
                    <span class="avatar-stack">${d.balances.slice(0, 4).map(b => avatar(b.name, b.memberId, 'sm')).join('')}</span>
                </div>`;
        } catch {
            if (foot?.isConnected) foot.innerHTML = '<span class="faint small">Sin datos</span>';
        }
    }));

    const summary = document.getElementById('projects-summary');
    if (summary?.isConnected && (owedToMe || iOwe)) {
        const parts = [];
        if (owedToMe >= 0.01) parts.push(`te deben <strong class="text-pos num">${fmt(owedToMe)}</strong>`);
        if (iOwe >= 0.01) parts.push(`debés <strong class="text-neg num">${fmt(iOwe)}</strong>`);
        summary.innerHTML = `Participás en ${projects.length} ${projects.length === 1 ? 'proyecto' : 'proyectos'} · En total ${parts.join(' y ')}.`;
    }
}
