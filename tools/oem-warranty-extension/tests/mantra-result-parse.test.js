import { describe, expect, it } from 'vitest';
import {
    inferMantraOutcome,
    isMantraResultVisible,
    readMantraResultTable,
} from '../src/lib/mantra-result-parse.js';

const fixtureTable = (serial, warranty, rd, model) => `
    <div id="divResult" style="display:block"></div>
    <table id="TblRDWarrantyCheck">
        <tbody>
            <tr>
                <td>${serial}</td>
                <td>${warranty}</td>
                <td>${rd}</td>
                <td>${model}</td>
            </tr>
        </tbody>
    </table>
`;

describe('readMantraResultTable', () => {
    it('extracts supported columns from synthetic OEM table', () => {
        document.body.innerHTML = fixtureTable('SYN-6419897', '2027-01-15', '2026-06-01', 'MFS 110');

        const rows = readMantraResultTable(document);
        expect(rows).toEqual([{
            serial_number: 'SYN-6419897',
            warranty_expiry_date: '2027-01-15',
            rd_expiry_date: '2026-06-01',
            model: 'MFS 110',
        }]);
    });

    it('returns empty when table is missing', () => {
        document.body.innerHTML = '<div id="divResult"></div>';
        expect(readMantraResultTable(document)).toEqual([]);
    });
});

describe('inferMantraOutcome', () => {
    it('marks row with dates as found', () => {
        document.body.innerHTML = fixtureTable('SYN-1', '2027-01-15', '2026-06-01', 'MIS 100');
        expect(inferMantraOutcome(readMantraResultTable(document))).toBe('found');
    });

    it('treats empty row values as not_found', () => {
        document.body.innerHTML = fixtureTable('SYN-1', '', '', '');
        expect(inferMantraOutcome(readMantraResultTable(document))).toBe('not_found');
    });

    it('is inconclusive without rows', () => {
        expect(inferMantraOutcome([])).toBe('inconclusive');
    });
});

describe('isMantraResultVisible', () => {
    it('requires table rows or visible divResult', () => {
        document.body.innerHTML = '<div id="divResult" style="display:none"></div>';
        expect(isMantraResultVisible(document)).toBe(false);

        document.body.innerHTML = fixtureTable('SYN-1', '2027-01-01', '2026-01-01', 'MFS 110');
        expect(isMantraResultVisible(document)).toBe(true);
    });
});
