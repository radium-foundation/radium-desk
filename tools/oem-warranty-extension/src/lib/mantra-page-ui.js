import { copyMantraSerialToClipboard, MANTRA_CAPTCHA_HIGHLIGHT_CLASS } from './mantra-operator-assist.js';

export const MANTRA_STATUS_BANNER_ID = 'radium-oem-handoff-banner';
const MANTRA_ASSIST_STYLE_ID = 'radium-mantra-operator-assist-style';

const BANNER_STYLE_BASE = 'margin:8px 0;padding:8px;font-size:13px;border-radius:4px;';

const ensureMantraAssistStyles = (doc) => {
    if (doc.getElementById(MANTRA_ASSIST_STYLE_ID)) {
        return;
    }

    const style = doc.createElement('style');
    style.id = MANTRA_ASSIST_STYLE_ID;
    style.textContent = `
.${MANTRA_CAPTCHA_HIGHLIGHT_CLASS} {
  outline: 2px solid #0d6efd;
  outline-offset: 2px;
  background-color: #f8f9fa;
}
`;
    doc.head?.appendChild(style);
};

/**
 * @typedef {'success' | 'failure' | 'timeout'} MantraPageStatusTone
 */

/** @type {Record<MantraPageStatusTone, { bg: string, border: string, text: string }>} */
const TONE_STYLES = {
    success: {
        bg: '#d4edda',
        border: '#c3e6cb',
        text: 'Serial number filled. Complete the CAPTCHA and click Check to view warranty details.',
    },
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
 * @param {Document} doc
 * @param {MantraPageStatusTone} tone
 * @param {{ serial?: string }} [options]
 */
export function showMantraHandoffStatus(doc, tone, options = {}) {
    if (doc.getElementById(MANTRA_STATUS_BANNER_ID)) {
        return;
    }

    ensureMantraAssistStyles(doc);

    const style = TONE_STYLES[tone];
    const el = doc.createElement('div');
    el.id = MANTRA_STATUS_BANNER_ID;
    el.setAttribute('role', 'status');
    el.style.cssText = `${BANNER_STYLE_BASE}background:${style.bg};border:1px solid ${style.border};`;

    if (tone === 'success') {
        el.innerHTML = `
            <p class="mb-1 mb-sm-2">${style.text}</p>
            <ol class="mb-2 ps-3" style="font-size:12px;">
                <li>Type the <strong>CAPTCHA</strong> shown on this page (not automated).</li>
                <li>Click <strong>Check</strong> yourself.</li>
                <li>Return to the Desk tab after results appear.</li>
            </ol>
        `;

        if (options.serial) {
            const copyBtn = doc.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'btn btn-sm btn-outline-secondary';
            copyBtn.textContent = 'Copy serial';
            copyBtn.addEventListener('click', async () => {
                const result = await copyMantraSerialToClipboard(options.serial);
                copyBtn.textContent = result.ok ? 'Serial copied' : 'Copy failed — select serial manually';
            });
            el.appendChild(copyBtn);
        }
    } else {
        el.textContent = style.text;
    }

    const anchor = doc.getElementById('txtSerialNo')?.parentElement ?? doc.body;
    anchor.prepend(el);
}

/**
 * @param {Document} doc
 * @param {boolean} entered
 */
export function updateMantraCaptchaEntryHint(doc, entered) {
    const banner = doc.getElementById(MANTRA_STATUS_BANNER_ID);
    if (!banner) {
        return;
    }

    const hint = banner.querySelector('[data-radium-captcha-hint]');
    if (!entered) {
        hint?.remove();
        return;
    }

    if (hint) {
        return;
    }

    const p = doc.createElement('p');
    p.dataset.radiumCaptchaHint = '1';
    p.className = 'mb-0 mt-2';
    p.style.fontSize = '12px';
    p.textContent = 'CAPTCHA field has text — click Check when ready (Desk does not submit for you).';
    banner.appendChild(p);
}