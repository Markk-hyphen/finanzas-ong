import { storage } from './api.js';
import { stopNotifications } from './notifications.js';

export function navigate(path) {
    // Assigning the current hash fires no hashchange, so "go back to the same
    // view" after an action (confirm, reject, cancel...) would not re-render it.
    if (location.hash === '#' + path) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        return;
    }
    location.hash = '#' + path;
}

export function setNav({ title = 'Finanzas ONG', back = null, logout = false } = {}) {
    document.getElementById('nav-title').textContent = title;

    const btnBack = document.getElementById('btn-back');
    if (back) {
        btnBack.classList.remove('d-none');
        btnBack.onclick = () => navigate(back);
    } else {
        btnBack.classList.add('d-none');
        btnBack.onclick = null;
    }

    const btnLogout = document.getElementById('btn-logout');
    if (logout) {
        btnLogout.classList.remove('d-none');
        btnLogout.onclick = () => { stopNotifications(); storage.clear(); navigate('/login'); };
    } else {
        btnLogout.classList.add('d-none');
        btnLogout.onclick = null;
    }
}
