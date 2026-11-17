export const MANTRA_STATUS_BANNER_ID = 'radium-oem-handoff-banner';

const BANNER_STYLE_BASE = 'margin:8px 0;padding:8px;font-size:13px;border-radius:4px;';

/**
 * @typedef {'success' | 'failure' | 'timeout'} MantraPageStatusTone
 */

/** @type {Record<Exclude<MantraPageStatusTone, 'success'>, { bg: string; border: string; text: string }>} */
const TONE_STYLES = {
    failure: {
        bg: '#fff3cd',
        border: '#ffeeba',
        text: 'Serial could not be filled automatically. Enter the serial from Desk manually on this page.',
    },
    timeout: {
        bg: '#f8d7da',
        border: '#f5c6cb',
        text: 'Warranty form did not load correctly. Refresh this page or contact IT if it persists.',
    },
};

/**
 * Error-only status banner. Successful prefill does not inject UI (P-17-16).
 *
 * @param {Document} doc
 * @param {MantraPageStatusTone} tone
 */
export function showMantraHandoffStatus(doc, tone, _options = {}) {
    if (tone === 'success') {
        return;
    }

    if (doc.getElementById(MANTRA_STATUS_BANNER_ID)) {
        return;
    }

    const style = TONE_STYLES[tone];
    if (!style) {
        return;
    }

    const el = doc.createElement('div');
    el.id = MANTRA_STATUS_BANNER_ID;
    el.setAttribute('role', 'status');
    el.style.cssText = `${BANNER_STYLE_BASE}background:${style.bg};border:1px solid ${style.border};`;
    el.textContent = style.text;

    const anchor = doc.getElementById('txtSerialNo')?.parentElement ?? doc.body;
    anchor.prepend(el);
}