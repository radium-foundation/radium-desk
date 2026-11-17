import { describe, expect, it } from 'vitest';
import { MANTRA_STATUS_BANNER_ID, showMantraHandoffStatus } from '../src/lib/mantra-page-ui.js';

describe('showMantraHandoffStatus', () => {
    it('does not render a banner on successful prefill', () => {
        document.body.innerHTML = '<input id="txtSerialNo" />';
        showMantraHandoffStatus(document, 'success', { serial: 'SYN123' });
        expect(document.getElementById(MANTRA_STATUS_BANNER_ID)).toBeNull();
    });

    it('shows failure or timeout guidance without internal identifiers', () => {
        document.body.innerHTML = '<input id="txtSerialNo" />';
        showMantraHandoffStatus(document, 'failure');
        const banner = document.getElementById(MANTRA_STATUS_BANNER_ID);
        expect(banner?.textContent).toMatch(/serial/i);
        expect(banner?.textContent).not.toMatch(/token|handoff|incident/i);
        expect(banner?.querySelector('button')).toBeNull();
    });

    it('renders only one banner', () => {
        document.body.innerHTML = '';
        showMantraHandoffStatus(document, 'failure');
        showMantraHandoffStatus(document, 'timeout');
        expect(document.querySelectorAll(`#${MANTRA_STATUS_BANNER_ID}`)).toHaveLength(1);
    });
});