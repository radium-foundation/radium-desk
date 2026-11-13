(function () {
    const OEM = 'mantra';

    function readTable() {
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
    }

    function inferOutcome(rows) {
        if (!rows.length) {
            return 'inconclusive';
        }

        const primary = rows[0];
        const hasAnyValue = [primary.warranty_expiry_date, primary.rd_expiry_date, primary.model]
            .some((value) => value && value !== '');

        return hasAnyValue ? 'found' : 'not_found';
    }

    function showFailure() {
        const bannerId = 'radium-oem-handoff-banner';
        if (document.getElementById(bannerId)) {
            return;
        }
        const el = document.createElement('p');
        el.id = bannerId;
        el.setAttribute('role', 'status');
        el.textContent = 'Serial could not be filled automatically. Enter it manually on this page.';
        el.style.cssText = 'margin:8px 0;padding:8px;background:#fff3cd;border:1px solid #ffeeba;font-size:13px;';
        document.getElementById('txtSerialNo')?.parentElement?.prepend(el);
    }

    function populate(serial) {
        const field = document.getElementById('txtSerialNo');
        if (!field || !(field instanceof HTMLInputElement)) {
            return false;
        }
        const normalized = String(serial).trim().toUpperCase();
        field.value = normalized;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    chrome.runtime.sendMessage({ type: 'OEM_HANDOFF_CLAIM', oem: OEM }, (response) => {
        if (chrome.runtime.lastError || !response?.ok) {
            showFailure();
            return;
        }

        const success = populate(response.serial);
        if (!success) {
            showFailure();
            return;
        }

        if (response.mantra_capture_enabled !== true) {
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
                type: 'OEM_HANDOFF_MANTRA_DRAFT',
                handoff_token: response.handoff_token,
                incident_id: response.incident_id,
                session_public_id: response.session_public_id,
                outcome: inferOutcome(rows),
                rows,
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
