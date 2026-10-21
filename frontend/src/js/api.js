const TOKEN_KEY = 'ong_jwt';
const USER_KEY  = 'ong_user';

export const storage = {
    getToken:  ()  => localStorage.getItem(TOKEN_KEY),
    setToken:  (t) => localStorage.setItem(TOKEN_KEY, t),

    getUser:   ()  => { const s = localStorage.getItem(USER_KEY); return s ? JSON.parse(s) : null; },
    setUser:   (u) => localStorage.setItem(USER_KEY, JSON.stringify(u)),

    clear:     ()  => { localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(USER_KEY); },
    isAdmin:   ()  => (storage.getUser()?.roles ?? []).includes('ROLE_ADMIN'),
};

async function request(method, path, body = null) {
    const headers = { 'Content-Type': 'application/json' };
    const token = storage.getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const resp = await fetch('/api' + path, {
        method,
        headers,
        body: body !== null ? JSON.stringify(body) : undefined,
    });

    if (resp.status === 401) {
        storage.clear();
        location.hash = '#/login';
        throw new Error('Sesión expirada. Iniciá sesión nuevamente.');
    }

    let data = {};
    const text = await resp.text();
    if (text) { try { data = JSON.parse(text); } catch {} }

    if (!resp.ok) {
        throw Object.assign(new Error(data.error || `Error ${resp.status}`), { status: resp.status, data });
    }

    return data;
}

export const api = {
    login: async (login, password) => {
        const r = await fetch('/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ login, password }),
        });
        const d = await r.json();
        // Bad credentials stay generic; account-status messages (e.g. deactivated) are shown as sent
        if (!r.ok) throw new Error(d.message && d.message !== 'Invalid credentials.' ? d.message : 'Usuario o contraseña incorrectos.');
        return d;
    },

    me:             ()          => request('GET',  '/me'),
    getProjects:    ()          => request('GET',  '/projects'),
    getDashboard:   (id)        => request('GET',  `/projects/${id}/dashboard`),
    getOverview:    (id)        => request('GET',  `/projects/${id}/overview`),
    getMembers:     (id)        => request('GET',  `/projects/${id}/members`),
    createExpense:  (id, body)  => request('POST', `/projects/${id}/expenses`, body),
    previewPayment: (id, body)  => request('POST', `/projects/${id}/payments/preview`, body),
    createPayment:  (id, body)  => request('POST', `/projects/${id}/payments`, body),
    confirmPayment: (batchId)   => request('POST', `/payments/${batchId}/confirm`),
    cancelMovement: (id, body)  => request('POST', `/movements/${id}/cancel`, body),
    withdrawPayment: (batchId)  => request('POST', `/payments/${batchId}/withdraw`),
    rejectPayment:  (batchId, body) => request('POST', `/payments/${batchId}/reject`, body),
    undoExpense:    (id)        => request('POST', `/expenses/${id}/undo`),
    getCapabilities: ()         => request('GET',  '/instance/capabilities'),
    updateSettings: (id, body)  => request('PATCH', `/projects/${id}/settings`, body),
    getMaterials:   (q)         => request('GET',  `/materials?q=${encodeURIComponent(q)}`),
    // Idempotent: returns the existing material when the normalized name already exists
    createMaterial: (name)      => request('POST', '/materials', { name }),
    getNotifications:        ()   => request('GET',  '/notifications'),
    markNotificationRead:    (id) => request('POST', `/notifications/${id}/read`),
    getNotificationsToken:   ()   => request('GET',  '/notifications/subscription-token'),
    getNotificationChannels: ()   => request('GET',  '/me/notification-channels'),
    linkTelegram:            ()   => request('POST', '/me/telegram/link'),
    checkTelegram:           ()   => request('POST', '/me/telegram/check'),
    unlinkTelegram:          ()   => request('DELETE', '/me/telegram'),
};
