/**
 * Parse visible Mantra warranty check table (#TblRDWarrantyCheck). Does not read hidden fields or credentials.
 *
 * @param {Document} doc
 * @returns {Array<{ serial_number: string, warranty_expiry_date: string, rd_expiry_date: string, model: string }>}
 */
export function readMantraResultTable(doc) {
    const rows = [];
    const table = doc.querySelector('#TblRDWarrantyCheck tbody');
    if (!table) {
        return rows;
    }

    table.querySelectorAll('tr').forEach((tr) => {
        const cells = tr.querySelectorAll('td');
        if (cells.length < 4) {
            return;
        }

        rows.push({
            serial_number: cells[0]?.innerText?.trim() ?? '',
            warranty_expiry_date: cells[1]?.innerText?.trim() ?? '',
            rd_expiry_date: cells[2]?.innerText?.trim() ?? '',
            model: cells[3]?.innerText?.trim() ?? '',
        });
    });

    return rows;
}

/**
 * @param {Array<{ warranty_expiry_date?: string, rd_expiry_date?: string, model?: string }>} rows
 * @returns {'found' | 'not_found' | 'inconclusive'}
 */
export function inferMantraOutcome(rows) {
    if (!rows.length) {
        return 'inconclusive';
    }

    const primary = rows[0];
    const hasAnyValue = [primary.warranty_expiry_date, primary.rd_expiry_date, primary.model]
        .some((value) => value && value !== '');

    return hasAnyValue ? 'found' : 'not_found';
}

/**
 * @param {Document} doc
 * @returns {boolean}
 */
export function isMantraResultVisible(doc) {
    const rows = readMantraResultTable(doc);
    if (rows.length > 0) {
        return true;
    }

    const resultDiv = doc.querySelector('#divResult');
    return Boolean(resultDiv && resultDiv.style.display !== 'none');
}
