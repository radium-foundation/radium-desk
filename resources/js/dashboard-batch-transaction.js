const copyTextToClipboard = async (text) => {
    await navigator.clipboard.writeText(text);
};

export const initBatchTransactionForm = (root, showToast) => {
    const form = root?.querySelector('[data-workspace-action-form="batch-transaction"]');

    if (!form) {
        return;
    }

    const copyAllButton = form.querySelector('[data-copy-all-serials]');
    const serialItems = form.querySelectorAll('[data-batch-serial-copy]');

    copyAllButton?.addEventListener('click', async () => {
        const serials = [...serialItems]
            .map((item) => item.dataset.serial?.trim() ?? '')
            .filter((serial) => serial !== '');

        if (serials.length === 0) {
            return;
        }

        await copyTextToClipboard(serials.join('\n'));

        const label = serials.length === 1 ? 'serial number' : 'serial numbers';
        showToast?.(`Copied ${serials.length} ${label}`);
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
