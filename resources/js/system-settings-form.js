export const initSystemSettingsForm = () => {
    const form = document.querySelector('[data-system-settings-form]');
    const saveBar = document.querySelector('[data-system-settings-save-bar]');
    const discardButton = document.querySelector('[data-system-settings-discard]');
    const discardHeader = document.querySelector('[data-system-settings-discard-header]');
    const saveHeader = document.querySelector('[data-system-settings-save-header]');

    if (! form || ! saveBar) {
        return;
    }

    let initialSnapshot = '';
    let isDirty = false;

    const serializeForm = () => new FormData(form).toString();

    const showSaveBar = () => {
        saveBar.hidden = false;
        saveBar.setAttribute('aria-hidden', 'false');
        saveHeader?.removeAttribute('hidden');
        discardHeader?.removeAttribute('hidden');
        isDirty = true;
    };

    const hideSaveBar = () => {
        saveBar.hidden = true;
        saveBar.setAttribute('aria-hidden', 'true');
        saveHeader?.setAttribute('hidden', '');
        discardHeader?.setAttribute('hidden', '');
        isDirty = false;
    };

    const syncSaveBar = () => {
        if (serializeForm() !== initialSnapshot) {
            showSaveBar();
        } else {
            hideSaveBar();
        }
    };

    discardButton?.addEventListener('click', () => {
        window.location.reload();
    });

    discardHeader?.addEventListener('click', () => {
        window.location.reload();
    });

    initialSnapshot = serializeForm();

    form.addEventListener('input', syncSaveBar);
    form.addEventListener('change', syncSaveBar);

    form.addEventListener('submit', () => {
        hideSaveBar();
    });

    window.addEventListener('beforeunload', (event) => {
        if (isDirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
};
