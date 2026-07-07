const ensureRadiumDeskNamespace = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    window.RadiumDesk = window.RadiumDesk || {};

    return window.RadiumDesk;
};

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
    try {
        const radiumDesk = ensureRadiumDeskNamespace();

        if (!radiumDesk || typeof root?.addEventListener !== 'function') {
            return;
        }

        if (radiumDesk.copyableDelegationBound === true) {
            return;
        }

        radiumDesk.copyableDelegationBound = true;

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
    } catch {
        // Copy UX is non-critical; never block app bootstrap.
    }
};
