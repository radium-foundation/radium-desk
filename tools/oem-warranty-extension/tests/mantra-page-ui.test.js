import { describe, expect, it } from 'vitest';
import { MANTRA_STATUS_BANNER_ID, showMantraHandoffStatus } from '../src/lib/mantra-page-ui.js';

describe('showMantraHandoffStatus', () => {
    it('shows success guidance without internal identifiers', () => {
        document.body.innerHTML = '<input id="txtSerialNo" />';
        showMantraHandoffStatus(document, 'success', { serial: 'SYN123' });
        const banner = document.getElementById(MANTRA_STATUS_BANNER_ID);
        expect(banner?.textContent).toMatch(/CAPTCHA/i);
        expect(banner?.querySelector('button')?.textContent).toMatch(/Copy serial/i);
        expect(banner?.textContent).not.toMatch(/token|handoff|incident/i);
    });

    it('renders only one banner', () => {
        document.body.innerHTML = '';
        showMantraHandoffStatus(document, 'failure');
        showMantraHandoffStatus(document, 'timeout');
        expect(document.querySelectorAll(`#${MANTRA_STATUS_BANNER_ID}`)).toHaveLength(1);
    });
});