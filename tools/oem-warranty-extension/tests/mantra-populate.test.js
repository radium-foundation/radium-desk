import { describe, expect, it } from 'vitest';
import { populateMantraSerialField } from '../src/lib/mantra-populate.js';

describe('populateMantraSerialField', () => {
    it('fills txtSerialNo and leaves captcha empty', () => {
        document.body.innerHTML = `
            <input id="txtSerialNo" type="text" />
            <input id="txtCaptcha" type="text" />
            <button id="btnCheck">Check</button>
        `;

        const result = populateMantraSerialField(document, 'syn-6419897');
        expect(result).toEqual({ ok: true });
        expect(document.getElementById('txtSerialNo').value).toBe('SYN-6419897');
        expect(document.getElementById('txtCaptcha').value).toBe('');
    });

    it('does not click submit controls', () => {
        let clicked = false;
        document.body.innerHTML = '<input id="txtSerialNo" /><button id="btnCheck">Check</button>';
        document.getElementById('btnCheck').addEventListener('click', () => {
            clicked = true;
        });

        populateMantraSerialField(document, 'TEST-1');
        expect(clicked).toBe(false);
    });
});
