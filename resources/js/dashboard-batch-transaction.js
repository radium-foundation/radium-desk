const copyTextToClipboard = async (text) => {
    await navigator.clipboard.writeText(text);
};

export const collectBatchTransactionSerials = (form) => {
    const serialItems = form.querySelectorAll('[data-batch-serial-copy]');

    return [...serialItems]
        .map((item) => item.dataset.serial?.trim() ?? '')
        .filter((serial) => serial !== '');
};

const collectIncidentIds = (form) => {
    return [...form.querySelectorAll('input[name="incident_ids[]"]')]
        .map((input) => parseInt(input.value, 10))
        .filter((id) => Number.isInteger(id) && id > 0);
};

const triggerBlobDownload = (blob, filename) => {
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(objectUrl);
};

const parseDownloadFilename = (contentDisposition) => {
    if (!contentDisposition) {
        return null;
    }

    const utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch?.[1]) {
        return decodeURIComponent(utfMatch[1]);
    }

    const asciiMatch = contentDisposition.match(/filename="([^"]+)"/i);
    if (asciiMatch?.[1]) {
        return asciiMatch[1];
    }

    return null;
};

export const initBatchTransactionForm = (root, showToast) => {
    const form = root?.querySelector('[data-workspace-action-form="batch-transaction"]');

    if (!form) {
        return;
    }

    const copyAllButton = form.querySelector('[data-copy-all-serials]');
    const exportExcelButton = form.querySelector('[data-export-serials-excel]');
    const serialItems = form.querySelectorAll('[data-batch-serial-copy]');

    copyAllButton?.addEventListener('click', async () => {
        const serials = collectBatchTransactionSerials(form);

        if (serials.length === 0) {
            showToast?.('No serial numbers to copy', 'warning');
            return;
        }

        await copyTextToClipboard(serials.join('\n'));

        const label = serials.length === 1 ? 'serial number' : 'serial numbers';
        showToast?.(`Copied ${serials.length} ${label}`);
    });

    exportExcelButton?.addEventListener('click', async () => {
        const serials = collectBatchTransactionSerials(form);
        if (serials.length === 0) {
            showToast?.('No serial numbers to export', 'warning');
            return;
        }

        const exportUrl = exportExcelButton.dataset.batchSerialExportUrl?.trim() ?? '';
        if (exportUrl === '') {
            showToast?.('Export is unavailable', 'danger');
            return;
        }

        const incidentIds = collectIncidentIds(form);
        if (incidentIds.length === 0) {
            showToast?.('No orders selected for export', 'warning');
            return;
        }

        const params = new URLSearchParams();
        incidentIds.forEach((id) => params.append('incident_ids[]', String(id)));
        const workspaceContext = form.querySelector('input[name="workspace_context"]')?.value;
        if (workspaceContext) {
            params.set('context', workspaceContext);
        }

        exportExcelButton.disabled = true;

        try {
            const response = await fetch(`${exportUrl}?${params.toString()}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                let message = 'Could not export serial numbers';
                const contentType = response.headers.get('content-type') ?? '';
                if (contentType.includes('application/json')) {
                    const payload = await response.json();
                    if (payload?.message) {
                        message = payload.message;
                    }
                }
                showToast?.(message, 'danger');
                return;
            }

            const blob = await response.blob();
            const filename = parseDownloadFilename(response.headers.get('content-disposition'))
                ?? 'RDWarrantyCheck-SerialNumbers.xlsx';
            triggerBlobDownload(blob, filename);
            showToast?.(`Downloaded ${serials.length} serial number${serials.length === 1 ? '' : 's'} as Excel`);
        } catch {
            showToast?.('Could not export serial numbers', 'danger');
        } finally {
            exportExcelButton.disabled = false;
        }
    });

    serialItems.forEach((item) => {
        item.addEventListener('click', async () => {
            const serial = item.dataset.serial?.trim() ?? '';

            if (serial === '') {
                return;
            }

            await copyTextToClipboard(serial);
            showToast?.('Serial copied');
        });
    });
};