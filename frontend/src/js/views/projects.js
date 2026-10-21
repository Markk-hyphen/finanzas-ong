import { api, storage } from '../api.js';
import { setNav, navigate } from '../nav.js';
import { esc } from '../html.js';

export async function renderProjects(el) {
    setNav({ title: 'Mis proyectos', logout: true });

    const [projects, user] = await Promise.all([api.getProjects(), Promise.resolve(storage.getUser())]);

    if (projects.length === 0) {
        el.innerHTML = `
            <p class="text-muted small mt-3">Hola, <strong>${esc(user?.name)}</strong></p>
            <div class="alert alert-info mt-2">No estás en ningún proyecto todavía.</div>
        `;
        return;
    }

    el.innerHTML = `
        <p class="text-muted small mt-3 mb-3">Hola, <strong>${esc(user?.name)}</strong></p>
        <div id="project-cards"></div>
    `;

    const container = document.getElementById('project-cards');
    for (const p of projects) {
        const card = document.createElement('div');
        card.className = 'card shadow-sm border-0 mb-3';
        card.style.cursor = 'pointer';
        card.innerHTML = `
            <div class="card-body d-flex justify-content-between align-items-center py-3">
                <div>
                    <div class="fw-semibold">${esc(p.name)}</div>
                    <div class="text-muted small">${esc(p.percentage)}% de participación</div>
                </div>
                <span class="text-primary fs-4">›</span>
            </div>
        `;
        card.addEventListener('click', () => navigate(`/project/${p.id}`));
        container.appendChild(card);
    }
}
