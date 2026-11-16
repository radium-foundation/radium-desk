import { describe, expect, it } from 'vitest';
import { isMantraResultVisible, readMantraResultTable } from '../src/lib/mantra-result-parse.js';

describe('mantra capture readiness', () => {
    it('does not treat visible divResult alone as capturable table data', () => {
        document.body.innerHTML = '<div id="divResult" style="display:block"></div>';
        expect(isMantraResultVisible(document)).toBe(true);
        expect(readMantraResultTable(document)).toEqual([]);
    });

    it('requires populated warranty rows for extraction', () => {
        document.body.innerHTML = `
            <table id="TblRDWarrantyCheck"><tbody>
                <tr><td>SYN1</td><td>01/01/2027</td><td>01/06/2026</td><td>MFS 110</td></tr>
            </tbody></table>`;
        const rows = readMantraResultTable(document);
        expect(rows).toHaveLength(1);
        expect(rows[0].serial_number).toBe('SYN1');
    });
});