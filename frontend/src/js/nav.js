import { storage } from './api.js';

export function navigate(path) {
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
        btnLogout.onclick = () => { storage.clear(); navigate('/login'); };
    } else {
        btnLogout.classList.add('d-none');
        btnLogout.onclick = null;
    }
}
