export const isTypingTarget = (target) => {
    if (!target || !(target instanceof HTMLElement)) {
        return false;
    }

    const tagName = target.tagName;

    return tagName === 'INPUT'
        || tagName === 'TEXTAREA'
        || tagName === 'SELECT'
        || target.isContentEditable;
};

export const isGlobalSearchInput = (target) => (
    target instanceof HTMLInputElement && target.id === 'global-search-input'
);

export const shouldBlockShortcutForTyping = (target) => {
    if (!isTypingTarget(target)) {
        return false;
    }

    return !isGlobalSearchInput(target);
};

export const isHelpShortcut = (event) => (
    event.key === '?' || (event.code === 'Slash' && event.shiftKey)
);

export const isQuickFilterShortcut = (event) => (
    (event.key === '/' || event.code === 'Slash') && !event.shiftKey
);

export const isMentionDropdownOpen = () => {
    const dropdown = document.querySelector('.mention-suggestions.show');

    return dropdown instanceof HTMLElement && dropdown.style.display !== 'none';
};

export const isSubmitModifier = (event) => (
    event.key === 'Enter' && (event.ctrlKey || event.metaKey)
);
