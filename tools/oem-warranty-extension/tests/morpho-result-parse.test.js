import { describe, expect, it } from 'vitest';
import {
    classifyMorphoWhitelistQualification,
    inferMorphoOutcome,
    isMorphoResultVisible,
    morphoFieldsForCapture,
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
        expect(morphoFieldsForCapture(fields).panel_visible).toBeUndefined();
    });

    it('ignores prefill input when result label serial is present', () => {
        document.body.innerHTML = `
            <input id="device-serial-number1" value="PREFILL999" />
            <div id="morpho-validation-panel">
                <span id="lblSerial">2441I041803</span>
                <span id="lblDeviceValidity">Valid</span>
            </div>
        `;
        const fields = readMorphoResultFields(document);
        expect(fields.serial_number).toBe('2441I041803');
    });

    it('does not treat prefill-only DOM as visible OEM result', () => {
        document.body.innerHTML = `
            <input id="device-serial-number1" value="PREFILL999" />
        `;
        expect(isMorphoResultVisible(document)).toBe(false);
    });

    it('classifies invalid device text as not_found', () => {
        const fields = {
            serial_number: '2441I041803',
            device_validity: 'Not Valid',
            whitelist_status: '',
            whitelist_date: '',
            expiry_date: '',
            validity_years: '',
            device_type: '',
            panel_visible: true,
        };
        expect(inferMorphoOutcome(fields)).toBe('not_found');
        expect(classifyMorphoWhitelistQualification(fields)).toBe('invalid_device');
    });

    it('classifies already whitelisted labels', () => {
        document.body.innerHTML = `
            <div id="morpho-validation-panel">
                <span id="lblDeviceValidity">Valid</span>
                <span id="lblWhitelistStatus">Device already whitelisted</span>
            </div>
        `;

        const fields = readMorphoResultFields(document);
        expect(classifyMorphoWhitelistQualification(fields)).toBe('already_whitelisted');
        expect(inferMorphoOutcome(fields)).toBe('found');
    });

    it('classifies valid but not whitelisted', () => {
        const fields = {
            serial_number: '2441I041803',
            device_validity: 'Valid',
            whitelist_status: 'Valid but not whitelisted',
            whitelist_date: '',
            expiry_date: '',
            validity_years: '',
            device_type: '',
            panel_visible: true,
        };

        expect(classifyMorphoWhitelistQualification(fields)).toBe('valid_not_whitelisted');
    });
});