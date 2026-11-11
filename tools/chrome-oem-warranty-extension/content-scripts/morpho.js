const DRAFT_TYPE = 'RDESK_OEM_WARRANTY_DRAFT';

const readPrimaryTable = () => {
    const rows = [];
    const table = document.querySelector('#table tbody');
    if (!table) {
        return rows;
    }

    table.querySelectorAll('tr').forEach((tr, index) => {
        if (index === 0) {
            return;
        }

        const cells = tr.querySelectorAll('td');
        if (cells.length !== 5) {
            return;
        }

        const joined = Array.from(cells).map((cell) => cell.innerText.trim()).join(' ');
        if (joined.toLowerCase().includes('no record found')) {
            rows.push({ no_record: true });
            return;
        }

        rows.push({
            order_number: cells[0]?.innerText?.trim() ?? '',
            serial_number: cells[1]?.innerText?.trim() ?? '',
            whitelisting_date: cells[2]?.innerText?.trim() ?? '',
            expiry_date: cells[3]?.innerText?.trim() ?? '',
            validity_years: cells[4]?.innerText?.trim() ?? '',
        });
    });

    return rows;
};

const inferOutcome = (rows) => {
    if (!rows.length) {
        return 'inconclusive';
    }

    if (rows.some((row) => row.no_record)) {
        return 'not_found';
    }

    return 'found';
};

const setup = async () => {
    const { activeLookup } = await chrome.storage.session.get('activeLookup');
    if (!activeLookup || activeLookup.provider !== 'morpho') {
        return;
    }

    const serialInput = document.querySelector('#device-serial-number1');
    if (serialInput) {
        serialInput.value = activeLookup.serial_number;
        serialInput.dispatchEvent(new Event('input', { bubbles: true }));
        serialInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const observer = new MutationObserver(() => {
        const table = document.querySelector('#table');
        if (!table || table.classList.contains('hidden')) {
            return;
        }

        const rows = readPrimaryTable();
        if (!rows.length) {
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

    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
};

setup();
