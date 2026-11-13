import { describe, expect, it } from 'vitest';
import {
    isExpectedOemDestinationUrl,
    isOemVendor,
    isValidHandoffSerial,
} from '../src/lib/handoff-payload.js';
import { readSerialFromDashboardCell } from '../src/lib/desk-serial-reader.js';
describe('handoff payload validation', () => {
    it('accepts alphanumeric desk serials up to 32 chars', () => {
        expect(isValidHandoffSerial('SYN-001')).toBe(false);
        expect(isValidHandoffSerial('TESTMFS110001')).toBe(true);
        expect(isValidHandoffSerial('')).toBe(false);
    });

    it('validates oem vendor values', () => {
        expect(isOemVendor('mantra')).toBe(true);
        expect(isOemVendor('morpho')).toBe(true);
        expect(isOemVendor('fm220')).toBe(false);
    });

    it('validates OEM destination URLs', () => {
        expect(
            isExpectedOemDestinationUrl(
                'https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx',
                'mantra',
            ),
        ).toBe(true);
        expect(
            isExpectedOemDestinationUrl('https://example.com/', 'mantra'),
        ).toBe(false);
        expect(
            isExpectedOemDestinationUrl(
                'https://rdserviceonline.com/pages/172iserialvalidatenumber',
                'morpho',
            ),
        ).toBe(true);
    });
});

describe('desk serial reader', () => {
    it('reads serial from row DOM without data attributes', () => {
        const document = {
            querySelector: () => null,
        };
        const cell = {
            querySelector: (selector) => {
                if (selector === '.dashboard-u-serial-value') {
                    return { textContent: 'SYNTH0000001' };
                }

                return null;
            },
        };
        const trigger = {
            closest: () => cell,
        };

        expect(readSerialFromDashboardCell(trigger)).toBe('SYNTH0000001');
        void document;
    });
});

