/**
 * Morpho serial page — DOM-only sync (no Selectize API, no blur).
 *
 * Avoids page CheckValidation / rdonline.rdms.co.in AJAX. Staff may still need to
 * focus the field before Submit enables (OEM client logic).
 *
 * @param {Document} doc
 * @param {string} serial
 * @returns {{ ok: true, mode: 'dom-only' } | { ok: false, reason: string }}
 */
export function populateMorphoSerialFieldDomOnly(doc, serial) {
    const base = doc.getElementById('device-serial-number1');
    const visible = doc.getElementById('device-serial-number1-selectized');

    if (!base || !(base instanceof HTMLInputElement)) {
        return { ok: false, reason: 'device-serial-number1-missing' };
    }

    const normalized = String(serial).trim().toUpperCase();
    if (normalized === '') {
        return { ok: false, reason: 'serial-empty' };
    }

    base.value = normalized;
    base.dispatchEvent(new Event('input', { bubbles: true }));

    if (visible instanceof HTMLInputElement) {
        visible.value = normalized;
    }

    return { ok: true, mode: 'dom-only' };
}

/** Integration path that triggers OEM validation AJAX — intentionally not implemented. */
export const MORPHO_SELECTIZE_AJAX_PATH = 'disabled';
