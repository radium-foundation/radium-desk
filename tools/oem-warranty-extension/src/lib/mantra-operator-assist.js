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

export const MANTRA_CAPTCHA_FIELD_MAX_WAIT_MS = 10_000;

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
 * @param {number} maxMs
 * @param {{ observe?: MutationObserver, now?: () => number }} [hooks]
 */
export function waitForMantraCaptchaField(doc, maxMs, hooks = {}) {
    const immediate = findMantraCaptchaField(doc);
    if (immediate) {
        return Promise.resolve(immediate);
    }

    const observeRoot = doc.body ?? doc.documentElement;
    if (!observeRoot) {
        return Promise.reject(new Error('timeout'));
    }

    const now = hooks.now ?? (() => Date.now());
    const deadline = now() + maxMs;

    return new Promise((resolve, reject) => {
        /** @type {MutationObserver | null} */
        let observer = null;
        let timeoutId = null;
        let settled = false;

        const finish = (/** @type {'resolve' | 'reject'} */ kind, /** @type {unknown} */ value) => {
            if (settled) {
                return;
            }
            settled = true;
            observer?.disconnect();
            if (timeoutId != null) {
                clearTimeout(timeoutId);
            }
            if (kind === 'resolve') {
                resolve(value);
            } else {
                reject(value);
            }
        };

        const tryResolve = () => {
            const el = findMantraCaptchaField(doc);
            if (el) {
                finish('resolve', el);
                return true;
            }
            if (now() >= deadline) {
                finish('reject', new Error('timeout'));
                return true;
            }
            return false;
        };

        observer = hooks.observe ?? new MutationObserver(() => {
            tryResolve();
        });
        observer.observe(observeRoot, { childList: true, subtree: true });

        timeoutId = setTimeout(() => {
            tryResolve();
        }, Math.max(0, deadline - now()));
    });
}

/**
 * @param {HTMLInputElement} field
 * @returns {boolean}
 */
export function isMantraCaptchaFieldEntered(field) {
    return field.value.trim().length > 0;
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
 * Re-binds when the portal replaces the input (ASP.NET / partial postback).
 *
 * @param {Document} doc
 * @param {(entered: boolean) => void} onChange
 * @returns {() => void}
 */
export function watchMantraCaptchaEntry(doc, onChange) {
    /** @type {HTMLInputElement | null} */
    let bound = null;

    /** @type {() => void} */
    let unbindCurrent = () => {};

    const bindField = (/** @type {HTMLInputElement} */ field) => {
        if (bound === field) {
            onChange(isMantraCaptchaFieldEntered(field));
            return;
        }

        unbindCurrent();
        bound = field;

        const notify = () => {
            onChange(isMantraCaptchaFieldEntered(field));
        };

        const onInput = () => notify();
        field.addEventListener('input', onInput);
        field.addEventListener('change', onInput);
        field.addEventListener('paste', onInput);
        field.addEventListener('keyup', onInput);

        notify();

        unbindCurrent = () => {
            field.removeEventListener('input', onInput);
            field.removeEventListener('change', onInput);
            field.removeEventListener('paste', onInput);
            field.removeEventListener('keyup', onInput);
            if (bound === field) {
                bound = null;
            }
        };
    };

    const tryBind = () => {
        const field = findMantraCaptchaField(doc);
        if (field) {
            bindField(field);
            return;
        }

        if (bound) {
            unbindCurrent();
            onChange(false);
        }
    };

    tryBind();

    const observeRoot = doc.body ?? doc.documentElement;
    /** @type {MutationObserver | null} */
    let observer = null;
    if (observeRoot) {
        observer = new MutationObserver(() => {
            tryBind();
        });
        observer.observe(observeRoot, { childList: true, subtree: true });
    }

    return () => {
        unbindCurrent();
        observer?.disconnect();
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