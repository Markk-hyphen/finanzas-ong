import { esc } from './html.js';
import { icon } from './ui/icons.js';

export function navigate(path) {
    // Assigning the current hash fires no hashchange, so "go back to the same
    // view" after an action (confirm, reject, cancel...) would not re-render it.
    if (location.hash === '#' + path) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        return;
    }
    location.hash = '#' + path;
}

/**
 * Top bar state for the current view.
 *   title  — shown in the phone top bar once the page title scrolls away
 *   back   — route of the back button (phones)
 *   crumbs — desktop breadcrumbs: [{ label, href? }]; defaults to [title]
 */
export function setNav({ title = 'Finanzas ONG', back = null, crumbs = null } = {}) {
    const bar = document.getElementById('topbar');
    document.title = title === 'Finanzas ONG' ? title : `${title} · Finanzas ONG`;
    document.getElementById('nav-title').textContent = title;

    const btnBack = document.getElementById('btn-back');
    bar.classList.toggle('has-back', !!back);
    btnBack.classList.toggle('hidden', !back);
    btnBack.onclick = back ? () => navigate(back) : null;
    document.getElementById('topbar-brand').classList.toggle('hidden', !!back);

    const trail = crumbs ?? [{ label: title }];
    document.getElementById('crumbs').innerHTML = trail.map((c, i) => {
        const last = i === trail.length - 1;
        const node = last || !c.href
            ? `<span class="${last ? 'current truncate' : ''}">${esc(c.label)}</span>`
            : `<a href="#${esc(c.href)}">${esc(c.label)}</a>`;
        return (i ? icon('chevronRight') : '') + node;
    }).join('');
}

let titleObserver = null;

// Phone top bar: reveal the small title only when the page's big title is out
// of view. Views without a .page-title get the small title right away.
export function watchPageTitle(root) {
    titleObserver?.disconnect();
    const bar = document.getElementById('topbar');
    const big = root.querySelector('.page-title');
    if (!big) {
        bar.classList.add('show-title');
        return;
    }
    bar.classList.remove('show-title');
    titleObserver = new IntersectionObserver(([entry]) => {
        bar.classList.toggle('show-title', !entry.isIntersecting);
    }, { rootMargin: '-64px 0px 0px 0px' });
    titleObserver.observe(big);
}
