import { describe, expect, it, vi } from 'vitest';
import { populateMorphoSerialFieldDomOnly } from '../src/lib/morpho-populate.js';

describe('populateMorphoSerialFieldDomOnly', () => {
    it('syncs base and selectized inputs without network', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        document.body.innerHTML = `
            <input id="device-serial-number1" type="text" />
            <input id="device-serial-number1-selectized" type="text" />
            <button id="valdt-btn" disabled>Submit</button>
        `;

        const selectized = document.getElementById('device-serial-number1-selectized');
        selectized.addEventListener('blur', () => {
            fetch('https://rdonline.rdms.co.in/OTBSValidatorService/apiconnector/DeviceValidator/TEST');
        });

        const result = populateMorphoSerialFieldDomOnly(document, '2441I041803');
        expect(result).toEqual({ ok: true, mode: 'dom-only' });
        expect(document.getElementById('device-serial-number1').value).toBe('2441I041803');
        expect(selectized.value).toBe('2441I041803');
        expect(fetchSpy).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('does not enable submit or call selectize validation path', () => {
        document.body.innerHTML = `
            <input id="device-serial-number1" type="text" />
            <input id="device-serial-number1-selectized" type="text" />
            <button id="valdt-btn" disabled>Submit</button>
        `;
        populateMorphoSerialFieldDomOnly(document, 'TEST-2441I041803');
        expect(document.getElementById('valdt-btn').disabled).toBe(true);
    });
});
