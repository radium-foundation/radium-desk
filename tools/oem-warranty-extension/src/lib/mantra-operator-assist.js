/**
 * Operator assistance on the Mantra warranty page — serial/CAPTCHA ergonomics only.
 * Never reads CAPTCHA images, never submits Check, never autofills captcha values.
 */

export const MANTRA_CAPTCHA_FIELD_SELECTORS = [
    '#txtCaptcha',
    '#txtVerifyCode',
    'input[name*="Captcha" i]',
    'input[id*="Captcha" i]',
];

export const MANTRA_CAPTCHA_HIGHLIGHT_CLASS = 'radium-mantra-captcha-highlight';

/**
 * @param {Document} doc
 * @returns {HTMLInputElement | null}
 */
export function findMantraCaptchaField(doc) {
    for (const selector of MANTRA_CAPTCHA_FIELD_SELECTORS) {
        const el = doc.querySelector(selector);
        if (el instanceof HTMLInputElement) {
            return el;
        }
    }

    return null;
}

/**
 * @param {Document} doc
 * @returns {{ focused: boolean, field: HTMLInputElement | null }}
 */
export function focusMantraCaptchaField(doc) {
    const field = findMantraCaptchaField(doc);
    if (!field) {
        return { focused: false, field: null };
    }

    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
    field.focus({ preventScroll: true });

    return { focused: true, field };
}

/**
 * @param {Document} doc
 */
export function highlightMantraCaptchaField(doc) {
    const field = findMantraCaptchaField(doc);
    if (!field) {
        return;
    }

    field.classList.add(MANTRA_CAPTCHA_HIGHLIGHT_CLASS);
    field.setAttribute('aria-describedby', 'radium-oem-handoff-banner');
}

/**
 * Visual-only hint when operator typed into CAPTCHA (does not validate correctness).
 *
 * @param {Document} doc
 * @param {(entered: boolean) => void} onChange
 * @returns {() => void}
 */
export function watchMantraCaptchaEntry(doc, onChange) {
    const field = findMantraCaptchaField(doc);
    if (!field) {
        return () => {};
    }

    const handler = () => {
        onChange(field.value.trim().length > 0);
    };

    field.addEventListener('input', handler);
    field.addEventListener('change', handler);

    return () => {
        field.removeEventListener('input', handler);
        field.removeEventListener('change', handler);
    };
}

/**
 * @param {string} serial
 * @returns {Promise<{ ok: true } | { ok: false, reason: string }>}
 */
export async function copyMantraSerialToClipboard(serial) {
    const normalized = String(serial).trim().toUpperCase();
    if (normalized === '') {
        return { ok: false, reason: 'serial-empty' };
    }

    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(normalized);
            return { ok: true };
        } catch {
            return { ok: false, reason: 'clipboard-denied' };
        }
    }

    return { ok: false, reason: 'clipboard-unavailable' };
}