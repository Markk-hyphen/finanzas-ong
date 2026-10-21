import './style.css';

import { storage } from './js/api.js';
import { esc } from './js/html.js';
import { navigate, watchPageTitle } from './js/nav.js';
import { closePanel as closeNotifications, initNotifications } from './js/notifications.js';
import { initShell, renderShell } from './js/shell.js';
import { icon } from './js/ui/icons.js';
import { closeMenu } from './js/ui/feedback.js';
import { renderLogin }     from './js/views/login.js';
import { renderProjects }  from './js/views/projects.js';
import { renderDashboard } from './js/views/dashboard.js';
import { renderExpense }   from './js/views/expense.js';
import { renderPayment }   from './js/views/payment.js';
import { renderSettings }  from './js/views/settings.js';
import { renderAccount }   from './js/views/account.js';

function parseRoute(hash) {
    const path  = (hash.replace(/^#/, '') || '/login');
    const parts = path.replace(/^\//, '').split('/');
    const [s0, s1, s2] = parts;

    if (!s0 || s0 === 'login')   return { view: 'login' };
    if (s0 === 'projects')       return { view: 'projects' };
    if (s0 === 'account')        return { view: 'account' };
    if (s0 === 'project' && s1) {
        if (!s2)                 return { view: 'dashboard', id: s1 };
        if (s2 === 'expense')    return { view: 'expense',   id: s1 };
        if (s2 === 'payment')    return { view: 'payment',   id: s1 };
        if (s2 === 'settings')   return { view: 'settings',  id: s1 };
    }
    return { view: 'login' };
}

// Placeholder shaped like the view being loaded, so the layout does not jump
function skeleton(view) {
    const line = (w, h = 12) => `<div class="skel skel-line" style="width:${w};height:${h}px"></div>`;
    const card = (rows) => `<div class="card" style="padding:1.25rem;display:grid;gap:1rem">${Array.from({ length: rows }, () =>
        `<div style="display:flex;gap:.85rem;align-items:center"><div class="skel skel-circle" style="width:36px;height:36px"></div><div style="flex:1;display:grid;gap:.45rem">${line('45%')}${line('25%', 10)}</div>${line('70px')}</div>`).join('')}</div>`;

    if (view === 'dashboard') {
        return `<div class="skeleton-page">
            <div style="display:grid;gap:.6rem;margin:.5rem 0 1.5rem">${line('220px', 28)}${line('160px', 14)}</div>
            <div class="dash-stats">${'<div class="skel" style="height:118px;border-radius:16px"></div>'.repeat(3)}</div>
            <div class="dash-grid" style="margin-top:1.25rem"><div>${card(5)}</div><div>${card(3)}</div></div>
        </div>`;
    }
    if (view === 'projects') {
        return `<div style="display:grid;gap:.6rem;margin:.5rem 0 1.5rem">${line('200px', 28)}${line('140px', 14)}</div>
            <div class="project-grid">${'<div class="skel" style="height:148px;border-radius:16px"></div>'.repeat(3)}</div>`;
    }
    return `<div class="form-page"><div style="display:grid;gap:.6rem;margin:.5rem 0 1.5rem">${line('200px', 28)}${line('260px', 14)}</div>
        <div class="skel" style="height:320px;border-radius:16px"></div></div>`;
}

let renderSeq = 0;

async function dispatch() {
    const route = parseRoute(location.hash);
    const { view, id } = route;
    const el = document.getElementById('app');
    const seq = ++renderSeq;

    if (!storage.getToken() && view !== 'login') {
        navigate('/login');
        return;
    }

    closeMenu();
    closeNotifications();
    document.body.classList.remove('has-actionbar');
    document.querySelector('.actionbar')?.remove();

    renderShell(route);
    initNotifications();

    el.classList.remove('view-enter');
    el.innerHTML = skeleton(view);
    window.scrollTo({ top: 0, behavior: 'instant' });

    try {
        switch (view) {
            case 'login':     await renderLogin(el);             break;
            case 'projects':  await renderProjects(el);          break;
            case 'dashboard': await renderDashboard(el, id);     break;
            case 'expense':   await renderExpense(el, id);       break;
            case 'payment':   await renderPayment(el, id);       break;
            case 'settings':  await renderSettings(el, id);      break;
            case 'account':   await renderAccount(el);           break;
            default:          navigate('/login');
        }
    } catch (e) {
        if (seq !== renderSeq) return;
        if (e.message !== 'Sesión expirada. Iniciá sesión nuevamente.') {
            el.innerHTML = `
                <div class="card" style="margin-top:1rem">
                    <div class="empty">
                        <div class="empty-icon">${icon('alert')}</div>
                        <div class="empty-title">No pudimos cargar esta pantalla</div>
                        <div class="empty-text">${esc(e.message)}</div>
                        <button class="btn btn-secondary btn-sm" style="margin-top:.5rem" id="btn-retry">${icon('refresh')} Reintentar</button>
                    </div>
                </div>`;
            document.getElementById('btn-retry').onclick = dispatch;
        }
    }

    if (seq !== renderSeq) return;
    // Restart the entrance animation for the freshly rendered view
    void el.offsetWidth;
    el.classList.add('view-enter');
    watchPageTitle(el);
}

initShell();
window.addEventListener('hashchange', dispatch);
window.addEventListener('load', dispatch);
