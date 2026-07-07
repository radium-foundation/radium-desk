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
    if (root.dataset.copyableDelegationBound === 'true') {
        return;
    }

    root.dataset.copyableDelegationBound = 'true';

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copyable-identifier]');

        if (!button || !root.contains(button)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

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
};
