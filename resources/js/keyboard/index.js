import {
    isHelpShortcut,
    isMentionDropdownOpen,
    isQuickFilterShortcut,
    isSubmitModifier,
    shouldBlockShortcutForTyping,
} from './guards';

const isKeyboardDebugEnabled = () => {
    try {
        return localStorage.getItem('radium.keyboardDebug') === 'true';
    } catch (error) {
        return false;
    }
};

const logKeyboardDebug = (event, details = {}) => {
    if (!isKeyboardDebugEnabled()) {
        return;
    }

    console.log('[keyboard-debug]', {
        key: event.key,
        code: event.code,
        shiftKey: event.shiftKey,
        ctrlKey: event.ctrlKey,
        metaKey: event.metaKey,
        target: event.target instanceof HTMLElement ? event.target.id || event.target.tagName : event.target,
        activeElement: document.activeElement instanceof HTMLElement
            ? document.activeElement.id || document.activeElement.tagName
            : document.activeElement,
        ...details,
    });
};

const showHelpModal = () => {
    const helpModalElement = document.getElementById('keyboardShortcutsModal');

    if (!helpModalElement || !window.bootstrap?.Modal) {
        return false;
    }

    window.bootstrap.Modal.getOrCreateInstance(helpModalElement).show();

    return true;
};

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

    const handleKeydown = (event) => {
        if (event.defaultPrevented) {
            return;
        }

        if (isKeyboardDebugEnabled() && (isHelpShortcut(event) || isQuickFilterShortcut(event))) {
            logKeyboardDebug(event, {
                mentionOpen: isMentionDropdownOpen(),
                typingBlocked: shouldBlockShortcutForTyping(event.target),
                dashboardPage: Boolean(document.getElementById('dashboard-page')),
                quickFilter: Boolean(document.querySelector('[data-dashboard-quick-filter-input]')),
                helpModal: Boolean(document.getElementById('keyboardShortcutsModal')),
            });
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

        if (shouldBlockShortcutForTyping(event.target)) {
            return;
        }

        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (isHelpShortcut(event)) {
            if (showHelpModal()) {
                event.preventDefault();
            }

            return;
        }

        if (isQuickFilterShortcut(event) && document.getElementById('dashboard-page')) {
            const filterInput = document.getElementById('dashboard-page')
                ?.querySelector('[data-dashboard-quick-filter-input]');

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
