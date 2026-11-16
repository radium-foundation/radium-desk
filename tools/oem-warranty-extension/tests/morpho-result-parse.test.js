import { describe, expect, it } from 'vitest';
import {
    inferMorphoOutcome,
    isMorphoResultVisible,
    readMorphoResultFields,
} from '../src/lib/morpho-result-parse.js';

describe('morpho result parse', () => {
    it('reads validation fields from representative DOM', () => {
        document.body.innerHTML = `
            <div id="morpho-validation-panel">
                <table id="tblDeviceResult"><tbody><tr><td>x</td></tr></tbody></table>
                <span id="lblSerial">2441I041803</span>
                <span id="lblDeviceValidity">Valid</span>
                <span id="lblWhitelistStatus">Whitelisted</span>
                <span id="lblExpiryDate">15/01/2027</span>
            </div>
        `;

        const fields = readMorphoResultFields(document);
        expect(fields.serial_number).toBe('2441I041803');
        expect(fields.device_validity).toBe('Valid');
        expect(isMorphoResultVisible(document)).toBe(true);
        expect(inferMorphoOutcome(fields)).toBe('found');
    });
});