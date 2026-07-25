export const initSystemSettingsAudit = () => {
    const drawer = document.querySelector('[data-system-settings-audit-drawer]');
    const openButton = document.querySelector('[data-system-settings-audit-open]');
    const closeElements = document.querySelectorAll('[data-system-settings-audit-close]');

    if (! drawer || ! openButton) {
        return;
    }

    const open = () => {
        drawer.hidden = false;
        drawer.setAttribute('aria-hidden', 'false');
        openButton.setAttribute('aria-expanded', 'true');
        document.body.classList.add('system-settings-audit-open');
        drawer.querySelector('[data-system-settings-audit-close]')?.focus();
    };

    const close = () => {
        drawer.hidden = true;
        drawer.setAttribute('aria-hidden', 'true');
        openButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('system-settings-audit-open');
        openButton.focus();
    };

    openButton.addEventListener('click', open);
    closeElements.forEach((el) => el.addEventListener('click', close));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && ! drawer.hidden) {
            close();
        }
    });
};
