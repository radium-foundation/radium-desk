import { isMentionDropdownOpen, isSubmitModifier, isTypingTarget } from './guards';

const getActiveModalForm = () => {
    const modals = Array.from(document.querySelectorAll('.modal.show'));

    if (modals.length === 0) {
        return null;
    }

    const topModal = modals[modals.length - 1];
    const form = topModal.querySelector('form');

    return form instanceof HTMLFormElement ? form : null;
};

/** @type {AbortController | null} */
let activeKeyboardController = null;

export const initKeyboardShortcuts = ({
    closeOpenInlineEditor = null,
    isWorkspaceSubmitBusy = () => false,
} = {}) => {
    activeKeyboardController?.abort();

    const helpModalElement = document.getElementById('keyboardShortcutsModal');
    const helpModal = helpModalElement && window.bootstrap?.Modal
        ? window.bootstrap.Modal.getOrCreateInstance(helpModalElement)
        : null;

    const handleKeydown = (event) => {
        if (event.defaultPrevented) {
            return;
        }

        if (event.key === 'Escape') {
            if (closeOpenInlineEditor?.()) {
                event.preventDefault();
            }

            return;
        }

        if (isSubmitModifier(event)) {
            if (isMentionDropdownOpen()) {
                return;
            }

            const form = getActiveModalForm();

            if (form && !isWorkspaceSubmitBusy()) {
                event.preventDefault();
                form.requestSubmit();
            }

            return;
        }

        if (isMentionDropdownOpen()) {
            return;
        }

        if (isTypingTarget(event.target)) {
            return;
        }

        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (event.key === '?' && helpModal) {
            event.preventDefault();
            helpModal.show();

            return;
        }

        if (event.key === '/' && document.getElementById('dashboard-page')) {
            const filterInput = document.querySelector('[data-dashboard-quick-filter-input]');

            if (filterInput instanceof HTMLInputElement) {
                event.preventDefault();
                filterInput.focus();
                filterInput.select();
            }
        }
    };

    activeKeyboardController = new AbortController();
    document.addEventListener('keydown', handleKeydown, { signal: activeKeyboardController.signal });

    return () => {
        activeKeyboardController?.abort();
        activeKeyboardController = null;
    };
};

export const resetKeyboardShortcuts = () => {
    activeKeyboardController?.abort();
    activeKeyboardController = null;
};

export { isTypingTarget } from './guards';
