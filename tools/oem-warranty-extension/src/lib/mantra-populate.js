/**
 * Mantra Servico warranty page — serial field only. Does not touch captcha or submit.
 *
 * @param {Document} doc
 * @param {string} serial
 * @returns {{ ok: true } | { ok: false, reason: string }}
 */
export function populateMantraSerialField(doc, serial) {
    const el = doc.getElementById('txtSerialNo');
    if (!el || !(el instanceof HTMLInputElement)) {
        return { ok: false, reason: 'txtSerialNo-missing' };
    }
    const normalized = String(serial).trim().toUpperCase();
    if (normalized === '') {
        return { ok: false, reason: 'serial-empty' };
    }
    el.value = normalized;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return { ok: true };
}
