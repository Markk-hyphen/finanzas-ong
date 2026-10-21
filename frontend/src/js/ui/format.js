import { esc } from '../html.js';

const LOCALE = 'es-AR';

const money2 = new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function fmt(amount) {
    return '$ ' + money2.format(Number(amount));
}

// Hero-size amount: integer part big, cents smaller (HTML, already escaped)
export function fmtBig(amount) {
    const [int, dec] = money2.format(Math.abs(Number(amount))).split(',');
    return `<span class="cur">$</span>${int}<span class="dec">,${dec}</span>`;
}

export function fmtQty(q) {
    const n = Number(q);
    if (!Number.isFinite(n)) return esc(q);
    return n.toLocaleString(LOCALE, { maximumFractionDigits: 3 });
}

export function fmtPct(p) {
    return Number(p).toLocaleString(LOCALE, { maximumFractionDigits: 2 }) + '%';
}

/**
 * Parses what a person types as an amount into the API's "1234.56" format, or
 * null if it is not a valid positive amount with at most 2 decimals.
 * Accepts both "1.234,56" (es-AR) and "1234.56"; a lone dot followed by exactly
 * 3 digits ("1.500") is read as a thousands separator, as anyone in Argentina
 * would mean it.
 */
export function parseAmount(raw) {
    let s = String(raw ?? '').trim().replace(/\s|\$/g, '');
    if (!s) return null;
    if (s.includes(',')) {
        s = s.replace(/\./g, '').replace(',', '.');
    } else {
        const dots = (s.match(/\./g) || []).length;
        if (dots > 1 || /^\d{1,3}\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
    }
    if (!/^\d+(\.\d{1,2})?$/.test(s)) return null;
    const n = Number(s);
    if (!(n > 0)) return null;
    return n.toFixed(2);
}

export function fmtDate(iso) {
    return new Date(iso).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
}

export function fmtTime(iso) {
    return new Date(iso).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
}

export function fmtDateTime(iso) {
    return new Date(iso).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function startOfDay(d) {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x;
}

// "Hoy", "Ayer", "Martes", "12 de octubre", "12 oct 2025"
export function dayLabel(iso) {
    const d = new Date(iso);
    const days = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
    if (days === 0) return 'Hoy';
    if (days === 1) return 'Ayer';
    if (days > 1 && days < 7) {
        const w = d.toLocaleDateString(LOCALE, { weekday: 'long' });
        return w.charAt(0).toUpperCase() + w.slice(1);
    }
    const sameYear = d.getFullYear() === new Date().getFullYear();
    return d.toLocaleDateString(LOCALE, sameYear ? { day: 'numeric', month: 'long' } : { day: 'numeric', month: 'short', year: 'numeric' });
}

const rtf = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' });

export function timeAgo(iso) {
    const secs = Math.round((new Date(iso) - Date.now()) / 1000);
    const abs = Math.abs(secs);
    if (abs < 45) return 'recién';
    if (abs < 3600) return rtf.format(Math.round(secs / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(secs / 3600), 'hour');
    if (abs < 86400 * 7) return rtf.format(Math.round(secs / 86400), 'day');
    return fmtDate(iso);
}

// Future deadline: "en 3 h", "mañana"
export function timeUntil(iso) {
    const secs = Math.round((new Date(iso) - Date.now()) / 1000);
    if (secs <= 0) return 'ya';
    if (secs < 3600) return rtf.format(Math.max(1, Math.round(secs / 60)), 'minute');
    if (secs < 86400) return rtf.format(Math.round(secs / 3600), 'hour');
    return rtf.format(Math.round(secs / 86400), 'day');
}

export function initials(name) {
    const parts = String(name ?? '?').trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? parts[0][0] + parts[parts.length - 1][0] : (parts[0] ?? '?').slice(0, 2);
    return esc(letters.toUpperCase());
}

// Stable color per id/name so a person always gets the same avatar color
export function hueFor(key) {
    const s = String(key ?? '');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return [250, 160, 20, 200, 300, 120, 340, 45, 180, 270][h % 10];
}

export function avatar(name, key = name, size = '') {
    return `<span class="avatar ${size}" style="--hue:${hueFor(key)}" aria-hidden="true">${initials(name)}</span>`;
}
