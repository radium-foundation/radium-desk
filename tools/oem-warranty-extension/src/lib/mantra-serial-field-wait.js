import { populateMantraSerialField } from './mantra-populate.js';

export const MANTRA_SERIAL_FIELD_MAX_WAIT_MS = 10_000;

/**
 * @param {Document} doc
 * @param {string} selector
 * @param {number} maxMs
 * @param {{ observe?: MutationObserver, now?: () => number, sleep?: (ms: number) => Promise<void> }} [hooks]
 */
export function waitForMantraSerialField(doc, selector, maxMs, hooks = {}) {
    const existing = doc.querySelector(selector);
    if (existing) {
        return Promise.resolve(existing);
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
            const el = doc.querySelector(selector);
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
 * @param {Document} doc
 * @param {string} serial
 * @returns {{ ok: true } | { ok: false, reason: string }}
 */
export function fillMantraSerialOnce(doc, serial) {
    const result = populateMantraSerialField(doc, serial);
    if (!result.ok) {
        return result;
    }

    const normalized = String(serial).trim().toUpperCase();
    const field = doc.getElementById('txtSerialNo');
    if (!(field instanceof HTMLInputElement) || field.value !== normalized) {
        return { ok: false, reason: 'post-fill-verification-failed' };
    }

    return { ok: true };
}