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

export const isMentionDropdownOpen = () => {
    const dropdown = document.querySelector('.mention-suggestions.show');

    return dropdown instanceof HTMLElement;
};

export const isSubmitModifier = (event) => (
    event.key === 'Enter' && (event.ctrlKey || event.metaKey)
);
