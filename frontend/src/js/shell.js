import { api, storage } from './api.js';
import { esc } from './html.js';
import { navigate } from './nav.js';
import { stopNotifications } from './notifications.js';
import { icon } from './ui/icons.js';
import { avatar, fmtPct, hueFor } from './ui/format.js';
import { showMenu } from './ui/feedback.js';

// ───────────── Theme ─────────────

const THEME_KEY = 'ong_theme';

export function getTheme() {
    try { return localStorage.getItem(THEME_KEY) || 'auto'; } catch { return 'auto'; }
}

export function setTheme(theme) {
    try { localStorage.setItem(THEME_KEY, theme); } catch {}
    applyTheme();
}

function applyTheme() {
    const t = getTheme();
    if (t === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', t);
    const dark = t === 'dark' || (t === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0e0e13' : '#f6f6f9');
}

// ───────────── Session ─────────────

export function logout() {
    stopNotifications();
    storage.clear();
    projectsCache = null;
    navigate('/login');
}

// ───────────── Sidebar ─────────────

let projectsCache = null;
let projectsLoading = null;

export function getCachedProjects() {
    return projectsCache;
}

export async function loadProjects(force = false) {
    if (projectsCache && !force) return projectsCache;
    if (!projectsLoading) {
        projectsLoading = api.getProjects()
            .then(p => (projectsCache = p))
            .finally(() => { projectsLoading = null; });
    }
    return projectsLoading;
}

/**
 * Renders the chrome for the current route. `route` is the parsed route from
 * main.js; the sidebar highlights the matching entry.
 */
export function renderShell(route) {
    const guest = route.view === 'login';
    document.body.classList.toggle('guest', guest);
    if (guest) return;

    const user = storage.getUser();
    renderSidebar(route);
    loadProjects().then(() => renderSidebar(route)).catch(() => {});

    document.getElementById('sidebar-user').innerHTML = `
        <div class="user-card">
            ${avatar(user?.name, user?.id)}
            <div class="who">
                <div class="name truncate">${esc(user?.name ?? '')}</div>
                <div class="meta truncate">${storage.isAdmin() ? 'Administrador' : 'Socio'}</div>
            </div>
            <button class="icon-btn sm" id="sidebar-theme" title="Tema" aria-label="Cambiar tema">${icon(themeIcon())}</button>
            <button class="icon-btn sm" id="sidebar-logout" title="Cerrar sesión" aria-label="Cerrar sesión">${icon('logOut')}</button>
        </div>`;
    document.getElementById('sidebar-logout').onclick = logout;
    document.getElementById('sidebar-theme').onclick = e => openThemeMenu(e.currentTarget);

    const btnAvatar = document.getElementById('btn-avatar');
    btnAvatar.innerHTML = avatar(user?.name, user?.id, 'sm');
    btnAvatar.onclick = () => showMenu(btnAvatar, [
        { label: 'Mis proyectos', icon: 'folder', onClick: () => navigate('/projects') },
        { label: 'Mi cuenta', icon: 'user', onClick: () => navigate('/account') },
        { label: 'Tema', icon: themeIcon(), onClick: () => openThemeMenu(btnAvatar) },
        'sep',
        { label: 'Cerrar sesión', icon: 'logOut', danger: true, onClick: logout },
    ], { title: user?.name ?? '' });
}

function themeIcon() {
    return { light: 'sun', dark: 'moon' }[getTheme()] ?? 'monitor';
}

export function openThemeMenu(anchor) {
    const mark = t => (getTheme() === t ? ' ✓' : '');
    showMenu(anchor, [
        { label: 'Automático' + mark('auto'), icon: 'monitor', onClick: () => { setTheme('auto'); refreshThemeButtons(); } },
        { label: 'Claro' + mark('light'), icon: 'sun', onClick: () => { setTheme('light'); refreshThemeButtons(); } },
        { label: 'Oscuro' + mark('dark'), icon: 'moon', onClick: () => { setTheme('dark'); refreshThemeButtons(); } },
    ], { title: 'Tema' });
}

function refreshThemeButtons() {
    const b = document.getElementById('sidebar-theme');
    if (b) b.innerHTML = icon(themeIcon());
    window.dispatchEvent(new Event('themechange'));
}

function renderSidebar(route) {
    const nav = document.getElementById('sidebar-nav');
    const projects = projectsCache;
    const current = (match) => (match ? 'aria-current="page"' : '');

    let list;
    if (!projects) {
        list = [1, 2, 3].map(() => `<div class="nav-item"><span class="skel" style="width:8px;height:8px;margin:0 5px"></span><span class="skel skel-line" style="width:60%"></span></div>`).join('');
    } else if (projects.length === 0) {
        list = '<div class="nav-item faint small">Sin proyectos</div>';
    } else {
        list = projects.map(p => `
            <a class="nav-item" href="#/project/${esc(p.id)}" ${current(route.id && String(route.id) === String(p.id))} title="${esc(p.name)}">
                <span class="nav-dot" style="--hue:${hueFor('p' + p.id)}"></span>
                <span class="label">${esc(p.name)}</span>
                <span class="nav-meta">${fmtPct(p.percentage)}</span>
            </a>`).join('');
    }

    nav.innerHTML = `
        <a class="nav-item" href="#/projects" ${current(route.view === 'projects')}>${icon('folder')}<span class="label">Mis proyectos</span></a>
        <a class="nav-item" href="#/account" ${current(route.view === 'account')}>${icon('user')}<span class="label">Mi cuenta</span></a>
        <div class="nav-section"><span>Proyectos</span></div>
        ${list}
    `;
}

// ───────────── Boot ─────────────

export function initShell() {
    applyTheme();
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);

    // Hairline under the top bar only once content scrolls beneath it
    const bar = document.getElementById('topbar');
    const onScroll = () => bar.classList.toggle('scrolled', window.scrollY > 4);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
}
