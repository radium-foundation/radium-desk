const copyTextToClipboard = async (value) => {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);

        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
};

export const initCopyableIdentifiers = (showToast, root = document) => {
    root.querySelectorAll('[data-copyable-identifier]').forEach((button) => {
        if (button.dataset.copyableBound === 'true') {
            return;
        }

        button.dataset.copyableBound = 'true';

        button.addEventListener('click', async () => {
            const value = button.dataset.copyValue?.trim() ?? '';

            if (value === '') {
                return;
            }

            try {
                await copyTextToClipboard(value);
                showToast?.(button.dataset.copyToast ?? 'Copied');
            } catch {
                showToast?.('Unable to copy value.', 'danger');
            }
        });
    });
};
