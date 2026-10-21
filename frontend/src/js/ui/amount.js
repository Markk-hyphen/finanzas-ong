import { esc } from '../html.js';
import { fmt, parseAmount } from './format.js';

// Big centered money input shared by the expense and payment forms
export function amountField({ id, label }) {
    return `
        <label class="amount-field" for="${esc(id)}">
            <span class="amount-label">${esc(label)}</span>
            <span class="amount-row">
                <span class="currency">$</span>
                <input type="text" id="${esc(id)}" inputmode="decimal" autocomplete="off" placeholder="0,00" aria-describedby="${esc(id)}-echo" />
            </span>
            <span class="amount-echo" id="${esc(id)}-echo" aria-live="polite"></span>
        </label>`;
}

/**
 * Wires the echo line: it always shows how the typed text was understood
 * ("1.500" → "$ 1.500,00"), so nobody sends 1,50 thinking it was 1.500.
 */
export function bindAmountField(root, id, onChange = () => {}) {
    const input = root.querySelector('#' + id);
    const field = input.closest('.amount-field');
    const echo = root.querySelector(`#${id}-echo`);

    const update = () => {
        const raw = input.value.trim();
        const parsed = parseAmount(raw);
        field.classList.toggle('invalid', raw !== '' && !parsed);
        echo.textContent = !raw ? '' : parsed ? fmt(parsed) : 'Monto inválido (hasta 2 decimales)';
        // Grow with the content so the currency sign stays next to the number,
        // and shrink the font a bit for long numbers so they never overflow
        input.style.width = `${Math.max(raw.length || 4, 1) + 0.6}ch`;
        input.style.fontSize = raw.length > 9 ? 'var(--fs-2xl)' : '';
        onChange(parsed);
    };

    input.addEventListener('input', () => {
        // Only digits and separators
        const clean = input.value.replace(/[^\d.,]/g, '');
        if (clean !== input.value) input.value = clean;
        update();
    });

    update();
    setTimeout(() => input.focus({ preventScroll: true }), 50);
    return { input, update, set(value) { input.value = value; update(); } };
}
