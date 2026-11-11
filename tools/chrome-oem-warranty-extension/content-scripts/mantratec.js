const DRAFT_TYPE = 'RDESK_OEM_WARRANTY_DRAFT';

const readTable = () => {
    const rows = [];
    const table = document.querySelector('#TblRDWarrantyCheck tbody');
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
};

const inferOutcome = (rows) => {
    if (!rows.length) {
        return 'inconclusive';
    }

    const primary = rows[0];
    const hasAnyValue = [primary.warranty_expiry_date, primary.rd_expiry_date, primary.model].some((value) => value && value !== '');

    return hasAnyValue ? 'found' : 'not_found';
};

const setup = async () => {
    const { activeLookup } = await chrome.storage.session.get('activeLookup');
    if (!activeLookup || activeLookup.provider !== 'mantratec') {
        return;
    }

    const serialInput = document.querySelector('#txtSerialNo');
    if (serialInput) {
        serialInput.value = activeLookup.serial_number;
        serialInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (activeLookup.extension_dom_enabled !== true) {
        return;
    }

    const observer = new MutationObserver(() => {
        const rows = readTable();
        const resultDiv = document.querySelector('#divResult');
        if (!rows.length || (resultDiv && resultDiv.style.display === 'none')) {
            return;
        }

        observer.disconnect();
        chrome.runtime.sendMessage({
            type: DRAFT_TYPE,
            handoff_token: activeLookup.handoff_token,
            outcome: inferOutcome(rows),
            rows,
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });
};

setup();
