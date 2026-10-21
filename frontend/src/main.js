import 'bootstrap/dist/css/bootstrap.min.css';
import './style.css';

import { storage } from './js/api.js';
import { esc } from './js/html.js';
import { navigate } from './js/nav.js';
import { renderLogin }     from './js/views/login.js';
import { renderProjects }  from './js/views/projects.js';
import { renderDashboard } from './js/views/dashboard.js';
import { renderExpense }   from './js/views/expense.js';
import { renderPayment }   from './js/views/payment.js';
import { renderSettings }  from './js/views/settings.js';

function parseRoute(hash) {
    const path  = (hash.replace(/^#/, '') || '/login');
    const parts = path.replace(/^\//, '').split('/');
    const [s0, s1, s2] = parts;

    if (!s0 || s0 === 'login')   return { view: 'login' };
    if (s0 === 'projects')       return { view: 'projects' };
    if (s0 === 'project' && s1) {
        if (!s2)                 return { view: 'dashboard', id: s1 };
        if (s2 === 'expense')    return { view: 'expense',   id: s1 };
        if (s2 === 'payment')    return { view: 'payment',   id: s1 };
        if (s2 === 'settings')   return { view: 'settings',  id: s1 };
    }
    return { view: 'login' };
}

const spinner = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';

async function dispatch() {
    const { view, id } = parseRoute(location.hash);
    const el = document.getElementById('app');
    el.innerHTML = spinner;

    if (!storage.getToken() && view !== 'login') {
        navigate('/login');
        return;
    }

    try {
        switch (view) {
            case 'login':     await renderLogin(el);             break;
            case 'projects':  await renderProjects(el);          break;
            case 'dashboard': await renderDashboard(el, id);     break;
            case 'expense':   await renderExpense(el, id);       break;
            case 'payment':   await renderPayment(el, id);       break;
            case 'settings':  await renderSettings(el, id);      break;
            default:          navigate('/login');
        }
    } catch (e) {
        if (e.message !== 'Sesión expirada. Iniciá sesión nuevamente.') {
            el.innerHTML = `<div class="alert alert-danger mt-3">${esc(e.message)}</div>`;
        }
    }
}

window.addEventListener('hashchange', dispatch);
window.addEventListener('load', dispatch);
